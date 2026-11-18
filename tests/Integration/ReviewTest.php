<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Tests\Integration;

use IndianOpinion\CommentDraftReplies\Admin\CommentsScreen;
use IndianOpinion\CommentDraftReplies\Admin\DraftsScreen;
use IndianOpinion\CommentDraftReplies\Clock;
use IndianOpinion\CommentDraftReplies\Housekeeping;
use IndianOpinion\CommentDraftReplies\Review\Publisher;
use IndianOpinion\CommentDraftReplies\Tests\Support\FakeModel;
use IndianOpinion\CommentDraftReplies\Tests\Support\TestCase;

/** Section 4.4: cases 37 to 53, and 54's human acts. */
final class ReviewTest extends TestCase
{
    private function publish(int $draftId, array $params = []): \WP_REST_Response|\WP_Error
    {
        return $this->rest('POST', '/comment-draft-replies/v1/drafts/' . $draftId . '/publish', $params, $this->nonceHeader());
    }

    public function testT37ListScreenFiltersAndBubble(): void
    {
        $ready = $this->readyDraft();
        $this->post(30);
        $other = $this->postComment(['comment_post_ID' => 30, 'comment_author_email' => 'q@example.net', 'comment_content' => 'Queued one on another post?']);
        wp_set_current_user(self::EDITOR);
        $screen = new DraftsScreen($this->plugin->drafts, $this->plugin->publisher);
        self::assertSame(1, $screen->readyCount());

        ob_start();
        $screen->render(['status' => 'ready']);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('data-draft="' . $ready->id() . '"', $html);
        self::assertStringNotContainsString('data-draft="' . $this->draftFor($other)->id() . '"', $html);

        ob_start();
        $screen->render(['status' => 'all', 'post' => '30']);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('data-draft="' . $this->draftFor($other)->id() . '"', $html);
        self::assertStringNotContainsString('data-draft="' . $ready->id() . '"', $html);

        $screen->menu();
        self::assertStringContainsString('pending-count">1<', (string) \WPStub::$menus[0][2]);
    }

