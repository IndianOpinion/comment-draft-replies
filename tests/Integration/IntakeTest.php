<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Tests\Integration;

use IndianOpinion\CommentDraftReplies\Clock;
use IndianOpinion\CommentDraftReplies\DailyCap;
use IndianOpinion\CommentDraftReplies\Draft;
use IndianOpinion\CommentDraftReplies\Generation\Runner;
use IndianOpinion\CommentDraftReplies\Tests\Support\TestCase;

/** Section 4.2: cases 1 to 21. */
final class IntakeTest extends TestCase
{
    public function testT1EligibleHeldCommentQueuesOneDraftAndWakesTheRunner(): void
    {
        $id = $this->postComment();
        $d = $this->draftFor($id);
        self::assertInstanceOf(Draft::class, $d);
        self::assertSame('queued', $d->status());
        self::assertSame(self::POST, $d->postId());
        self::assertSame(1, $d->generation());
        self::assertSame(self::NOW + 30, wp_next_scheduled(Runner::CRON_HOOK));
        self::assertSame(1, did_action('cdreplies_queued'));
    }

    public function testT2IntakeMakesNoHttpCallAndAtMostFourReadsAndTwoWrites(): void
    {
        global $wpdb;
        $id = $this->insertComment();
        $wpdb->log = [];
        do_action('comment_post', $id, '0', []);
        self::assertSame([], \WPStub::$http);
        self::assertSame([], \WPStub::$mail);
        $locks = array_filter($wpdb->log, static fn (string $q): bool => preg_match('/^\s*SELECT (GET|RELEASE)_LOCK/i', $q) === 1);
        self::assertCount(2, $locks);
        $reads = array_filter(array_diff($wpdb->log, $locks), static fn (string $q): bool => preg_match('/^\s*(SELECT|SHOW)/i', $q) === 1);
        $writes = array_filter($wpdb->log, static fn (string $q): bool => preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)/i', $q) === 1);
        self::assertLessThanOrEqual(4, count($reads), implode("\n", $reads));
        self::assertLessThanOrEqual(2, count($writes), implode("\n", $writes));
        self::assertNotNull($this->draftFor($id));
        self::assertSame(0, $this->model->calls());
    }

    public function testT3NonOrdinaryTypesSkipAndBothOrdinaryTypesAreEligible(): void
    {
        foreach (['pingback', 'trackback', 'note'] as $type) {
            $id = $this->postComment(['comment_type' => $type, 'comment_content' => 'Type ' . $type . ' about the fare rise in March.']);
            self::assertNull($this->draftFor($id));
            self::assertContains('type', $this->skipReasons($id));
        }
        $a = $this->postComment(['comment_type' => '', 'comment_content' => 'Old style empty type comment on fares.']);
        $b = $this->postComment(['comment_type' => 'comment', 'comment_content' => 'New style comment type on the fares.', 'comment_author_email' => 'other@example.net']);
        self::assertNotNull($this->draftFor($a));
        self::assertNotNull($this->draftFor($b));
    }

    public function testT4SpamAtArrivalIsSkipped(): void
    {
        $id = $this->postComment(['comment_approved' => 'spam']);
        self::assertNull($this->draftFor($id));
        self::assertContains('spam', $this->skipReasons($id));
    }

    public function testT5HeldAndApprovedAreBothDrafted(): void
    {
        $held = $this->postComment(['comment_approved' => '0']);
        $approved = $this->postComment(['comment_approved' => '1', 'comment_content' => 'When exactly in March does it start?', 'comment_author_email' => 'b@example.net']);
        self::assertNotNull($this->draftFor($held));
        self::assertNotNull($this->draftFor($approved));
    }

