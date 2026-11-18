<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies;

use IndianOpinion\CommentDraftReplies\Generation\Runner;

/**
 * Deciding whether a comment gets a draft (section 4.2).
 *
 * Fail open for the reader: nothing here may block or slow a comment.
 */
final class Intake
{
    /** Reasons the veto filter cannot override (case 20). */
    public const HARD = ['type', 'spam', 'staff', 'own_reply', 'disabled', 'daily_cap'];
    public const SKIP_META = '_cdreplies_skip';

    /** Id of the draft being published right now, so its reply is skipped. */
    public static int $publishing = 0;

    public function __construct(private readonly DraftRepository $drafts)
    {
    }

    /** `comment_post` handler. */
    public function onCommentPost(mixed $commentId, mixed $approved = null): void
    {
        $id = is_numeric($commentId) ? (int) $commentId : 0;
        try {
            if ($id > 0) {
                $this->queue($id, 'intake');
            }
        } catch (\Throwable $e) {
            Logger::log('error', 'intake_error', ['comment' => $id, 'codes' => [get_class($e)]]);
        }
    }

    /**
     * Run every rule without writing.
     */
    public function evaluate(int $commentId, string $lane = 'intake'): Decision
    {
        $comment = get_comment($commentId);
        if (!$comment instanceof \WP_Comment) {
            return new Decision(false, ['comment_gone'], $lane);
        }
        $post = get_post((int) $comment->comment_post_ID);
        $post = $post instanceof \WP_Post ? $post : null;

        $raw = OptionStore::readFresh([Settings::OPTION, DailyCap::OPTION]);
        $settingsValue = isset($raw[Settings::OPTION]) ? maybe_unserialize($raw[Settings::OPTION]) : [];
        $s = Settings::merge(is_array($settingsValue) ? $settingsValue : []);
        $calls = DailyCap::stateFromRaw($raw[DailyCap::OPTION] ?? null)['calls'];

        $reasons = [];
        if (!Comments::isOrdinary($comment)) {
            $reasons[] = 'type';
        }
        if (in_array((string) $comment->comment_approved, ['spam', 'trash'], true)) {
            $reasons[] = 'spam';
        }
        if (Comments::isStaff($comment, $post)) {
            $reasons[] = 'staff';
        }
        $replyAuthor = (int) $s['reply_author'];
        if (
            self::$publishing > 0
            || ($replyAuthor > 0 && (int) $comment->user_id === $replyAuthor)
            || ($lane !== 'intake' && get_comment_meta($commentId, Review\Publisher::PROVENANCE_META, true) !== '')
        ) {
            $reasons[] = 'own_reply';
        }
        if (!Comments::postEligible($post)) {
            $reasons[] = 'post_state';
        } elseif (!comments_open($post) && !apply_filters('cdreplies_allow_closed', false, $comment)) {
            $reasons[] = 'closed';
        }

        $plain = Text::plain((string) $comment->comment_content);
        $len = Text::length($plain);
        if ($len < (int) $s['min_length'] || $len > (int) $s['max_length']) {
            $reasons[] = 'length';
        }
        if (!empty($s['skip_links'])) {
            $tlds = array_values(array_map('strval', (array) apply_filters('cdreplies_link_tlds', Text::DEFAULT_TLDS)));
            if (Text::hasLink((string) $comment->comment_content, $tlds) || trim((string) $comment->comment_author_url) !== '') {
                $reasons[] = 'link';
            }
        }
        if (!empty($s['skip_contact']) && Text::hasContact($plain)) {
            $reasons[] = 'contact';
        }
        $terms = array_values(array_merge(Text::lines((string) $s['blocklist']), Text::lines((string) get_option('disallowed_keys', ''))));
        if ($terms !== [] && Text::matchesBlocklist($plain, $terms)) {
            $reasons[] = 'blocklist';
        }

        $rootId = Comments::threadRoot($comment);
        $authorKey = Comments::authorKey($comment);
        $now = Clock::now();
        [$tCount, $tWin] = Settings::limit($s, 'thread');
        [$pCount, $pWin] = Settings::limit($s, 'post');
        [$aCount, $aWin] = Settings::limit($s, 'author');
        $counts = $this->drafts->intakeCounts($commentId, $rootId, (int) $comment->comment_post_ID, $authorKey, $now - $tWin, $now - $pWin, $now - $aWin);
        if ($counts === null) {
            return new Decision(false, ['schema'], $lane, false, ['schema_error' => true]);
        }
        if ($counts['existing'] > 0) {
            return new Decision(false, [], $lane, true);
        }

        if (Comments::authorFlagged($comment)) {
            $reasons[] = 'author_flagged';
        }
        if (Settings::isDisabled($s)) {
            $reasons[] = 'disabled';
        }
        if ($tCount > 0 && $counts['thread'] >= $tCount) {
            $reasons[] = 'rate_thread';
        }
        if ($pCount > 0 && $counts['post'] >= $pCount) {
            $reasons[] = 'rate_post';
        }
        if ($aCount > 0 && $counts['author'] >= $aCount) {
            $reasons[] = 'rate_author';
        }
        if ($calls >= Settings::dailyCap($s)) {
            $reasons[] = 'daily_cap';
        }
        if (!Text::hasLetter($plain)) {
            $reasons[] = 'no_text';
        }

        if (array_intersect($reasons, self::HARD) === []) {
            $verdict = apply_filters('cdreplies_should_draft', $reasons === [], $comment);
            if ($reasons === [] && $verdict !== true) {
                $reasons[] = 'filtered';
            }
        }

        $facts = [
            'post_id' => (int) $comment->comment_post_ID,
            'thread_root_id' => $rootId,
            'author_key' => $authorKey,
            'comment_hash' => Comments::textHash($comment),
        ];

        return new Decision($reasons === [], array_values(array_unique($reasons)), $lane, false, $facts);
    }

