<?php

/**
 * Public functions (section 5.1). There is deliberately no publish function.
 */

declare(strict_types=1);

use IndianOpinion\CommentDraftReplies\DailyCap;
use IndianOpinion\CommentDraftReplies\Decision;
use IndianOpinion\CommentDraftReplies\Draft;
use IndianOpinion\CommentDraftReplies\Plugin;
use IndianOpinion\CommentDraftReplies\Settings;

if (!function_exists('cdreplies_get_draft')) {
    /** The draft for a comment, if any. */
    function cdreplies_get_draft(int $comment_id): ?Draft
    {
        return Plugin::instance()->drafts->findByComment($comment_id);
    }

    /** Run the intake rules without writing. */
    function cdreplies_evaluate(int $comment_id): Decision
    {
        return Plugin::instance()->intake->evaluate($comment_id, 'manual');
    }

    /** Apply the intake rules and queue a draft. */
    function cdreplies_queue(int $comment_id, string $lane = 'manual'): Draft|WP_Error
    {
        $lane = in_array($lane, ['intake', 'backfill', 'manual'], true) ? $lane : 'manual';
        $result = Plugin::instance()->intake->queue($comment_id, $lane);
        if ($result instanceof Draft) {
            return $result;
        }

        return new WP_Error('cdreplies_skipped', 'No draft was queued.', ['reasons' => $result->reasons()]);
    }

    /** Fresh read of the kill switch: true when drafting is allowed. */
    function cdreplies_is_enabled(): bool
    {
        return !Settings::isDisabled(Settings::fresh());
    }

    /** Generation calls counted today. */
    function cdreplies_calls_today(): int
    {
        return DailyCap::callsToday();
    }

    /** Calls left today, never negative. */
    function cdreplies_cap_remaining(): int
    {
        return DailyCap::remaining(Settings::dailyCap(Settings::fresh()));
    }
}
