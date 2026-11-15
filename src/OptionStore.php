<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies;

/**
 * Raw option access for the values that must never be stale or must change
 * atomically: the settings when checking the switch, the daily counter and
 * the provider hold. Object caches are bypassed on purpose (section 10).
 */
final class OptionStore
{
    /**
     * One query for several options. Returns raw (serialised) values.
     *
     * @param list<string> $names
     * @return array<string, string>
     */
    public static function readFresh(array $names): array
    {
        global $wpdb;
        if ($names === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($names), '%s'));
        $sql = $wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ($placeholders)",
            ...$names
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(string) $row['option_name']] = (string) $row['option_value'];
        }

        return $out;
    }

    /**
     * Compare and swap. With $old null the option must not exist yet.
     */
    public static function swap(string $name, ?string $old, string $new): bool
    {
        global $wpdb;
        if ($old === null) {
            $done = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $name,
                $new
            ));
        } else {
            $done = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                $new,
                $name,
                $old
            ));
        }
        wp_cache_delete($name, 'options');

        return $done === 1;
    }

    public static function forget(string $name): void
    {
        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');
    }
}