    public function testT38InlinePanelOnlyOnTheCommentsScreen(): void
    {
        $d = $this->readyDraft([], 'Secret draft text for the panel.');
        $c = get_comment($d->commentId());
        // Front end, feed, REST: the filter is not attached.
        self::assertSame('body', apply_filters('comment_text', 'body', $c));

        \WPStub::$isAdmin = true;
        wp_set_current_user(self::EDITOR);
        $screen = new CommentsScreen($this->plugin->drafts);
        $screen->register();
        do_action('load-edit-comments.php');
        $out = (string) apply_filters('comment_text', 'body', $c);
        self::assertStringContainsString('Secret draft text for the panel.', $out);
        self::assertStringContainsString('Approve comment and publish reply', $out);

        $evil = $this->readyDraft(['comment_author_email' => 'e@example.net', 'comment_content' => 'Another question here?'], 'Look <script>alert(1)</script> here');
        $html = (string) apply_filters('comment_text', 'x', get_comment($evil->commentId()));
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testT38DraftsDoNotReachCommentQueries(): void
    {
        $d = $this->readyDraft([], 'Unique draft words xyzzy.');
        global $wpdb;
        $hits = \WPStub::internal(static fn () => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_content LIKE '%xyzzy%'"));
        self::assertSame('0', $hits);
        self::assertSame(1, $this->countComments());
        self::assertSame('ready', $d->status());
    }

    public function testT39ModelTextIsPublishedAsTextNeverAsMarkup(): void
    {
        // The model spelled a tag with entities; the validator decodes them
        // to plain text, so publishing must encode, even for an admin.
        $d = $this->readyDraft(['comment_approved' => '1'], 'Use &lt;script&gt;alert(1)&lt;/script&gt; never, and 2 &lt; 3.');
        self::assertSame('Use <script>alert(1)</script> never, and 2 < 3.', $d->reply());
        $this->loginBrowser(self::ADMIN);
        $ok = $this->publish($d->id());
        self::assertInstanceOf(\WP_REST_Response::class, $ok);
        $reply = get_comment($ok->get_data()['reply_comment_id']);
        self::assertStringNotContainsString('<script', $reply->comment_content);
        self::assertSame('Use &lt;script&gt;alert(1)&lt;/script&gt; never, and 2 &lt; 3.', $reply->comment_content);
        // The draft keeps the plain text it was written as.
        self::assertSame('Use <script>alert(1)</script> never, and 2 < 3.', $this->draftFor($d->commentId())->reply());
    }

    public function testT39ErasedDraftCannotPublishAnEmptyReply(): void
    {
        $d = $this->readyDraft(['comment_approved' => '1']);
        $this->plugin->drafts->update($d->id(), ['reply' => null, 'last_reason' => 'erased']);
        $this->loginBrowser(self::EDITOR);
        self::assertSame('cdreplies_invalid_text', $this->publish($d->id())->get_error_code());
        self::assertSame(1, $this->countComments());
        self::assertSame('ready', $this->draftFor($d->commentId())->status());
    }

    public function testT39ApproveAsEditorAndRefusals(): void
    {
        $d = $this->readyDraft(['comment_approved' => '1']);
        $this->loginBrowser(self::MODERATOR);
        self::assertSame(403, self::errorStatus($this->publish($d->id())), 'no edit_post');

        $this->loginBrowser(self::READER);
        self::assertSame(403, self::errorStatus($this->publish($d->id())), 'no moderate_comments');

        $this->loginBrowser(self::EDITOR);
        $bad = $this->rest('POST', '/comment-draft-replies/v1/drafts/' . $d->id() . '/publish', [], ['X-Cdreplies-Nonce' => 'nope']);
        self::assertSame(403, self::errorStatus($bad));
        self::assertSame(1, $this->countComments());

        $ok = $this->publish($d->id());
        self::assertInstanceOf(\WP_REST_Response::class, $ok);
        $replyId = $ok->get_data()['reply_comment_id'];
        $reply = get_comment($replyId);
        self::assertSame((string) $d->commentId(), $reply->comment_parent);
        self::assertSame('1', $reply->comment_approved);
        self::assertSame('comment', $reply->comment_type);
        self::assertSame((string) self::EDITOR, $reply->user_id);
        self::assertSame((string) $d->id(), get_comment_meta($replyId, '_cdreplies_draft', true));
        $final = $this->draftFor($d->commentId());
        self::assertSame('published', $final->status());
        self::assertSame(self::EDITOR, $final->decidedBy());
        self::assertSame($replyId, $final->replyCommentId());
        self::assertNull($this->draftFor($replyId), 'own reply is not drafted');
        self::assertContains('own_reply', $this->skipReasons($replyId));
    }

    public function testT40HeldParent(): void
    {
        $d = $this->readyDraft();
        $this->loginBrowser(self::EDITOR);
        $r = $this->publish($d->id());
        self::assertSame('cdreplies_parent_held', $r->get_error_code());

        \WPStub::$failApprove = true;
        $r = $this->publish($d->id(), ['approve_parent' => true]);
        self::assertSame('cdreplies_core_refused', $r->get_error_code());
        self::assertSame(1, $this->countComments());
        self::assertSame('ready', $this->draftFor($d->commentId())->status());

        \WPStub::$failApprove = false;
        $r = $this->publish($d->id(), ['approve_parent' => true]);
        self::assertInstanceOf(\WP_REST_Response::class, $r);
        self::assertSame('1', get_comment($d->commentId())->comment_approved);
        self::assertSame(2, $this->countComments());
    }

    public function testRuling5ApproveAndPublishCanBeTurnedOff(): void
    {
        $this->settings(['approve_and_publish' => false]);
        $d = $this->readyDraft();
        $this->loginBrowser(self::EDITOR);
        self::assertSame('cdreplies_parent_held', $this->publish($d->id(), ['approve_parent' => true])->get_error_code());
    }

    public function testT41EditedTextIsFilteredWarnsAndKeepsOriginal(): void
    {
        $d = $this->readyDraft(['comment_approved' => '1'], 'Model wrote this.');
        $this->loginBrowser(self::EDITOR);
        $r = $this->publish($d->id(), ['text' => 'See other.org/fares <script>x</script><b>bold</b>']);
        self::assertSame('cdreplies_confirm_needed', $r->get_error_code());
        self::assertSame(['link'], $r->get_error_data()['warnings']);

        $r = $this->publish($d->id(), ['text' => 'See other.org/fares <script>x</script><b>bold</b>', 'confirm' => ['link']]);
        self::assertInstanceOf(\WP_REST_Response::class, $r);
        $reply = get_comment($r->get_data()['reply_comment_id']);
        self::assertStringNotContainsString('<script>', $reply->comment_content);
        self::assertStringContainsString('<b>bold</b>', $reply->comment_content);
        $final = $this->draftFor($d->commentId());
        self::assertTrue($final->edited());
        self::assertSame('Model wrote this.', $final->originalReply());

        $d2 = $this->readyDraft(['comment_approved' => '1', 'comment_author_email' => 'z@example.net', 'comment_content' => 'Another fair question?']);
        self::assertSame('cdreplies_invalid_text', $this->publish($d2->id(), ['text' => '   '])->get_error_code());
        self::assertSame('cdreplies_invalid_text', $this->publish($d2->id(), ['text' => str_repeat('a', 5001)])->get_error_code());
    }

    public function testT42DiscardAndBulkDiscard(): void
    {
        $d = $this->readyDraft();
        $before = (array) get_comment($d->commentId());
        $this->loginBrowser(self::EDITOR);
        $r = $this->rest('POST', '/comment-draft-replies/v1/drafts/' . $d->id() . '/discard', ['reason' => 'tone'], $this->nonceHeader());
        self::assertInstanceOf(\WP_REST_Response::class, $r);
        $final = $this->draftFor($d->commentId());
        self::assertSame('discarded', $final->status());
        self::assertSame('tone', $final->lastReason());
        self::assertSame($before, (array) get_comment($d->commentId()));
        self::assertSame('cdreplies_invalid_text', $this->rest('POST', '/comment-draft-replies/v1/drafts/' . $d->id() . '/discard', ['reason' => 'bogus'], $this->nonceHeader())->get_error_code());

        $a = $this->readyDraft(['comment_author_email' => 'a@example.net', 'comment_content' => 'Bulk one question?']);
        $b = $this->readyDraft(['comment_author_email' => 'b@example.net', 'comment_content' => 'Bulk two question?']);
        \WPStub::$request = ['_wpnonce' => wp_create_nonce(DraftsScreen::BULK_ACTION)];
        $screen = new DraftsScreen($this->plugin->drafts, $this->plugin->publisher);
        self::assertSame(2, $screen->bulkDiscard(['ids' => [(string) $a->id(), (string) $b->id()], 'reason' => 'not_needed'], false));
        self::assertSame('discarded', $this->draftFor($a->commentId())->status());

        \WPStub::$request = ['_wpnonce' => 'bad'];
        $this->expectException(\WPDieException::class);
        $screen->bulkDiscard(['ids' => [1]], false);
    }

    public function testT43RegenerateNoteLimitAndPublishedRefusal(): void
    {
        $d = $this->readyDraft(['comment_approved' => '1']);
        $this->loginBrowser(self::EDITOR);
        $r = $this->rest('POST', '/comment-draft-replies/v1/drafts/' . $d->id() . '/regenerate', ['note' => 'shorter please'], $this->nonceHeader());
        self::assertInstanceOf(\WP_REST_Response::class, $r);
        self::assertSame(2, $r->get_data()['generation']);
        $this->plugin->runner->run();
        self::assertStringContainsString('Editor note for this draft: shorter please', end($this->model->requests)['user']);

        foreach ([3, 4] as $gen) {
            $r = $this->plugin->publisher->regenerate($d->id(), self::EDITOR, '');
            self::assertSame($gen, $r->generation());
            $this->plugin->runner->run();
        }
        $r = $this->plugin->publisher->regenerate($d->id(), self::EDITOR, '');
        self::assertSame('cdreplies_regen_limit', $r->get_error_code());

        $p = $this->readyDraft(['comment_approved' => '1', 'comment_author_email' => 'p@example.net', 'comment_content' => 'Publish me first?']);
        $this->publish($p->id());
        self::assertSame('cdreplies_wrong_status', $this->plugin->publisher->regenerate($p->id(), self::EDITOR, '')->get_error_code());
    }

    public function testRegenerationDoesNotCountTowardRateLimits(): void
    {
        $this->settings(['limit_thread' => [1, 86400]]);
        $d = $this->readyDraft(['comment_approved' => '1']);
        $this->plugin->publisher->regenerate($d->id(), self::EDITOR, '');
        $this->plugin->runner->run();
        self::assertSame(1, $this->plugin->drafts->count([]));
    }

    public function testT44ConcurrentPublishCreatesOneReply(): void
    {
        $d = $this->readyDraft(['comment_approved' => '1']);
        $this->loginBrowser(self::EDITOR);
        self::assertInstanceOf(\WP_REST_Response::class, $this->publish($d->id()));
        $this->loginBrowser(self::ADMIN);
        $second = $this->publish($d->id());
        self::assertSame('cdreplies_wrong_status', $second->get_error_code());
        self::assertStringContainsString('Already published by Edna Editor', $second->get_error_message());
        self::assertSame(2, $this->countComments());
    }

    public function testT45CommentEditedAfterDrafting(): void
    {
        $d = $this->readyDraft(['comment_approved' => '1']);
        wp_update_comment(['comment_ID' => $d->commentId(), 'comment_content' => 'Changed my question entirely?']);
        self::assertContains('comment_changed', $this->draftFor($d->commentId())->flags());
        $this->loginBrowser(self::EDITOR);
        self::assertSame('cdreplies_confirm_needed', $this->publish($d->id())->get_error_code());
        self::assertInstanceOf(\WP_REST_Response::class, $this->publish($d->id(), ['confirm' => ['comment_changed']]));

        $e = $this->readyDraft(['comment_approved' => '1', 'comment_author_email' => 'e@example.net', 'comment_content' => 'Edit then regenerate?']);
        wp_update_comment(['comment_ID' => $e->commentId(), 'comment_content' => 'Edited text, regenerate?']);
        $r = $this->plugin->publisher->regenerate($e->id(), self::EDITOR, '');
        self::assertNotContains('comment_changed', $r->flags());
    }

    public function testT46SpamUnspamAndPermanentDelete(): void
    {
        $d = $this->readyDraft();
        wp_set_comment_status($d->commentId(), 'spam');
        $s = $this->draftFor($d->commentId());
        self::assertSame('stale', $s->status());
        self::assertSame('comment_spam', $s->lastReason());
        self::assertSame('ready', $s->previousStatus());
        wp_set_comment_status($d->commentId(), 'hold');
        self::assertSame('ready', $this->draftFor($d->commentId())->status());

        wp_set_comment_status($d->commentId(), 'trash');
        self::assertSame('comment_trash', $this->draftFor($d->commentId())->lastReason());
        wp_set_comment_status($d->commentId(), 'approve');
        self::assertSame('ready', $this->draftFor($d->commentId())->status());

        wp_delete_comment($d->commentId(), true);
        $g = $this->plugin->drafts->find($d->id());
        self::assertSame('stale', $g->status());
        self::assertSame('comment_gone', $g->lastReason());
        self::assertNull($g->reply());
    }

    public function testT47PostUnpublishRepublishAndDelete(): void
    {
        $d = $this->readyDraft();
        $post = get_post(self::POST);
        $post->post_status = 'draft';
        do_action('transition_post_status', 'draft', 'publish', $post);
        self::assertSame('stale', $this->draftFor($d->commentId())->status());
        self::assertSame('post_state', $this->draftFor($d->commentId())->lastReason());
        $post->post_status = 'publish';
        do_action('transition_post_status', 'publish', 'draft', $post);
        self::assertSame('ready', $this->draftFor($d->commentId())->status());

        do_action('before_delete_post', self::POST);
        $g = $this->plugin->drafts->find($d->id());
        self::assertSame('stale', $g->status());
        self::assertNull($g->reply());
    }

    public function testT48ExpiredDraftCannotBePublished(): void
    {
        $d = $this->readyDraft(['comment_approved' => '1']);
        Clock::freeze(self::NOW + 15 * 86400);
        (new Housekeeping($this->plugin->drafts))->run();
        self::assertSame('expired', $this->draftFor($d->commentId())->status());
        $this->loginBrowser(self::EDITOR);
        self::assertSame('cdreplies_wrong_status', $this->publish($d->id())->get_error_code());
        self::assertSame(1, $this->countComments());
        // An expired draft can be regenerated.
        self::assertSame('queued', $this->plugin->publisher->regenerate($d->id(), self::EDITOR, '')->status());
    }

    public function testT48PublishChecksAgeEvenBeforeHousekeeping(): void
    {
        $d = $this->readyDraft(['comment_approved' => '1']);
        Clock::freeze(self::NOW + 15 * 86400);
        $this->loginBrowser(self::EDITOR);
        self::assertSame('cdreplies_wrong_status', $this->publish($d->id())->get_error_code());
        self::assertSame('expired', $this->draftFor($d->commentId())->status());
    }

    public function testT51CoreRefusalKeepsTheDraftReady(): void
    {
        $d = $this->readyDraft(['comment_approved' => '1'], 'Duplicate words.');
        // A reply with the same text already exists under the same parent.
        wp_insert_comment(['comment_post_ID' => self::POST, 'comment_parent' => $d->commentId(), 'comment_content' => 'Duplicate words.', 'user_id' => self::EDITOR]);
        $this->loginBrowser(self::EDITOR);
        $r = $this->publish($d->id());
        self::assertSame('cdreplies_core_refused', $r->get_error_code());
        self::assertSame('ready', $this->draftFor($d->commentId())->status());

        add_filter('pre_comment_approved', static fn () => new \WP_Error('comment_flood', 'Slow down.'));
        $d2 = $this->readyDraft(['comment_approved' => '1', 'comment_author_email' => 'f@example.net', 'comment_content' => 'Flood case question?'], 'Unique words.');
        self::assertSame('cdreplies_core_refused', $this->publish($d2->id())->get_error_code());
        self::assertSame('ready', $this->draftFor($d2->commentId())->status());
    }

    public function testT52Ruling2AtMaximumDepthTheReplyAttachesToTheParent(): void
    {
        update_option('thread_comments_depth', '3');
        $this->settings(['limit_thread' => [0, 86400], 'limit_author' => [0, 86400]]);
        $l1 = $this->insertComment(['comment_approved' => '1', 'comment_content' => 'Level one']);
        $l2 = $this->insertComment(['comment_approved' => '1', 'comment_parent' => $l1, 'comment_content' => 'Level two']);
        $d = $this->readyDraft(['comment_approved' => '1', 'comment_parent' => $l2, 'comment_content' => 'Level three question?']);
        $shallow = $this->readyDraft(['comment_approved' => '1', 'comment_parent' => $l1, 'comment_content' => 'Level two question?', 'comment_author_email' => 's@example.net']);
        $this->loginBrowser(self::EDITOR);
        $r = $this->publish($d->id());
        self::assertSame((string) $l2, get_comment($r->get_data()['reply_comment_id'])->comment_parent);
        $r2 = $this->publish($shallow->id());
        self::assertSame((string) $shallow->commentId(), get_comment($r2->get_data()['reply_comment_id'])->comment_parent);
    }

    public function testT53NamedReplyAuthor(): void
    {
        $this->settings(['reply_author' => self::SITE_ACCOUNT]);
        $d = $this->readyDraft(['comment_approved' => '1']);
        $this->loginBrowser(self::EDITOR);
        $r = $this->publish($d->id());
        self::assertSame((string) self::SITE_ACCOUNT, get_comment($r->get_data()['reply_comment_id'])->user_id);
        self::assertSame(self::EDITOR, $this->draftFor($d->commentId())->decidedBy());

        \WPStub::$users[self::MODERATOR]->caps[] = 'edit_post:' . self::POST;
        $d2 = $this->readyDraft(['comment_approved' => '1', 'comment_author_email' => 'm@example.net', 'comment_content' => 'Moderator case question?']);
        $this->loginBrowser(self::MODERATOR);
        self::assertSame(403, self::errorStatus($this->publish($d2->id())));
    }

    public function testT54SwitchOnStillAllowsPublishButNotRegenerate(): void
    {
        $d = $this->readyDraft(['comment_approved' => '1']);
        $this->settings(['kill' => true]);
        self::assertSame('cdreplies_disabled', $this->plugin->publisher->regenerate($d->id(), self::EDITOR, '')->get_error_code());
        $this->loginBrowser(self::EDITOR);
        self::assertInstanceOf(\WP_REST_Response::class, $this->publish($d->id()));
    }

    public function testRuling6NoteClearedAtPublish(): void
    {
        $d = $this->readyDraft(['comment_approved' => '1']);
        $this->plugin->publisher->regenerate($d->id(), self::EDITOR, 'warmer');
        $this->plugin->runner->run();
        self::assertSame('warmer', $this->draftFor($d->commentId())->editorNote());
        $this->loginBrowser(self::EDITOR);
        $this->publish($d->id());
        self::assertNull($this->draftFor($d->commentId())->editorNote());
    }

    public function testGetRoutesNeedReviewCapability(): void
    {
        $d = $this->readyDraft();
        wp_set_current_user(self::READER);
        self::assertSame(403, self::errorStatus($this->rest('GET', '/comment-draft-replies/v1/drafts')));
        wp_set_current_user(self::EDITOR);
        $list = $this->rest('GET', '/comment-draft-replies/v1/drafts', ['status' => 'ready']);
        self::assertCount(1, $list->get_data());
        $one = $this->rest('GET', '/comment-draft-replies/v1/drafts/' . $d->id());
        self::assertSame('Does the rise apply to season tickets as well?', $one->get_data()['comment']['text']);
        self::assertSame(404, self::errorStatus($this->rest('GET', '/comment-draft-replies/v1/drafts/99999')));
    }

    public function testPublisherHasNoUnreviewedPath(): void
    {
        $d = $this->readyDraft(['comment_approved' => '1']);
        $r = $this->plugin->publisher->publish($d->id(), 0, null, false, []);
        self::assertSame(403, self::errorStatus($r));
        self::assertSame(1, $this->countComments());
        self::assertSame('moderate_comments', Publisher::reviewCapability());
    }
}
