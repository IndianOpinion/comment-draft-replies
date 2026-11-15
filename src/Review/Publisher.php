<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Review;

use IndianOpinion\CommentDraftReplies\Clock;
use IndianOpinion\CommentDraftReplies\Comments;
use IndianOpinion\CommentDraftReplies\DailyCap;
use IndianOpinion\CommentDraftReplies\Draft;
use IndianOpinion\CommentDraftReplies\DraftRepository;
use IndianOpinion\CommentDraftReplies\Generation\Runner;
use IndianOpinion\CommentDraftReplies\Generation\Validator;
use IndianOpinion\CommentDraftReplies\Intake;
use IndianOpinion\CommentDraftReplies\Logger;
use IndianOpinion\CommentDraftReplies\Settings;
use IndianOpinion\CommentDraftReplies\Text;

/**
 * The human actions (section 4.4). Publishing exists only here and is only
 * reached from the REST handler after the user, the nonce and the
 * authentication method were checked (case 49). The capability checks are
 * repeated here as defence in depth.
 */
final class Publisher
{
    public const PROVENANCE_META = '_cdreplies_draft';
    public const EDIT_MAX = 5000;
    public const NOTE_MAX = 300;

    public function __construct(private readonly DraftRepository $drafts)
    {
    }

    public static function reviewCapability(): string
    {
        return (string) apply_filters('cdreplies_capability', 'moderate_comments');
    }

    public static function canReview(int $userId, int $postId): bool
    {
        return $userId > 0 && user_can($userId, self::reviewCapability()) && user_can($userId, 'edit_post', $postId);
    }

    private function forbidden(): \WP_Error
    {
        return new \WP_Error('rest_forbidden', 'You cannot review drafts on this post.', ['status' => 403]);
    }

    private function notFound(): \WP_Error
    {
        return new \WP_Error('cdreplies_not_found', 'No such draft.', ['status' => 404]);
    }

    private function wrongStatus(Draft $draft): \WP_Error
    {
        if ($draft->status() === 'published' || $draft->status() === 'discarded') {
            $user = $draft->decidedBy() !== null ? get_userdata($draft->decidedBy()) : false;
            $name = $user instanceof \WP_User ? $user->display_name : 'another user';
            $when = $draft->decidedAt() !== null ? gmdate('Y-m-d H:i', $draft->decidedAt()) . ' UTC' : 'an earlier time';
            $verb = $draft->status() === 'published' ? 'published' : 'discarded';

            return new \WP_Error('cdreplies_wrong_status', sprintf('Already %s by %s at %s.', $verb, $name, $when), ['status' => 409, 'draft_status' => $draft->status(), 'decided_by' => $draft->decidedBy()]);
        }

        return new \WP_Error('cdreplies_wrong_status', sprintf('This draft is %s and cannot take that action.', $draft->status()), ['status' => 409, 'draft_status' => $draft->status()]);
    }

    /**
     * Warnings for edited text (case 41). The moderator may override them.
     *
     * @return list<string>
     */
    public static function warnings(string $text): array
    {
        $tlds = array_values(array_map('strval', (array) apply_filters('cdreplies_link_tlds', Text::DEFAULT_TLDS)));
        $hosts = array_values(array_map('strval', (array) apply_filters('cdreplies_allowed_reply_hosts', [(string) parse_url(home_url('/'), PHP_URL_HOST)])));
        $plain = Text::plain($text);
        $out = [];
        if (Validator::foreignHosts($plain, $hosts, $tlds) !== []) {
            $out[] = 'link';
        }
        if (Text::hasContact($plain)) {
            $out[] = 'contact';
        }
        if (Text::hasInvisible($text)) {
            $out[] = 'invisible';
        }

        return $out;
    }

