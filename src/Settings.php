<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies;

/**
 * Settings: one autoloaded option array, validated on save.
 *
 * The kill switch and the cap are read fresh from the database at every
 * intake and before every call (case 54), never from a long-lived cache.
 */
final class Settings
{
    public const OPTION = 'cdreplies_settings';
    public const DEFAULT_BASE_URL = 'https://openrouter.ai/api/v1';
    public const DISCARD_REASONS = ['wrong', 'tone', 'not_needed', 'other'];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'kill' => false,
            'base_url' => self::DEFAULT_BASE_URL,
            'api_key' => '',
            'model' => '',
            'fallback_model' => '',
            'voice' => '',
            'send_names' => false,
            'daily_cap' => 30,
            'limit_thread' => [2, 86400],
            'limit_post' => [6, 3600],
            'limit_author' => [3, 86400],
            'min_length' => 15,
            'max_length' => 5000,
            'skip_links' => true,
            'skip_contact' => true,
            'blocklist' => '',
            'reply_max' => 1000,
            'queue_age' => 48 * 3600,
            'draft_age' => 14 * 86400,
            'reply_author' => 0,
            'approve_and_publish' => true,
            'log_file' => '',
        ];
    }

    /**
     * Settings through the options API (autoloaded). Use fresh() for the
     * switch and the cap.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);

        return self::merge(is_array($stored) ? $stored : []);
    }

    /**
     * Settings read straight from the options table, bypassing every cache.
     *
     * @return array<string, mixed>
     */
    public static function fresh(): array
    {
        $raw = OptionStore::readFresh([self::OPTION]);
        $value = isset($raw[self::OPTION]) ? maybe_unserialize($raw[self::OPTION]) : [];

        return self::merge(is_array($value) ? $value : []);
    }

    /**
     * @param array<mixed> $stored
     * @return array<string, mixed>
     */
    public static function merge(array $stored): array
    {
        $out = self::defaults();
        foreach ($out as $key => $default) {
            if (array_key_exists($key, $stored)) {
                $out[$key] = $stored[$key];
            }
        }

        return $out;
    }

    public static function constantDisabled(): bool
    {
        return defined('CDREPLIES_DISABLED') && (bool) constant('CDREPLIES_DISABLED');
    }

    /** @param array<string, mixed> $settings */
    public static function isDisabled(array $settings): bool
    {
        return self::constantDisabled() || !empty($settings['kill']);
    }

    /** @param array<string, mixed> $settings */
    public static function dailyCap(array $settings): int
    {
        if (defined('CDREPLIES_DAILY_CAP')) {
            return max(0, (int) constant('CDREPLIES_DAILY_CAP'));
        }

        return max(0, (int) $settings['daily_cap']);
    }

    /** @param array<string, mixed> $settings */
    public static function providerKey(array $settings): string
    {
        if (defined('CDREPLIES_PROVIDER_KEY') && is_string(constant('CDREPLIES_PROVIDER_KEY'))) {
            return (string) constant('CDREPLIES_PROVIDER_KEY');
        }

        return (string) $settings['api_key'];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{0:int,1:int}
     */
    public static function limit(array $settings, string $which): array
    {
        $pair = $settings['limit_' . $which] ?? [0, 0];
        if (!is_array($pair)) {
            return [0, 0];
        }

        return [max(0, (int) ($pair[0] ?? 0)), max(0, (int) ($pair[1] ?? 0))];
    }

    /** Last four characters of the key, for display only. */
    public static function maskedKey(string $key): string
    {
        if ($key === '') {
            return '';
        }

        return str_repeat('*', 8) . substr($key, -4);
    }

    /**
     * Validate a submitted settings array against the old one. Returns the
     * new settings and a list of error messages; on any error the old value
     * of that field is kept (section 7).
     *
     * @param array<mixed> $input
     * @param array<string, mixed> $old
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public static function validate(array $input, array $old): array
    {
        $old = self::merge($old);
        $out = $old;
        $errors = [];

        $bool = static fn (string $k): bool => !empty($input[$k]);
        foreach (['kill', 'send_names', 'skip_links', 'skip_contact', 'approve_and_publish'] as $k) {
            $out[$k] = $bool($k);
        }

        $int = static function (string $k, int $min, int $max, string $label, int $scale = 1) use ($input, &$out, &$errors): void {
            if (!array_key_exists($k, $input)) {
                return;
            }
            $v = is_scalar($input[$k]) ? trim((string) $input[$k]) : '';
            if ($v === '' || preg_match('/^\d+$/', $v) !== 1 || (int) $v < $min || (int) $v > $max) {
                $errors[] = sprintf('%s must be a whole number from %d to %d.', $label, $min, $max);
                return;
            }
            $out[$k] = (int) $v * $scale;
        };
        $int('daily_cap', 0, 100000, 'Daily cap');
        $int('min_length', 1, 5000, 'Minimum comment length');
        $int('max_length', 1, 100000, 'Maximum comment length');
        $int('reply_max', 50, 5000, 'Reply maximum length');
        $int('queue_age', 1, 24 * 30, 'Queue age limit (hours)', 3600);
        $int('draft_age', 1, 365, 'Draft age limit (days)', 86400);
        if ((int) $out['min_length'] > (int) $out['max_length']) {
            $errors[] = 'Minimum comment length cannot exceed the maximum.';
            $out['min_length'] = $old['min_length'];
            $out['max_length'] = $old['max_length'];
        }

        foreach (['thread', 'post', 'author'] as $which) {
            $countKey = 'limit_' . $which . '_count';
            $winKey = 'limit_' . $which . '_window';
            if (!array_key_exists($countKey, $input) && !array_key_exists($winKey, $input)) {
                continue;
            }
            $c = is_scalar($input[$countKey] ?? null) ? trim((string) $input[$countKey]) : '';
            $w = is_scalar($input[$winKey] ?? null) ? trim((string) $input[$winKey]) : '';
            if (preg_match('/^\d+$/', $c) !== 1 || preg_match('/^\d+$/', $w) !== 1 || (int) $c > 10000 || (int) $w < 60 || (int) $w > 30 * 86400) {
                $errors[] = sprintf('The per %s limit needs a count from 0 and a window from 60 seconds to 30 days.', $which);
                continue;
            }
            $out['limit_' . $which] = [(int) $c, (int) $w];
        }

        if (array_key_exists('base_url', $input)) {
            $url = is_string($input['base_url']) ? trim($input['base_url']) : '';
            if (preg_match('#^https://[^\s/]+#i', $url) !== 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
                $errors[] = 'Provider base URL must be an https URL.';
            } else {
                $out['base_url'] = rtrim($url, '/');
            }
        }
        if (!empty($input['api_key_clear'])) {
            $out['api_key'] = '';
        } elseif (isset($input['api_key']) && is_string($input['api_key']) && trim($input['api_key']) !== '') {
            $key = trim($input['api_key']);
            if (preg_match('/^[\x21-\x7E]{8,512}$/', $key) !== 1) {
                $errors[] = 'Provider key must be 8 to 512 printable characters without spaces.';
            } else {
                $out['api_key'] = $key;
            }
        }
        foreach (['model' => 'Model', 'fallback_model' => 'Fallback model'] as $k => $label) {
            if (!array_key_exists($k, $input)) {
                continue;
            }
            $v = is_string($input[$k]) ? trim($input[$k]) : '';
            if ($v !== '' && preg_match('#^[A-Za-z0-9._:/@\-]{1,128}$#', $v) !== 1) {
                $errors[] = $label . ' may hold letters, digits and . _ : / @ - only, up to 128 characters.';
                continue;
            }
            $out[$k] = $v;
        }
        if (array_key_exists('voice', $input)) {
            $v = is_string($input['voice']) ? str_replace("\r\n", "\n", trim($input['voice'])) : '';
            if (Text::length($v) > 2000) {
                $errors[] = 'Voice notes are limited to 2000 characters.';
            } else {
                $out['voice'] = sanitize_textarea_field($v);
            }
        }
        if (array_key_exists('blocklist', $input)) {
            $v = is_string($input['blocklist']) ? $input['blocklist'] : '';
            $out['blocklist'] = implode("\n", Text::lines(sanitize_textarea_field($v)));
        }
        if (array_key_exists('reply_author', $input)) {
            $id = is_scalar($input['reply_author']) ? (int) $input['reply_author'] : 0;
            if ($id < 0 || ($id > 0 && get_userdata($id) === false)) {
                $errors[] = 'Reply author must be an existing user, or none.';
            } else {
                $out['reply_author'] = $id;
            }
        }
        if (array_key_exists('log_file', $input)) {
            $v = is_string($input['log_file']) ? trim($input['log_file']) : '';
            if ($v !== '' && (!str_starts_with($v, '/') || str_contains($v, '..') || preg_match('/\.(php\d?|phtml|phar)$/i', $v) === 1)) {
                $errors[] = 'Log file must be an absolute path without "..", and not a PHP file.';
            } else {
                $out['log_file'] = $v;
            }
        }

        return [$out, $errors];
    }
}
