<?php

/**
 * Autoloader for use without Composer. Loads this plugin's classes and, when
 * present, a copy of llm-json-php in lib/llm-json-php/.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'IndianOpinion\\CommentDraftReplies\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_readable($file)) {
        require $file;
    }
});

require_once __DIR__ . '/src/functions.php';

if (!class_exists('IndianOpinion\\LlmJson\\Client') && is_readable(__DIR__ . '/lib/llm-json-php/bootstrap.php')) {
    require_once __DIR__ . '/lib/llm-json-php/bootstrap.php';
}
