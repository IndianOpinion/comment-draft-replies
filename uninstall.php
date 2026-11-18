<?php

/**
 * Uninstall (case 61): drops the table, options, transients, events and the
 * plugin's comment meta on every site. Published replies stay. Define
 * CDREPLIES_KEEP_DATA as true to keep everything.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!defined('CDREPLIES_FILE')) {
    define('CDREPLIES_FILE', __DIR__ . '/comment-draft-replies.php');
}
if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    require_once __DIR__ . '/autoload.php';
}

IndianOpinion\CommentDraftReplies\Installer::uninstall();
