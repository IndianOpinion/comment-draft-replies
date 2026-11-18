<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies;

/**
 * Every query against the drafts table. Status changes are one conditional
 * update from the expected status, so two actors never both win (case 44).
 */
final class DraftRepository
{
    public function table(): string
    {
        return Schema::table();
    }

    public function find(int $id): ?Draft
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id), ARRAY_A);

        return is_array($row) ? Draft::fromRow($row) : null;
    }

    public function findByComment(int $commentId): ?Draft
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE comment_id = %d", $commentId), ARRAY_A);

        return is_array($row) ? Draft::fromRow($row) : null;
    }

    /**
     * Insert a queued row. Returns the new id, 0 when a row already exists
     * for the comment (unique key), or -1 on a database error.
     *
     * @param array<string, int|string> $fields
     */
    public function insertQueued(array $fields): int
    {
        global $wpdb;
        $fields += ['status' => 'queued', 'generation' => 1, 'regenerations' => 0, 'attempts' => 0, 'edited' => 0, 'flags' => '', 'created_at' => Clock::mysql()];
        $cols = array_keys($fields);
        $formats = array_map(static fn ($v): string => is_int($v) ? '%d' : '%s', array_values($fields));
        $sql = $wpdb->prepare(
            "INSERT IGNORE INTO {$this->table()} (" . implode(',', $cols) . ') VALUES (' . implode(',', $formats) . ')',
            ...array_values($fields)
        );
        $suppress = $wpdb->suppress_errors(true);
        $done = $wpdb->query($sql);
        $wpdb->suppress_errors($suppress);
        if ($done === false) {
            return -1;
        }

        return $done === 1 ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Move a draft from one of the expected statuses, setting fields.
     * Null values write SQL NULL. Returns true when this call won.
     *
     * @param string|list<string> $from
     * @param array<string, int|string|null> $set
     * @param array<string, int|string> $where extra equality conditions
     */
    public function transition(int $id, string|array $from, array $set, array $where = []): bool
    {
        global $wpdb;
        $from = (array) $from;
        [$setSql, $args] = $this->setClause($set);
        $sql = "UPDATE {$this->table()} SET {$setSql} WHERE id = %d";
        $args[] = $id;
        if ($from !== []) {
            $sql .= ' AND status IN (' . implode(',', array_fill(0, count($from), '%s')) . ')';
            array_push($args, ...$from);
        }
        foreach ($where as $col => $val) {
            $sql .= ' AND ' . $this->column($col) . ' = ' . (is_int($val) ? '%d' : '%s');
            $args[] = $val;
        }

        return $wpdb->query($wpdb->prepare($sql, ...$args)) === 1;
    }

    /**
     * Unconditional field update for one draft.
     *
     * @param array<string, int|string|null> $set
     */
    public function update(int $id, array $set): void
    {
        $this->transition($id, [], $set);
    }

    /**
     * @param array<string, int|string|null> $set
     * @return array{0:string,1:list<int|string>}
     */
    private function setClause(array $set): array
    {
        $parts = [];
        $args = [];
        foreach ($set as $col => $val) {
            $col = $this->column($col);
            if ($val === null) {
                $parts[] = "{$col} = NULL";
            } else {
                $parts[] = $col . ' = ' . (is_int($val) ? '%d' : '%s');
                $args[] = $val;
            }
        }

        return [implode(', ', $parts), $args];
    }

    private function column(string $col): string
    {
        if (!array_key_exists($col, Schema::COLUMNS)) {
            throw new \InvalidArgumentException('Unknown column ' . $col);
        }

        return $col;
    }

    /**
     * Counts for intake in one grouped query: does a row exist for the
     * comment, and how many rows were created in each rate window.
     *
     * @return array{existing:int,thread:int,post:int,author:int}|null null on a database error
     */
    public function intakeCounts(int $commentId, int $rootId, int $postId, string $authorKey, int $threadSince, int $postSince, int $authorSince): ?array
    {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT
                COALESCE(SUM(comment_id = %d), 0) AS existing,
                COALESCE(SUM(thread_root_id = %d AND created_at >= %s), 0) AS thread,
                COALESCE(SUM(post_id = %d AND created_at >= %s), 0) AS post,
                COALESCE(SUM(author_key = %s AND created_at >= %s), 0) AS author
             FROM {$this->table()}
             WHERE comment_id = %d OR thread_root_id = %d OR post_id = %d OR author_key = %s",
            $commentId,
            $rootId,
            Clock::mysql($threadSince),
            $postId,
            Clock::mysql($postSince),
            $authorKey,
            Clock::mysql($authorSince),
            $commentId,
            $rootId,
            $postId,
            $authorKey
        );
        $suppress = $wpdb->suppress_errors(true);
        $row = $wpdb->get_row($sql, ARRAY_A);
        $wpdb->suppress_errors($suppress);
        if (!is_array($row)) {
            return null;
        }

        return [
            'existing' => (int) $row['existing'],
            'thread' => (int) $row['thread'],
            'post' => (int) $row['post'],
            'author' => (int) $row['author'],
        ];
    }

    /** Name of the per-site intake lock (MySQL named locks are server-wide). */
    public function intakeLockName(): string
    {
        global $wpdb;

        return 'cdreplies_intake_' . substr(md5((string) ($wpdb->dbname ?? '') . '|' . $this->table()), 0, 32);
    }

    /**
     * Take the intake lock, so the rate-limit count and the insert of two
     * concurrent comments cannot interleave. False when it was not granted
     * within the timeout (the caller then goes on without it: the reader is
     * never blocked, and the daily cap still bounds spending).
     */
    public function lockIntake(int $timeout = 2): bool
    {
        global $wpdb;
        $suppress = $wpdb->suppress_errors(true);
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $this->intakeLockName(), $timeout));
        $wpdb->suppress_errors($suppress);

        return (string) $got === '1';
    }

    public function unlockIntake(): void
    {
        global $wpdb;
        $suppress = $wpdb->suppress_errors(true);
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->intakeLockName()));
        $wpdb->suppress_errors($suppress);
    }

    /**
     * Ids of queued drafts whose retry time has come, oldest first.
     *
     * @return list<int>
     */
    public function dueQueued(int $limit): array
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$this->table()} WHERE status = 'queued' AND (run_after IS NULL OR run_after <= %s) ORDER BY id ASC LIMIT %d",
            Clock::mysql(),
            $limit
        ));

        return array_values(array_map('intval', is_array($ids) ? $ids : []));
    }

    /** Claim one queued draft; returns the token when this caller won. */
    public function claim(int $id): ?string
    {
        $token = bin2hex(random_bytes(16));
        $won = $this->transition($id, 'queued', ['status' => 'generating', 'claim_token' => $token, 'claimed_at' => Clock::mysql()]);

        return $won ? $token : null;
    }

    /** Return claims older than ten minutes to the queue (case 22). */
    public function recoverAbandoned(int $olderThan = 600): int
    {
        global $wpdb;
        $n = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table()} SET status = 'queued', claim_token = NULL, claimed_at = NULL WHERE status = 'generating' AND claimed_at < %s",
            Clock::mysql(Clock::now() - $olderThan)
        ));

        return is_int($n) ? $n : 0;
    }

    /**
     * @param array{status?:string,post?:int} $filters
     * @return list<Draft>
     */
    public function list(array $filters, int $page = 1, int $perPage = 20): array
    {
        global $wpdb;
        [$where, $args] = $this->filterWhere($filters);
        $args[] = $perPage;
        $args[] = max(0, ($page - 1) * $perPage);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()} {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
            ...$args
        ), ARRAY_A);

        return array_values(array_map(static fn (array $r): Draft => Draft::fromRow($r), is_array($rows) ? $rows : []));
    }

    /** @param array{status?:string,post?:int} $filters */
    public function count(array $filters): int
    {
        global $wpdb;
        [$where, $args] = $this->filterWhere($filters);
        $sql = "SELECT COUNT(*) FROM {$this->table()} {$where}";

        return (int) $wpdb->get_var($args === [] ? $sql : $wpdb->prepare($sql, ...$args));
    }

    /**
     * @param array{status?:string,post?:int} $filters
     * @return array{0:string,1:list<int|string>}
     */
    private function filterWhere(array $filters): array
    {
        $parts = [];
        $args = [];
        if (!empty($filters['status'])) {
            $parts[] = 'status = %s';
            $args[] = (string) $filters['status'];
        }
        if (!empty($filters['post'])) {
            $parts[] = 'post_id = %d';
            $args[] = (int) $filters['post'];
        }

        return [$parts === [] ? '' : 'WHERE ' . implode(' AND ', $parts), $args];
    }

    /** @return array<string, int> */
    public function countsByStatus(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT status, COUNT(*) AS n FROM {$this->table()} GROUP BY status", ARRAY_A);
        $out = array_fill_keys(Draft::STATUSES, 0);
        foreach (is_array($rows) ? $rows : [] as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }

        return $out;
    }

    /**
     * Non-terminal drafts of one post, or of one comment.
     *
     * @return list<Draft>
     */
    public function openForPost(int $postId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE post_id = %d AND status NOT IN ('published','discarded','expired')",
            $postId
        ), ARRAY_A);

        return array_values(array_map(static fn (array $r): Draft => Draft::fromRow($r), is_array($rows) ? $rows : []));
    }

    /**
     * Ids of drafts in a status, created (or generated) before a time.
     *
     * @param list<string> $statuses
     * @return list<array{id:int,status:string,created_at:string}>
     */
    public function olderThan(array $statuses, string $column, int $before, int $limit): array
    {
        global $wpdb;
        $col = $this->column($column);
        $in = implode(',', array_fill(0, count($statuses), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, status, created_at FROM {$this->table()} WHERE status IN ({$in}) AND {$col} < %s ORDER BY id ASC LIMIT %d",
            ...array_merge($statuses, [Clock::mysql($before), $limit])
        ), ARRAY_A);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $out[] = ['id' => (int) $r['id'], 'status' => (string) $r['status'], 'created_at' => (string) $r['created_at']];
        }

        return $out;
    }

    /** @param list<int> $ids */
    public function deleteIds(array $ids): int
    {
        global $wpdb;
        if ($ids === []) {
            return 0;
        }
        $n = $wpdb->query("DELETE FROM {$this->table()} WHERE id IN (" . implode(',', array_map('intval', $ids)) . ')');

        return is_int($n) ? $n : 0;
    }

    /**
     * Drafts written for comments by one email address (privacy tools).
     *
     * @return list<Draft>
     */
    public function forEmail(string $email, int $page, int $perPage): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT d.* FROM {$this->table()} d INNER JOIN {$wpdb->comments} c ON c.comment_ID = d.comment_id
             WHERE c.comment_author_email = %s ORDER BY d.id ASC LIMIT %d OFFSET %d",
            $email,
            $perPage,
            max(0, ($page - 1) * $perPage)
        ), ARRAY_A);

        return array_values(array_map(static fn (array $r): Draft => Draft::fromRow($r), is_array($rows) ? $rows : []));
    }
}
