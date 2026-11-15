<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies;

/**
 * Follows comment and post state changes (cases 45 to 47).
 */
final class Lifecycle
{
    private const OPEN = ['queued', 'generating', 'ready', 'failed', 'declined'];

    public function __construct(private readonly DraftRepository $drafts)
    {
    }

    public function register(): void
    {
        add_action('transition_comment_status', [$this, 'onCommentStatus'], 10, 3);
        add_action('edit_comment', [$this, 'onCommentEdited'], 10, 1);
        add_action('delete_comment', [$this, 'onCommentDeleted'], 10, 1);
        add_action('transition_post_status', [$this, 'onPostStatus'], 10, 3);
        add_action('before_delete_post', [$this, 'onPostDeleted'], 10, 1);
    }

    public function onCommentStatus(mixed $new, mixed $old, mixed $comment): void
    {
        if (!$comment instanceof \WP_Comment) {
            return;
        }
        $draft = $this->drafts->findByComment((int) $comment->comment_ID);
        if (!$draft instanceof Draft) {
            return;
        }
        $new = (string) $new;
        if ($new === 'spam' || $new === 'trash') {
            $this->makeStale($draft, $new === 'spam' ? 'comment_spam' : 'comment_trash');

            return;
        }
        if (in_array($new, ['approved', 'unapproved'], true) && $draft->status() === 'stale' && in_array($draft->lastReason(), ['comment_spam', 'comment_trash'], true)) {
            $this->restore($draft);
        }
    }

    public function onCommentEdited(mixed $commentId): void
    {
        $draft = $this->drafts->findByComment((int) $commentId);
        $comment = get_comment((int) $commentId);
        if (!$draft instanceof Draft || !$comment instanceof \WP_Comment || $draft->status() !== 'ready') {
            return;
        }
        if (Comments::textHash($comment) === $draft->commentHash() || $draft->hasFlag('comment_changed')) {
            return;
        }
        $flags = $draft->flags();
        $flags[] = 'comment_changed';
        $this->drafts->transition($draft->id(), 'ready', ['flags' => implode(',', $flags)]);
    }

    public function onCommentDeleted(mixed $commentId): void
    {
        $draft = $this->drafts->findByComment((int) $commentId);
        if (!$draft instanceof Draft || $draft->status() === 'published') {
            return;
        }
        $set = ['reply' => null, 'original_reply' => null, 'editor_note' => null, 'reason' => null, 'last_reason' => 'comment_gone'];
        if (!$draft->isTerminal()) {
            $set['status'] = 'stale';
            if ($draft->status() !== 'stale') {
                $set['previous_status'] = $this->restorable($draft->status());
            }
        }
        $this->drafts->update($draft->id(), $set);
        if (!$draft->isTerminal()) {
            $this->fire($draft->id(), 'comment_gone');
        }
    }

    public function onPostStatus(mixed $new, mixed $old, mixed $post): void
    {
        if (!$post instanceof \WP_Post || $new === $old) {
            return;
        }
        if ($old === 'publish' && $new !== 'publish') {
            foreach ($this->drafts->openForPost((int) $post->ID) as $draft) {
                $this->makeStale($draft, 'post_state');
            }
        } elseif ($new === 'publish') {
            foreach ($this->drafts->openForPost((int) $post->ID) as $draft) {
                if ($draft->status() === 'stale' && $draft->lastReason() === 'post_state') {
                    $this->restore($draft);
                }
            }
        }
    }

    public function onPostDeleted(mixed $postId): void
    {
        foreach ($this->drafts->openForPost((int) $postId) as $draft) {
            $this->onCommentDeleted($draft->commentId());
        }
    }

    private function restorable(string $status): string
    {
        return $status === 'generating' ? 'queued' : $status;
    }

    private function makeStale(Draft $draft, string $reason): void
    {
        if ($draft->isTerminal()) {
            return;
        }
        $set = ['status' => 'stale', 'last_reason' => $reason, 'claim_token' => null, 'claimed_at' => null];
        if ($draft->status() !== 'stale') {
            $set['previous_status'] = $this->restorable($draft->status());
        }
        if ($this->drafts->transition($draft->id(), $draft->status(), $set)) {
            $this->fire($draft->id(), $reason);
        }
    }

    private function restore(Draft $draft): void
    {
        $prev = $draft->previousStatus() ?? 'queued';
        if (!in_array($prev, self::OPEN, true) || $prev === 'generating') {
            $prev = 'queued';
        }
        if ($this->drafts->transition($draft->id(), 'stale', ['status' => $prev, 'previous_status' => null, 'last_reason' => null])) {
            $fresh = $this->drafts->find($draft->id());
            if ($fresh instanceof Draft && $prev === 'queued') {
                Generation\Runner::wake($fresh);
            }
        }
    }

    private function fire(int $id, string $reason): void
    {
        $d = $this->drafts->find($id);
        if ($d instanceof Draft) {
            Logger::log('info', 'stale', ['draft' => $id, 'comment' => $d->commentId(), 'post' => $d->postId(), 'codes' => [$reason]]);
            do_action('cdreplies_stale', $d, $reason);
        }
    }
}
