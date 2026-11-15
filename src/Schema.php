<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies;

/**
 * The drafts table: create, upgrade, and verify by reading the columns back
 * (case 58, case 66, lesson 17).
 */
final class Schema
{
    public const VERSION = '1';
    public const VERSION_OPTION = 'cdreplies_db_version';
    public const BROKEN_TRANSIENT = 'cdreplies_schema_broken';

    /** Column definitions, in order. */
    public const COLUMNS = [
        'id' => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT',
        'comment_id' => 'bigint(20) unsigned NOT NULL',
        'post_id' => 'bigint(20) unsigned NOT NULL',
        'thread_root_id' => 'bigint(20) unsigned NOT NULL',
        'author_key' => 'char(64) NOT NULL',
        'status' => "varchar(16) NOT NULL DEFAULT 'queued'",
        'previous_status' => 'varchar(16) NULL DEFAULT NULL',
        'reply' => 'mediumtext NULL',
        'original_reply' => 'mediumtext NULL',
        'edited' => 'tinyint(1) NOT NULL DEFAULT 0',
        'language' => 'varchar(35) NULL DEFAULT NULL',
        'flags' => "varchar(255) NOT NULL DEFAULT ''",
        'reason' => 'varchar(255) NULL DEFAULT NULL',
        'last_reason' => 'varchar(64) NULL DEFAULT NULL',
        'editor_note' => 'varchar(300) NULL DEFAULT NULL',
        'comment_hash' => "char(64) NOT NULL DEFAULT ''",
        'model' => 'varchar(128) NULL DEFAULT NULL',
        'prompt_id' => 'varchar(191) NULL DEFAULT NULL',
        'render_hash' => 'char(64) NULL DEFAULT NULL',
        'tokens_in' => 'int(10) unsigned NULL DEFAULT NULL',
        'tokens_out' => 'int(10) unsigned NULL DEFAULT NULL',
        'generation' => 'smallint(5) unsigned NOT NULL DEFAULT 1',
        'regenerations' => 'smallint(5) unsigned NOT NULL DEFAULT 0',
        'attempts' => 'smallint(5) unsigned NOT NULL DEFAULT 0',
        'claim_token' => 'char(32) NULL DEFAULT NULL',
        'claimed_at' => 'datetime NULL DEFAULT NULL',
        'run_after' => 'datetime NULL DEFAULT NULL',
        'created_at' => 'datetime NOT NULL',
        'generated_at' => 'datetime NULL DEFAULT NULL',
        'decided_at' => 'datetime NULL DEFAULT NULL',
        'decided_by' => 'bigint(20) unsigned NULL DEFAULT NULL',
        'reply_comment_id' => 'bigint(20) unsigned NULL DEFAULT NULL',
    ];

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'cdreplies_drafts';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $lines = [];
        foreach (self::COLUMNS as $name => $def) {
            $lines[] = '  ' . $name . ' ' . $def;
        }
        $lines[] = '  PRIMARY KEY  (id)';
        $lines[] = '  UNIQUE KEY comment_id (comment_id)';
        $lines[] = '  KEY post_id (post_id)';
        $lines[] = '  KEY thread_root_id (thread_root_id)';
        $lines[] = '  KEY author_key (author_key)';
        $lines[] = '  KEY status_created (status,created_at)';

        return 'CREATE TABLE ' . self::table() . " (\n" . implode(",\n", $lines) . "\n) " . $wpdb->get_charset_collate() . ';';
    }

    /**
     * Create or upgrade, then verify. Missing columns that dbDelta skipped
     * are added one by one and verified again. Returns the columns still
     * missing (empty on success).
     *
     * @return list<string>
     */
    public static function install(): array
    {
        global $wpdb;
        if (!function_exists('dbDelta')) {
            self::load(ABSPATH . 'wp-admin/includes/upgrade.php');
        }
        if (function_exists('dbDelta')) {
            dbDelta(self::createSql());
        }

        $missing = self::missingColumns();
        if ($missing !== [] && self::tableExists()) {
            $table = self::table();
            foreach ($missing as $column) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$column} " . self::COLUMNS[$column]);
            }
            $missing = self::missingColumns();
        }

        if ($missing === []) {
            update_option(self::VERSION_OPTION, self::VERSION, true);
            delete_transient(self::BROKEN_TRANSIENT);
        } else {
            set_transient(self::BROKEN_TRANSIENT, implode(',', $missing), DAY_IN_SECONDS);
            Logger::log('error', 'schema', ['codes' => $missing]);
        }

        return $missing;
    }

    private static function load(string $file): void
    {
        if (is_readable($file)) {
            require_once $file;
        }
    }

    public static function tableExists(): bool
    {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(self::table())));

        return $found === self::table();
    }

    /** @return list<string> */
    public static function missingColumns(): array
    {
        global $wpdb;
        if (!self::tableExists()) {
            return array_keys(self::COLUMNS);
        }
        $suppress = $wpdb->suppress_errors(true);
        $cols = $wpdb->get_col('SHOW COLUMNS FROM ' . self::table());
        $wpdb->suppress_errors($suppress);
        $have = array_map('strtolower', is_array($cols) ? array_map('strval', $cols) : []);

        return array_values(array_diff(array_keys(self::COLUMNS), $have));
    }

    /** Mark the schema as broken after a failed intake write (case 66). */
    public static function markBroken(string $why): void
    {
        set_transient(self::BROKEN_TRANSIENT, $why, DAY_IN_SECONDS);
    }

    public static function isMarkedBroken(): bool
    {
        return get_transient(self::BROKEN_TRANSIENT) !== false;
    }

    public static function drop(): void
    {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS ' . self::table());
    }
}