    public function testT6StaffDecidedByUserIdNotEmail(): void
    {
        $editor = $this->postComment(['user_id' => self::EDITOR, 'comment_author_email' => 'ednaeditor@example.org']);
        $author = $this->postComment(['user_id' => self::AUTHOR, 'comment_content' => 'Author note on the fares piece here.']);
        $impostor = $this->postComment(['user_id' => 0, 'comment_author_email' => 'ednaeditor@example.org', 'comment_content' => 'I typed the editor address, honest question?']);
        self::assertContains('staff', $this->skipReasons($editor));
        self::assertContains('staff', $this->skipReasons($author));
        self::assertNotNull($this->draftFor($impostor));
    }

    public function testT7ReplyAuthorAccountIsOwnReplyAndReadersReplyToItIsDrafted(): void
    {
        $this->settings(['reply_author' => self::SITE_ACCOUNT]);
        $site = $this->postComment(['user_id' => self::SITE_ACCOUNT, 'comment_approved' => '1', 'comment_content' => 'Thanks, the rise is three per cent.']);
        self::assertContains('own_reply', $this->skipReasons($site));
        $reader = $this->postComment(['comment_parent' => $site, 'comment_content' => 'Thanks, and for railcards too?']);
        self::assertNotNull($this->draftFor($reader));
        self::assertSame($site, $this->draftFor($reader)->threadRootId());
    }

    public function testT8PostStateDraftPasswordAndCustomType(): void
    {
        $this->post(11, ['post_status' => 'draft']);
        $this->post(12, ['post_password' => 'secret']);
        $this->post(13, ['post_type' => 'product']);
        foreach ([11, 12, 13] as $pid) {
            $id = $this->postComment(['comment_post_ID' => $pid, 'comment_author_email' => "p{$pid}@example.net"]);
            self::assertContains('post_state', $this->skipReasons($id), "post {$pid}");
        }
    }

    public function testT9ClosedCommentsSkipUnlessFiltered(): void
    {
        $this->post(14, ['comment_status' => 'closed']);
        $a = $this->postComment(['comment_post_ID' => 14]);
        self::assertContains('closed', $this->skipReasons($a));
        add_filter('cdreplies_allow_closed', '__return_true');
        $b = $this->postComment(['comment_post_ID' => 14, 'comment_author_email' => 'x@example.net', 'comment_content' => 'Another question on the closed post?']);
        self::assertNotNull($this->draftFor($b));
    }

    public function testT10LengthBoundsInCharacters(): void
    {
        $this->settings(['limit_post' => [0, 3600], 'limit_author' => [0, 86400], 'limit_thread' => [0, 86400]]);
        $cases = [
            [str_repeat('a', 14), false],
            [str_repeat('a', 15), true],
            [str_repeat('b ', 2500), true],
            [str_repeat('c', 5001), false],
            [str_repeat('क', 15), true],
            [str_repeat('क', 14), false],
        ];
        foreach ($cases as $i => [$text, $eligible]) {
            $id = $this->postComment(['comment_content' => $text, 'comment_author_email' => "len{$i}@example.net"]);
            if ($eligible) {
                self::assertNotNull($this->draftFor($id), "case {$i}");
            } else {
                self::assertContains('length', $this->skipReasons($id), "case {$i}");
            }
        }
    }

    public function testT11LinksAreSkipped(): void
    {
        $this->settings(['limit_post' => [0, 3600], 'limit_author' => [0, 86400]]);
        $texts = ['read https://x for more', 'try www.x please now', 'see <a href="/">here</a> ok', 'look at example.com/path now', 'go and see example.com.'];
        foreach ($texts as $i => $t) {
            $id = $this->postComment(['comment_content' => $t, 'comment_author_email' => "l{$i}@example.net"]);
            self::assertContains('link', $this->skipReasons($id), $t);
        }
        $web = $this->postComment(['comment_author_url' => 'https://example.net', 'comment_author_email' => 'web@example.net']);
        self::assertContains('link', $this->skipReasons($web));
        $plain = $this->postComment(['comment_content' => 'This is a plain sentence. It ends well.', 'comment_author_email' => 'plain@example.net']);
        self::assertNotNull($this->draftFor($plain));
    }

