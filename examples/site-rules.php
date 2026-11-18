<?php

/**
 * Plugin Name: Comment Draft Replies, site rules (example)
 * Description: Shows the common filters: a veto, extra reply hosts, post types and a smaller batch. Not loaded by default.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Veto: never draft on posts tagged "legal". The filter can only say no.
add_filter('cdreplies_should_draft', static function (bool $draft, WP_Comment $comment): bool {
    return $draft && !has_tag('legal', (int) $comment->comment_post_ID);
}, 10, 2);

// Replies may link to the site and its help centre.
add_filter('cdreplies_allowed_reply_hosts', static function (array $hosts): array {
    $hosts[] = 'help.example.org';
    return $hosts;
});

// Draft on a custom post type too.
add_filter('cdreplies_post_types', static fn (array $types): array => array_merge($types, ['briefing']));

// Smaller cron batches on a small host.
add_filter('cdreplies_batch_size', static fn (): int => 5);

// Send every log line to your own logger (ids and codes only).
add_action('cdreplies_log', static function (string $level, string $event, array $context): void {
    if ($level === 'error') {
        error_log('cdreplies ' . $event . ' ' . wp_json_encode($context));
    }
}, 10, 3);