    /**
     * Apply the rules and queue (section 5.1). With $dryRun nothing is
     * written. Returns the draft, the existing draft (case 18), or a
     * Decision when skipped.
     */
    public function queue(int $commentId, string $lane = 'manual', bool $dryRun = false): Draft|Decision
    {
        if ($dryRun) {
            return $this->queueUnlocked($commentId, $lane, true);
        }
        // Count and insert under one lock: without it, two comments in the
        // same thread (or by the same author) arriving together both see the
        // count below the limit and both get a draft.
        $locked = $this->drafts->lockIntake();
        if (!$locked) {
            Logger::throttled('intake_lock', HOUR_IN_SECONDS, 'warning', 'intake_lock', ['comment' => $commentId, 'codes' => ['lock_timeout']]);
        }
        try {
            return $this->queueUnlocked($commentId, $lane, false);
        } finally {
            if ($locked) {
                $this->drafts->unlockIntake();
            }
        }
    }

    private function queueUnlocked(int $commentId, string $lane, bool $dryRun): Draft|Decision
    {
        $decision = $this->evaluate($commentId, $lane);
        if ($decision->exists()) {
            $existing = $this->drafts->findByComment($commentId);

            return $existing ?? $decision;
        }
        if (!empty($decision->facts()['schema_error'])) {
            $this->schemaProblem($commentId);

            return $decision;
        }
        if (!$decision->eligible()) {
            if (!$dryRun && $decision->reasons() !== ['comment_gone']) {
                $this->recordSkip($commentId, $decision);
            }

            return $decision;
        }
        if ($dryRun) {
            return $decision;
        }

        $f = $decision->facts();
        $id = $this->drafts->insertQueued([
            'comment_id' => $commentId,
            'post_id' => (int) $f['post_id'],
            'thread_root_id' => (int) $f['thread_root_id'],
            'author_key' => (string) $f['author_key'],
            'comment_hash' => (string) $f['comment_hash'],
        ]);
        if ($id < 0) {
            $this->schemaProblem($commentId);

            return new Decision(false, ['schema'], $lane);
        }
        $draft = $id === 0 ? $this->drafts->findByComment($commentId) : $this->drafts->find($id);
        if (!$draft instanceof Draft) {
            return $decision;
        }
        if ($id > 0) {
            Logger::log('info', 'queue', ['comment' => $commentId, 'post' => $draft->postId(), 'draft' => $draft->id(), 'lane' => $lane]);
            do_action('cdreplies_queued', $draft);
            Runner::wake($draft);
        }

        return $draft;
    }

    private function recordSkip(int $commentId, Decision $decision): void
    {
        update_comment_meta($commentId, self::SKIP_META, (string) wp_json_encode([
            'at' => gmdate('Y-m-d\TH:i:s\Z', Clock::now()),
            'reasons' => $decision->reasons(),
            'lane' => $decision->lane(),
        ]));
        $comment = get_comment($commentId);
        Logger::log('info', 'skip', [
            'comment' => $commentId,
            'post' => $comment instanceof \WP_Comment ? (int) $comment->comment_post_ID : 0,
            'codes' => $decision->reasons(),
            'lane' => $decision->lane(),
        ]);
        do_action('cdreplies_skipped', $commentId, $decision->reasons(), $decision->lane());
    }

    private function schemaProblem(int $commentId): void
    {
        Schema::markBroken('intake');
        Logger::throttled('schema_intake', HOUR_IN_SECONDS, 'error', 'schema', ['comment' => $commentId, 'codes' => ['table_or_column_missing']]);
    }
}
