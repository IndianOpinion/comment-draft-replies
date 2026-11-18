<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Admin;

use IndianOpinion\CommentDraftReplies\Draft;
use IndianOpinion\CommentDraftReplies\Settings;

/**
 * The draft panel shown on the Comments screen and the Reply drafts screen
 * (case 38). Every value is escaped; draft text is never rendered as HTML.
 */
final class Panel
{
    public const SHOWN = ['ready', 'failed', 'declined', 'stale'];

    public static function render(Draft $draft, \WP_Comment $comment): string
    {
        $held = (string) $comment->comment_approved !== '1';
        $s = Settings::all();
        $h = '<div class="cdreplies-panel" data-draft="' . esc_attr((string) $draft->id()) . '" data-status="' . esc_attr($draft->status()) . '">';
        $h .= '<p class="cdreplies-head"><strong>' . esc_html__('Reply draft', 'comment-draft-replies') . '</strong> ';
        $h .= '<span class="cdreplies-status cdreplies-status-' . esc_attr($draft->status()) . '">' . esc_html($draft->status()) . '</span>';
        if ($draft->flags() !== []) {
            $h .= ' <span class="cdreplies-flags">' . esc_html(implode(', ', $draft->flags())) . '</span>';
        }
        $h .= '</p>';
        if ($draft->status() === 'ready') {
            $h .= '<textarea class="cdreplies-text widefat" rows="4" aria-label="' . esc_attr__('Reply text', 'comment-draft-replies') . '">' . esc_textarea((string) $draft->reply()) . '</textarea>';
        }
        if ($draft->reason() !== null && $draft->reason() !== '') {
            $h .= '<p class="cdreplies-reason">' . esc_html__('Reason:', 'comment-draft-replies') . ' ' . esc_html((string) $draft->reason()) . '</p>';
        }
        if ($draft->lastReason() !== null) {
            $h .= '<p class="cdreplies-code">' . esc_html__('Code:', 'comment-draft-replies') . ' ' . esc_html((string) $draft->lastReason()) . '</p>';
        }
        $h .= '<p class="cdreplies-meta">' . esc_html(sprintf('%s, %s, generation %d', (string) ($draft->model() ?? '-'), (string) ($draft->promptId() ?? '-'), $draft->generation())) . '</p>';
        $h .= '<p class="cdreplies-actions">';
        if ($draft->status() === 'ready') {
            if ($held && !empty($s['approve_and_publish'])) {
                $h .= self::button('approve_publish', __('Approve comment and publish reply', 'comment-draft-replies'), true);
            } elseif (!$held) {
                $h .= self::button('publish', __('Publish reply', 'comment-draft-replies'), true);
            } else {
                $h .= '<em>' . esc_html__('Approve the comment first to publish this reply.', 'comment-draft-replies') . '</em> ';
            }
        }
        if ($draft->status() === 'declined') {
            $h .= self::button('anyway', __('Draft anyway', 'comment-draft-replies'));
        }
        $h .= self::button('regenerate', __('Regenerate', 'comment-draft-replies'));
        $h .= '<select class="cdreplies-discard-reason" aria-label="' . esc_attr__('Discard reason', 'comment-draft-replies') . '"><option value="">' . esc_html__('No reason', 'comment-draft-replies') . '</option>';
        foreach (Settings::DISCARD_REASONS as $r) {
            $h .= '<option value="' . esc_attr($r) . '">' . esc_html($r) . '</option>';
        }
        $h .= '</select> ' . self::button('discard', __('Discard', 'comment-draft-replies'));
        $h .= '</p><p class="cdreplies-message" role="status" aria-live="polite"></p></div>';

        return $h;
    }

    private static function button(string $action, string $label, bool $primary = false): string
    {
        return '<button type="button" class="button' . ($primary ? ' button-primary' : '') . ' cdreplies-btn" data-action="' . esc_attr($action) . '">' . esc_html($label) . '</button> ';
    }
}
