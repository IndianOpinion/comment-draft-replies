<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Admin;

use IndianOpinion\CommentDraftReplies\Draft;
use IndianOpinion\CommentDraftReplies\DraftRepository;
use IndianOpinion\CommentDraftReplies\Review\Publisher;

/**
 * Inline panel on the core Comments screen (case 38). It is attached only
 * when that admin screen loads, so no front-end, feed or REST path can ever
 * see a draft.
 */
final class CommentsScreen
{
    private bool $active = false;

    public function __construct(private readonly DraftRepository $drafts)
    {
    }

    public function register(): void
    {
        add_action('load-edit-comments.php', [$this, 'activate']);
    }

    public function activate(): void
    {
        $this->active = true;
        add_filter('comment_text', [$this, 'append'], 99, 2);
        add_action('admin_enqueue_scripts', [Assets::class, 'enqueue']);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function append(mixed $text, mixed $comment = null): mixed
    {
        if (!$this->active || !is_admin() || !$comment instanceof \WP_Comment || !is_string($text)) {
            return $text;
        }
        if (!Publisher::canReview(get_current_user_id(), (int) $comment->comment_post_ID)) {
            return $text;
        }
        $draft = $this->drafts->findByComment((int) $comment->comment_ID);
        if (!$draft instanceof Draft || !in_array($draft->status(), Panel::SHOWN, true)) {
            return $text;
        }

        return $text . Panel::render($draft, $comment);
    }
}