    public function testT12ContactDetails(): void
    {
        $this->settings(['limit_post' => [0, 3600], 'limit_author' => [0, 86400]]);
        $email = $this->postComment(['comment_content' => 'Write to me at me@example.net about this', 'comment_author_email' => 'c1@example.net']);
        self::assertContains('contact', $this->skipReasons($email));
        self::assertNotContains('link', $this->skipReasons($email));
        foreach (['Call +44 20 7946 0000 now please', 'Ring 98-7654-3210 tonight please'] as $i => $t) {
            $id = $this->postComment(['comment_content' => $t, 'comment_author_email' => "c2{$i}@example.net"]);
            self::assertContains('contact', $this->skipReasons($id), $t);
        }
        $seven = $this->postComment(['comment_content' => 'The figure was 1234567 in total last year', 'comment_author_email' => 'c3@example.net']);
        self::assertNotNull($this->draftFor($seven));
    }

    public function testT13Blocklist(): void
    {
        $this->settings(['blocklist' => "Rubbish\nदुष्ट\nfake news", 'limit_post' => [0, 3600], 'limit_author' => [0, 86400]]);
        update_option('disallowed_keys', "scam");
        $hit = ['This is RUBBISH reporting today', 'यह दुष्ट लेख है सच में', "That is fake\nnews and you know it", 'Total scam of an article here'];
        foreach ($hit as $i => $t) {
            $id = $this->postComment(['comment_content' => $t, 'comment_author_email' => "b{$i}@example.net"]);
            self::assertContains('blocklist', $this->skipReasons($id), $t);
        }
        $miss = ['Rubbishy weather but fine article', 'Scampi for lunch, good article'];
        foreach ($miss as $i => $t) {
            $id = $this->postComment(['comment_content' => $t, 'comment_author_email' => "m{$i}@example.net"]);
            self::assertNotNull($this->draftFor($id), $t);
        }
    }

    public function testT14AuthorFlaggedByUserIdOrEmailButNotItself(): void
    {
        $this->insertComment(['user_id' => self::READER, 'comment_approved' => 'trash', 'comment_author_email' => 'zzz@example.net']);
        $byUser = $this->postComment(['user_id' => self::READER, 'comment_author_email' => 'new@example.net']);
        self::assertContains('author_flagged', $this->skipReasons($byUser));

        $this->insertComment(['comment_approved' => 'spam', 'comment_author_email' => 'flag@example.net']);
        $byEmail = $this->postComment(['comment_author_email' => 'flag@example.net', 'comment_content' => 'A fair question about fares?']);
        self::assertContains('author_flagged', $this->skipReasons($byEmail));

        $self = $this->insertComment(['comment_author_email' => 'solo@example.net', 'comment_approved' => '0']);
        global $wpdb;
        \WPStub::internal(static fn () => $wpdb->query("UPDATE {$wpdb->comments} SET comment_approved = 'trash' WHERE comment_ID = {$self}"));
        $decision = $this->plugin->intake->evaluate($self);
        self::assertNotContains('author_flagged', $decision->reasons());
        self::assertContains('spam', $decision->reasons());
    }

    public function testT15KillSwitchSkipsAndNothingAppearsLater(): void
    {
        $this->settings(['kill' => true]);
        $id = $this->postComment();
        self::assertContains('disabled', $this->skipReasons($id));
        $this->settings(['kill' => false]);
        $this->plugin->runner->run();
        self::assertNull($this->draftFor($id));
    }