    /**
     * Publish a ready draft (cases 39 to 41, 44, 45, 51 to 53).
     *
     * @param list<string> $confirm acknowledged warnings
     * @return array{draft:Draft,reply_comment_id:int}|\WP_Error
     */
    public function publish(int $draftId, int $userId, ?string $text, bool $approveParent, array $confirm): array|\WP_Error
    {
        $draft = $this->drafts->find($draftId);
        if (!$draft instanceof Draft) {
            return $this->notFound();
        }
        if (!self::canReview($userId, $draft->postId())) {
            return $this->forbidden();
        }
        $s = Settings::all();
        if ($draft->status() === 'ready' && $draft->generatedAt() !== null && $draft->generatedAt() < Clock::now() - (int) $s['draft_age']) {
            if ($this->drafts->transition($draftId, 'ready', ['status' => 'expired', 'last_reason' => 'draft_age'])) {
                Logger::log('info', 'expire', ['draft' => $draftId, 'codes' => ['draft_age']]);
            }
            $draft = $this->drafts->find($draftId) ?? $draft;
        }
        if ($draft->status() !== 'ready') {
            return $this->wrongStatus($draft);
        }
        $comment = get_comment($draft->commentId());
        $post = $comment instanceof \WP_Comment ? get_post((int) $comment->comment_post_ID) : null;
        if (!$comment instanceof \WP_Comment || !$post instanceof \WP_Post || in_array((string) $comment->comment_approved, ['spam', 'trash'], true)) {
            return new \WP_Error('cdreplies_parent_gone', 'The comment or its post is gone, spam or in the trash.', ['status' => 409]);
        }

        // The text: as written, or edited by the moderator.
        $edited = false;
        $body = (string) $draft->reply();
        if ($text !== null && $text !== $body) {
            $filtered = trim(wp_kses($text, wp_kses_allowed_html('pre_comment_content')));
            if ($filtered === '' || Text::length($filtered) > self::EDIT_MAX) {
                return new \WP_Error('cdreplies_invalid_text', 'The reply must be between 1 and 5000 characters.', ['status' => 400]);
            }
            $missing = array_values(array_diff(self::warnings($filtered), $confirm));
            if ($missing !== []) {
                return new \WP_Error('cdreplies_confirm_needed', 'The edited reply needs confirming: ' . implode(', ', $missing) . '.', ['status' => 409, 'warnings' => $missing]);
            }
            $edited = true;
            $body = $filtered;
        }
        if ($draft->hasFlag('comment_changed') && !in_array('comment_changed', $confirm, true)) {
            return new \WP_Error('cdreplies_confirm_needed', 'The comment changed after this draft was written.', ['status' => 409, 'warnings' => ['comment_changed']]);
        }

        $held = (string) $comment->comment_approved !== '1';
        if ($held && (!$approveParent || empty($s['approve_and_publish']))) {
            return new \WP_Error('cdreplies_parent_held', 'The comment is still held. Approve it first, or use "Approve comment and publish reply".', ['status' => 409]);
        }

        // Reply author (case 53).
        $authorId = $userId;
        $named = (int) $s['reply_author'];
        if ($named > 0 && $named !== $userId) {
            $cap = (string) apply_filters('cdreplies_reply_author_capability', 'edit_others_posts');
            if (get_userdata($named) === false || !user_can($userId, $cap)) {
                return new \WP_Error('rest_forbidden', 'You cannot publish as the configured reply author.', ['status' => 403]);
            }
            $authorId = $named;
        }
        $author = get_userdata($authorId);
        if (!$author instanceof \WP_User) {
            return $this->forbidden();
        }

        // Claim: one conditional update, so only one publish wins (case 44).
        $now = Clock::mysql();
        if (!$this->drafts->transition($draftId, 'ready', ['status' => 'published', 'decided_by' => $userId, 'decided_at' => $now])) {
            return $this->wrongStatus($this->drafts->find($draftId) ?? $draft);
        }
        $revert = function () use ($draftId): void {
            $this->drafts->transition($draftId, 'published', ['status' => 'ready', 'decided_by' => null, 'decided_at' => null], []);
        };

        if ($held) {
            $ok = wp_set_comment_status($comment, 'approve', true);
            if ($ok !== true) {
                $revert();

                return new \WP_Error('cdreplies_core_refused', 'The comment could not be approved, so nothing was published.', ['status' => 409]);
            }
        }

        $data = [
            'comment_post_ID' => (int) $post->ID,
            'comment_parent' => Comments::replyParent($comment),
            'comment_content' => $body,
            'comment_type' => 'comment',
            'user_id' => $authorId,
            'comment_author' => $author->display_name,
            'comment_author_email' => $author->user_email,
            'comment_author_url' => $author->user_url,
        ];
        Intake::$publishing = $draftId;
        try {
            $newId = wp_new_comment(wp_slash($data), true);
        } finally {
            Intake::$publishing = 0;
        }
        if (!is_int($newId) || $newId <= 0) {
            $revert();
            $msg = $newId instanceof \WP_Error ? $newId->get_error_message() : 'The reply was refused.';

            return new \WP_Error('cdreplies_core_refused', $msg, ['status' => 409, 'core_code' => $newId instanceof \WP_Error ? $newId->get_error_code() : '']);
        }
        $created = get_comment($newId);
        if ($created instanceof \WP_Comment && (string) $created->comment_approved !== '1') {
            wp_set_comment_status($newId, 'approve');
        }
        add_comment_meta($newId, self::PROVENANCE_META, (string) $draftId, true);

        $set = ['reply_comment_id' => $newId, 'reply' => $body, 'editor_note' => null];
        if ($edited) {
            $set['edited'] = 1;
            $set['original_reply'] = (string) $draft->reply();
        }
        $this->drafts->update($draftId, $set);
        $final = $this->drafts->find($draftId) ?? $draft;
        Logger::log('info', 'publish', ['draft' => $draftId, 'comment' => $draft->commentId(), 'post' => $draft->postId(), 'reply' => $newId, 'user' => $userId]);
        do_action('cdreplies_published', $final, $newId, $userId);

        return ['draft' => $final, 'reply_comment_id' => $newId];
    }

