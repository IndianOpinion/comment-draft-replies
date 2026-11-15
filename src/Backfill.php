<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies;

/**
 * Backfill (case 59): the intake rules on existing comments, oldest first.
 */
final class Backfill
{
    public function __construct(private readonly Intake $intake, private readonly DraftRepository $drafts)
    {
    }

    /**
     * @return array{decisions:array<string,int>,reasons:array<string,int>,queued:int,stopped:?string,since:string}
     */
    public function run(int $since, int $postId = 0, int $limit = 50, bool $dryRun = false): array
    {
        global $wpdb;
        $maxDays = max(1, (int) apply_filters('cdreplies_backfill_max_days', 7));
        $floor = Clock::now() - $maxDays * DAY_IN_SECONDS;
        $since = max($since, $floor);
        $report = ['decisions' => ['queued' => 0, 'skipped' => 0, 'exists' => 0], 'reasons' => [], 'queued' => 0, 'stopped' => null, 'since' => gmdate('c', $since)];

        $s = Settings::fresh();
        if (Settings::isDisabled($s)) {
            $report['stopped'] = 'disabled';

            return $report;
        }
        $room = DailyCap::remaining(Settings::dailyCap($s));

        $sql = "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_date_gmt >= %s";
        $args = [Clock::mysql($since)];
        if ($postId > 0) {
            $sql .= ' AND comment_post_ID = %d';
            $args[] = $postId;
        }
        $sql .= ' ORDER BY comment_date_gmt ASC, comment_ID ASC LIMIT 5000';
        $ids = $wpdb->get_col($wpdb->prepare($sql, ...$args));

        foreach (is_array($ids) ? $ids : [] as $id) {
            if ($report['queued'] >= $limit) {
                $report['stopped'] = 'limit';
                break;
            }
            if ($report['queued'] >= $room) {
                $report['stopped'] = 'cap';
                break;
            }
            if ($this->drafts->findByComment((int) $id) instanceof Draft) {
                $report['decisions']['exists']++;
                continue;
            }
            $result = $this->intake->queue((int) $id, 'backfill', $dryRun);
            if ($result instanceof Draft) {
                $report['decisions']['queued']++;
                $report['queued']++;
                continue;
            }
            if ($result->exists()) {
                $report['decisions']['exists']++;
                continue;
            }
            if ($result->eligible()) {
                // Dry run: would queue.
                $report['decisions']['queued']++;
                $report['queued']++;
                continue;
            }
            $report['decisions']['skipped']++;
            foreach ($result->reasons() as $r) {
                $report['reasons'][$r] = ($report['reasons'][$r] ?? 0) + 1;
            }
            if (in_array('disabled', $result->reasons(), true)) {
                $report['stopped'] = 'disabled';
                break;
            }
        }

        return $report;
    }
}