    public function testT16RateLimits(): void
    {
        $root = $this->postComment(['comment_approved' => '1', 'comment_author_email' => 'r0@example.net', 'comment_content' => 'Root question on the fares?']);
        $r1 = $this->postComment(['comment_parent' => $root, 'comment_author_email' => 'r1@example.net', 'comment_content' => 'Second in thread, a question?']);
        $r2 = $this->postComment(['comment_parent' => $root, 'comment_author_email' => 'r2@example.net', 'comment_content' => 'Third in thread, a question?']);
        self::assertNotNull($this->draftFor($root));
        self::assertNotNull($this->draftFor($r1));
        self::assertContains('rate_thread', $this->skipReasons($r2));

        // Per post: six per hour, the seventh is skipped (fresh post, new threads).
        $this->post(20);
        $ids = [];
        for ($i = 0; $i < 7; $i++) {
            $ids[] = $this->postComment(['comment_post_ID' => 20, 'comment_author_email' => "pp{$i}@example.net", 'comment_content' => "Post question number {$i} on fares?"]);
        }
        self::assertNotNull($this->draftFor($ids[5]));
        self::assertContains('rate_post', $this->skipReasons($ids[6]));

        // Per author: three per day, the fourth is skipped.
        $this->post(21);
        $this->post(22);
        $this->post(23);
        $this->post(24);
        $a = [];
        foreach ([21, 22, 23, 24] as $pid) {
            $a[] = $this->postComment(['comment_post_ID' => $pid, 'comment_author_email' => 'same@example.net', 'comment_content' => "Same author on post {$pid}?"]);
        }
        self::assertNotNull($this->draftFor($a[2]));
        self::assertContains('rate_author', $this->skipReasons($a[3]));

        // A limit of 0 is off.
        $this->settings(['limit_author' => [0, 86400]]);
        $this->post(25);
        $off = $this->postComment(['comment_post_ID' => 25, 'comment_author_email' => 'same@example.net', 'comment_content' => 'Fifth by the same author?']);
        self::assertNotNull($this->draftFor($off));
    }

    public function testT16CountAndInsertRunUnderTheIntakeLock(): void
    {
        $name = $this->plugin->drafts->intakeLockName();
        $other = self::secondConnection();
        $seen = [];
        add_action('cdreplies_queued', static function () use ($other, $name, &$seen): void {
            $r = $other->query("SELECT IS_FREE_LOCK('" . $other->real_escape_string($name) . "')");
            $seen[] = $r instanceof \mysqli_result ? (string) $r->fetch_row()[0] : 'error';
        });
        $this->postComment();
        self::assertSame(['0'], $seen, 'the lock is held while the draft row is written');
        $r = $other->query("SELECT IS_FREE_LOCK('" . $other->real_escape_string($name) . "')");
        self::assertSame('1', $r instanceof \mysqli_result ? (string) $r->fetch_row()[0] : 'error');

        // Another process holding the lock: intake waits, then goes on
        // without it (the reader is never blocked) and logs once.
        $other->query("SELECT GET_LOCK('" . $other->real_escape_string($name) . "', 0)");
        $this->settings(['limit_thread' => [0, 86400], 'limit_author' => [0, 86400]]);
        $id = $this->plugin->drafts->lockIntake(0) ? 0 : $this->postComment(['comment_author_email' => 'late@example.net']);
        self::assertGreaterThan(0, $id);
        self::assertNotNull($this->draftFor($id));
        $other->close();
    }

    private static function secondConnection(): \mysqli
    {
        $env = static fn (string $k, string $d): string => (string) (getenv($k) !== false ? getenv($k) : $d);
        $socket = getenv('CDR_DB_SOCKET') !== false ? (string) getenv('CDR_DB_SOCKET') : null;
        $db = mysqli_init();
        self::assertInstanceOf(\mysqli::class, $db);
        $db->real_connect($socket !== null ? 'localhost' : $env('CDR_DB_HOST', '127.0.0.1'), $env('CDR_DB_USER', 'root'), $env('CDR_DB_PASS', ''), $env('CDR_DB_NAME', 'cdreplies_test'), (int) $env('CDR_DB_PORT', '3306'), $socket);

        return $db;
    }

