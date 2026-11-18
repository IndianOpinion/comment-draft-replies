<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies;

/**
 * Log lines carry ids, codes and counts only, never comment text, names or
 * addresses (case 21, section 11). Every line fires `cdreplies_log`; with a
 * log file set it is also appended as one JSON line; warnings and errors go
 * to PHP's error log when no file is set.
 */
final class Logger
{
    private const ALLOWED = ['comment', 'post', 'draft', 'codes', 'lane', 'calls', 'cap', 'until', 'count', 'user', 'generation', 'attempt', 'source', 'reply', 'status'];

    /** @param array<string, mixed> $context */
    public static function log(string $level, string $event, array $context = []): void
    {
        $clean = [];
        foreach ($context as $k => $v) {
            if (!in_array($k, self::ALLOWED, true)) {
                continue;
            }
            if (is_array($v)) {
                $clean[$k] = array_values(array_map(static fn ($c): string => is_scalar($c) ? (string) $c : '', $v));
            } elseif (is_int($v) || is_float($v) || is_bool($v)) {
                $clean[$k] = $v;
            } elseif (is_string($v) && preg_match('/^[A-Za-z0-9_.:@\-]{0,64}$/', $v) === 1) {
                $clean[$k] = $v;
            }
        }
        do_action('cdreplies_log', $level, $event, $clean);

        $line = ['ts' => gmdate('c', Clock::now()), 'level' => $level, 'event' => $event] + $clean;
        $file = (string) (Settings::all()['log_file'] ?? '');
        if ($file !== '') {
            $dir = dirname($file);
            if (is_dir($dir) && is_writable($dir) && (!file_exists($file) || is_writable($file))) {
                file_put_contents($file, wp_json_encode($line) . "\n", FILE_APPEND | LOCK_EX);
            }

            return;
        }
        if ($level === 'warning' || $level === 'error') {
            error_log('comment-draft-replies ' . wp_json_encode($line));
        }
    }

    /**
     * Log at most once per period for a key (transient throttle).
     *
     * @param array<string, mixed> $context
     */
    public static function throttled(string $key, int $seconds, string $level, string $event, array $context = []): bool
    {
        $tkey = 'cdreplies_lt_' . substr(md5($key), 0, 16);
        if (get_transient($tkey) !== false) {
            return false;
        }
        set_transient($tkey, 1, $seconds);
        self::log($level, $event, $context);

        return true;
    }
}
