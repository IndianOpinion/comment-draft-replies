<?php

declare(strict_types=1);

/**
 * A small wpdb over mysqli, enough for this plugin and its tests. Queries
 * issued while WPStub::$internal > 0 (core stubs) are not counted, so tests
 * can count the plugin's own reads and writes.
 */
class wpdb
{
    public string $prefix = 'wp_';
    public string $base_prefix = 'wp_';
    public string $options = 'wp_options';
    public string $comments = 'wp_comments';
    public string $commentmeta = 'wp_commentmeta';
    public string $posts = 'wp_posts';
    public int $insert_id = 0;
    public string $last_error = '';
    public int $rows_affected = 0;
    public int $blogid = 1;
    /** @var list<string> */
    public array $log = [];
    private bool $suppress = false;

    public function __construct(private mysqli $db)
    {
    }

    public function set_blog_id(int $id): void
    {
        $this->blogid = $id;
        $this->prefix = $id === 1 ? $this->base_prefix : $this->base_prefix . $id . '_';
        $this->options = $this->prefix . 'options';
        $this->comments = $this->prefix . 'comments';
        $this->commentmeta = $this->prefix . 'commentmeta';
        $this->posts = $this->prefix . 'posts';
    }

    public function suppress_errors(bool $suppress = true): bool
    {
        $old = $this->suppress;
        $this->suppress = $suppress;

        return $old;
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function _real_escape(string $s): string
    {
        return $this->db->real_escape_string($s);
    }

    public function prepare(string $query, mixed ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        $out = preg_replace_callback('/%(%|d|s|f)/', function (array $m) use (&$i, $args): string {
            if ($m[1] === '%') {
                return '%';
            }
            if (!array_key_exists($i, $args)) {
                throw new \LogicException('prepare(): too few arguments for ' . $m[0]);
            }
            $v = $args[$i++];
            return match ($m[1]) {
                'd' => (string) (int) $v,
                'f' => (string) (float) $v,
                default => "'" . $this->db->real_escape_string((string) $v) . "'",
            };
        }, $query);
        if ($i !== count($args)) {
            throw new \LogicException('prepare(): too many arguments');
        }

        return (string) $out;
    }

    /** @return int|bool|false */
    public function query(string $sql): int|bool
    {
        if (WPStub::$internal === 0) {
            $this->log[] = $sql;
        }
        $this->last_error = '';
        $res = $this->db->query($sql);
        if ($res === false) {
            $this->last_error = $this->db->error;
            if (!$this->suppress && WPStub::$echoErrors) {
                fwrite(STDERR, "SQL error: {$this->last_error}\n{$sql}\n");
            }

            return false;
        }
        if ($res instanceof mysqli_result) {
            $this->lastResult = $res->fetch_all(MYSQLI_ASSOC);
            $res->free();

            return count($this->lastResult);
        }
        $this->lastResult = [];
        if (preg_match('/^\s*(insert|replace)/i', $sql) === 1) {
            $this->insert_id = (int) $this->db->insert_id;
        }
        if (preg_match('/^\s*(insert|update|delete|replace)/i', $sql) === 1) {
            $this->rows_affected = (int) $this->db->affected_rows;

            return $this->rows_affected;
        }

        return true;
    }

    /** @var list<array<string, string|null>> */
    private array $lastResult = [];

    public function get_var(string $sql, int $x = 0, int $y = 0): ?string
    {
        if ($this->query($sql) === false) {
            return null;
        }
        $row = $this->lastResult[$y] ?? null;
        if ($row === null) {
            return null;
        }
        $vals = array_values($row);

        return isset($vals[$x]) ? (string) $vals[$x] : null;
    }

    /** @return array<string, mixed>|object|null */
    public function get_row(string $sql, string $output = 'OBJECT', int $y = 0): array|object|null
    {
        if ($this->query($sql) === false) {
            return null;
        }
        $row = $this->lastResult[$y] ?? null;
        if ($row === null) {
            return null;
        }

        return $output === ARRAY_A ? $row : (object) $row;
    }

    /** @return list<string|null> */
    public function get_col(string $sql, int $x = 0): array
    {
        if ($this->query($sql) === false) {
            return [];
        }
        $out = [];
        foreach ($this->lastResult as $row) {
            $vals = array_values($row);
            $out[] = $vals[$x] ?? null;
        }

        return $out;
    }

    /** @return list<mixed>|null */
    public function get_results(string $sql, string $output = 'OBJECT'): ?array
    {
        if ($this->query($sql) === false) {
            return null;
        }

        return $output === ARRAY_A ? $this->lastResult : array_map(static fn (array $r): object => (object) $r, $this->lastResult);
    }

    /** Raw access for the stubs and fixtures. */
    public function raw(): mysqli
    {
        return $this->db;
    }
}