    /** Discard (case 42). */
    public function discard(int $draftId, int $userId, string $reason = ''): Draft|\WP_Error
    {
        $draft = $this->drafts->find($draftId);
        if (!$draft instanceof Draft) {
            return $this->notFound();
        }
        if (!self::canReview($userId, $draft->postId())) {
            return $this->forbidden();
        }
        if ($reason !== '' && !in_array($reason, Settings::DISCARD_REASONS, true)) {
            return new \WP_Error('cdreplies_invalid_text', 'Unknown discard reason.', ['status' => 400]);
        }
        $won = $this->drafts->transition($draftId, ['queued', 'ready', 'failed', 'declined', 'stale'], [
            'status' => 'discarded',
            'last_reason' => $reason !== '' ? $reason : null,
            'decided_by' => $userId,
            'decided_at' => Clock::mysql(),
        ]);
        if (!$won) {
            return $this->wrongStatus($this->drafts->find($draftId) ?? $draft);
        }
        $final = $this->drafts->find($draftId) ?? $draft;
        Logger::log('info', 'discard', ['draft' => $draftId, 'comment' => $draft->commentId(), 'post' => $draft->postId(), 'user' => $userId, 'codes' => [$reason]]);
        do_action('cdreplies_discarded', $final, $userId, $reason);

        return $final;
    }

    /** Regenerate (case 43), including "Draft anyway" for declined drafts. */
    public function regenerate(int $draftId, int $userId, string $note = '', bool $anyway = false): Draft|\WP_Error
    {
        $draft = $this->drafts->find($draftId);
        if (!$draft instanceof Draft) {
            return $this->notFound();
        }
        if (!self::canReview($userId, $draft->postId())) {
            return $this->forbidden();
        }
        $s = Settings::fresh();
        if (Settings::isDisabled($s)) {
            return new \WP_Error('cdreplies_disabled', 'Drafting is switched off.', ['status' => 409]);
        }
        $allowed = ['ready', 'failed', 'declined', 'stale', 'expired'];
        if (!in_array($draft->status(), $allowed, true)) {
            return $this->wrongStatus($draft);
        }
        if ($draft->status() === 'stale') {
            $comment = get_comment($draft->commentId());
            $post = $comment instanceof \WP_Comment ? get_post((int) $comment->comment_post_ID) : null;
            if (!$comment instanceof \WP_Comment || in_array((string) $comment->comment_approved, ['spam', 'trash'], true) || !Comments::postEligible($post instanceof \WP_Post ? $post : null)) {
                return $this->wrongStatus($draft);
            }
        }
        $max = max(0, (int) apply_filters('cdreplies_max_regenerations', 3));
        if ($draft->regenerations() >= $max) {
            return new \WP_Error('cdreplies_regen_limit', sprintf('This comment has reached the limit of %d regenerations.', $max), ['status' => 409]);
        }
        if (DailyCap::remaining(Settings::dailyCap($s)) === 0) {
            return new \WP_Error('cdreplies_cap_reached', 'Today\'s generation cap is reached.', ['status' => 409]);
        }
        $note = Text::cut(sanitize_textarea_field($note), self::NOTE_MAX);
        $flags = array_values(array_diff($draft->flags(), ['comment_changed', 'anyway', 'script_mismatch']));
        if ($anyway) {
            $flags[] = 'anyway';
        }
        $won = $this->drafts->transition($draftId, $allowed, [
            'status' => 'queued',
            'generation' => $draft->generation() + 1,
            'regenerations' => $draft->regenerations() + 1,
            'attempts' => 0,
            'run_after' => null,
            'editor_note' => $note !== '' ? $note : null,
            'flags' => implode(',', $flags),
            'previous_status' => null,
            'last_reason' => null,
            'claim_token' => null,
            'claimed_at' => null,
        ], ['generation' => $draft->generation()]);
        if (!$won) {
            return $this->wrongStatus($this->drafts->find($draftId) ?? $draft);
        }
        $final = $this->drafts->find($draftId) ?? $draft;
        Logger::log('info', 'queue', ['draft' => $draftId, 'comment' => $draft->commentId(), 'post' => $draft->postId(), 'lane' => 'regenerate', 'generation' => $final->generation()]);
        do_action('cdreplies_queued', $final);
        Runner::wake($final);

        return $final;
    }
}
