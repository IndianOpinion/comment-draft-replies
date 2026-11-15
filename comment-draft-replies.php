<?php

/**
 * Plugin Name:       Comment Draft Replies
 * Plugin URI:        https://github.com/IndianOpinion/comment-draft-replies
 * Description:       Drafts a reply to each eligible reader comment with a language model and holds it for a moderator. Nothing publishes without a person's click.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       comment-draft-replies
 * Network:           true
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('CDREPLIES_VERSION', '0.1.0');
define('CDREPLIES_FILE', __FILE__);

if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    require_once __DIR__ . '/autoload.php';
}

register_activation_hook(__FILE__, [IndianOpinion\CommentDraftReplies\Installer::class, 'activate']);
register_deactivation_hook(__FILE__, [IndianOpinion\CommentDraftReplies\Installer::class, 'deactivate']);

IndianOpinion\CommentDraftReplies\Plugin::instance()->boot();
