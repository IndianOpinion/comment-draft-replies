<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies;

/**
 * Daily housekeeping (cases 24, 48, 63): expiry, abandoned claims,
 * retention purge in batches of 500.
 */
final class Housekeeping
{
    public const HOOK = 'cdreplies_housekeeping';
    public const STATS_OPTION = 'cdreplies_daily_stats';
    private const BATCH = 500;

    public function __construct(private readonly DraftRepository $drafts)
    {
    }

    /** @return array<string, int> counts by action */
    public function run(bool $dryRun = false): array
    {
        $out = ['expired_queue' => 0, 'expired_ready' => 0, 'recovered' => 0, 'purged' => 0, 'skip_meta' => 0];
        if (!$dryRun) {
            $out['recovered'] = $this->drafts->recoverAbandoned();
            $out += $this->expire();
        }
        $purge = $this->purge($dryRun);

        return array_merge($out, $purge);
    }

    /** @return array{expired_queue:int,expired_ready:int} */
    public function expire(): array
    {
        $s = Settings::all();
        $now = Clock::now();
        $counts = ['expired_queue' => 0, 'expired_ready' => 0];
        foreach ([['queued', 'created_at', (int) $s['queue_age'], 'queue_age', 'expired_queue'], ['ready', 'generated_at', (int) $s['draft_age'], 'draft_age', 'expired_ready']] as [$status, $col, $age, $code, $key]) {
            do {
                $rows = $this->drafts->olderThan([$status], $col, $now - $age, self::BATCH);
                foreach ($rows as $r) {
                    if ($this->drafts->transition($r['id'], $status, ['status' => 'expired', 'last_reason' => $code, 'claim_token' => null])) {
                        $counts[$key]++;
                        Logger::log('info', 'expire', ['draft' => $r['id'], 'codes' => [$code]]);
                    }
                }
            } while (count($rows) === self::BATCH);
        }

        return $counts;
    }

    /** @return array{purged:int,skip_meta:int} */
    public function purge(bool $dryRun = false): array
    {
        global $wpdb;
        $days = max(1, (int) apply_filters('cdreplies_retention_days', 90));
        $cutoff = Clock::now() - $days * DAY_IN_SECONDS;
        $purged = 0;
        $statuses = ['published', 'discarded', 'expired', 'failed', 'declined'];
        do {
            $rows = $this->drafts->olderThan($statuses, 'created_at', $cutoff, self::BATCH);
            if ($rows === []) {
                break;
            }
            if ($dryRun) {
                $purged += count($rows);
                break;
            }
            $stats = get_option(self::STATS_OPTION, []);
            $stats = is_array($stats) ? $stats : [];
            foreach ($rows as $r) {
                $day = substr($r['created_at'], 0, 10);
                $stats[$day][$r['status']] = (int) ($stats[$day][$r['status']] ?? 0) + 1;
            }
            update_option(self::STATS_OPTION, $stats, false);
            $purged += $this->drafts->deleteIds(array_map(static fn (array $r): int => $r['id'], $rows));
        } while (count($rows) === self::BATCH);

        $metaDeleted = 0;
        $lastId = 0;
        do {
            $metaRows = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_id, comment_id, meta_value FROM {$wpdb->commentmeta} WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id ASC LIMIT %d",
                Intake::SKIP_META,
                $lastId,
                self::BATCH
            ), ARRAY_A);
            $metaRows = is_array($metaRows) ? $metaRows : [];
            foreach ($metaRows as $m) {
                $lastId = (int) $m['meta_id'];
                $value = json_decode((string) $m['meta_value'], true);
                $at = is_array($value) && isset($value['at']) ? strtotime((string) $value['at']) : false;
                if ($at !== false && $at < $cutoff) {
                    if (!$dryRun) {
                        delete_comment_meta((int) $m['comment_id'], Intake::SKIP_META);
                    }
                    $metaDeleted++;
                }
            }
        } while (count($metaRows) === self::BATCH);

        if (!$dryRun && ($purged > 0 || $metaDeleted > 0)) {
            Logger::log('info', 'purge', ['count' => $purged + $metaDeleted]);
        }

        return ['purged' => $purged, 'skip_meta' => $metaDeleted];
    }
}