    public function testT17DailyCapAtIntake(): void
    {
        $this->settings(['daily_cap' => 2]);
        self::assertTrue(DailyCap::take(2));
        self::assertTrue(DailyCap::take(2));
        $id = $this->postComment();
        self::assertContains('daily_cap', $this->skipReasons($id));
    }

    public function testT18SecondCommentPostIsANoOp(): void
    {
        $id = $this->postComment();
        do_action('comment_post', $id, '0', []);
        global $wpdb;
        $n = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cdreplies_drafts WHERE comment_id = {$id}");
        self::assertSame(1, $n);
        self::assertSame([], $this->skipReasons($id));
    }

    public function testT19NoLetters(): void
    {
        $id = $this->postComment(['comment_content' => '😀😀😀😀😀 12345 !!!!!!!!!']);
        self::assertContains('no_text', $this->skipReasons($id));
    }

    public function testT20VetoFilter(): void
    {
        add_filter('cdreplies_should_draft', '__return_false');
        $id = $this->postComment();
        self::assertSame(['filtered'], $this->skipReasons($id));

        remove_all_filters('cdreplies_should_draft');
        add_filter('cdreplies_should_draft', '__return_true');
        $ping = $this->postComment(['comment_type' => 'pingback', 'comment_author_email' => 'p@example.net']);
        self::assertContains('type', $this->skipReasons($ping));
        self::assertNull($this->draftFor($ping));
    }

    public function testT20FilterIsNotConsultedWhenAHardRuleFailed(): void
    {
        $seen = 0;
        add_filter('cdreplies_should_draft', static function ($v) use (&$seen) {
            $seen++;
            return $v;
        });
        $this->postComment(['comment_type' => 'pingback']);
        self::assertSame(0, $seen);
    }

    public function testT21SkipRecordAndLogCarryIdsAndCodesOnly(): void
    {
        $lines = [];
        add_action('cdreplies_log', static function ($level, $event, $ctx) use (&$lines) {
            $lines[] = [$event, $ctx];
        }, 10, 3);
        $id = $this->postComment(['comment_type' => 'pingback', 'comment_author' => 'Secret Name', 'comment_author_email' => 'secret@example.net', 'comment_content' => 'Secret words in the comment text']);
        $meta = (string) get_comment_meta($id, '_cdreplies_skip', true);
        $decoded = json_decode($meta, true);
        self::assertSame(['type'], $decoded['reasons']);
        self::assertSame('intake', $decoded['lane']);
        self::assertArrayHasKey('at', $decoded);
        $skips = array_values(array_filter($lines, static fn ($l) => $l[0] === 'skip'));
        self::assertCount(1, $skips);
        self::assertSame($id, $skips[0][1]['comment']);
        self::assertSame(self::POST, $skips[0][1]['post']);
        $blob = $meta . json_encode($lines);
        foreach (['Secret Name', 'secret@example.net', 'Secret words'] as $needle) {
            self::assertStringNotContainsString($needle, $blob);
        }
        self::assertSame(1, did_action('cdreplies_skipped'));
    }

    public function testT65ExceptionInIntakeIsCaught(): void
    {
        add_filter('cdreplies_should_draft', static function () {
            throw new \RuntimeException('boom');
        });
        $id = $this->postComment();
        self::assertNotNull(get_comment($id));
        self::assertNull($this->draftFor($id));
    }

    public function testThreadRootFollowsBrokenChainToLastFound(): void
    {
        $orphan = $this->postComment(['comment_parent' => 99999]);
        self::assertSame($orphan, $this->draftFor($orphan)->threadRootId());
    }

    public function testEvaluateWritesNothing(): void
    {
        $id = $this->insertComment();
        $d = cdreplies_evaluate($id);
        self::assertTrue($d->eligible());
        self::assertNull($this->draftFor($id));
        self::assertSame('', get_comment_meta($id, '_cdreplies_skip', true));
        self::assertSame(self::NOW, Clock::now());
    }
}
