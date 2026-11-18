<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Admin;

use IndianOpinion\CommentDraftReplies\Rest\Controller;

/**
 * Script and data for the panels.
 */
final class Assets
{
    public static function enqueue(): void
    {
        $url = plugins_url('assets/', CDREPLIES_FILE);
        wp_enqueue_style('cdreplies-admin', $url . 'admin.css', [], CDREPLIES_VERSION);
        wp_enqueue_script('cdreplies-admin', $url . 'admin.js', [], CDREPLIES_VERSION, true);
        wp_localize_script('cdreplies-admin', 'cdrepliesAdmin', [
            'root' => esc_url_raw(rest_url(Controller::NAMESPACE . '/drafts/')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'nonce' => wp_create_nonce(Controller::NONCE_ACTION),
            'i18n' => [
                'confirmWarnings' => __('This reply has warnings: %s. Publish anyway?', 'comment-draft-replies'),
                'confirmChanged' => __('The comment changed after this draft was written. Publish anyway?', 'comment-draft-replies'),
                'notePrompt' => __('Optional note for the new draft (up to 300 characters):', 'comment-draft-replies'),
                'done' => __('Done.', 'comment-draft-replies'),
            ],
        ]);
    }
}
