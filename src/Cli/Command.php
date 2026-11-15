<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Cli;

use IndianOpinion\CommentDraftReplies\Backfill;
use IndianOpinion\CommentDraftReplies\DailyCap;
use IndianOpinion\CommentDraftReplies\Draft;
use IndianOpinion\CommentDraftReplies\DraftRepository;
use IndianOpinion\CommentDraftReplies\Generation\Runner;
use IndianOpinion\CommentDraftReplies\Hold;
use IndianOpinion\CommentDraftReplies\Housekeeping;
use IndianOpinion\CommentDraftReplies\Integration\GenQueue;
use IndianOpinion\CommentDraftReplies\Integration\PromptLibrary;
use IndianOpinion\CommentDraftReplies\Integration\SpendMeter;
use IndianOpinion\CommentDraftReplies\Review\Publisher;
use IndianOpinion\CommentDraftReplies\Settings;

/**
 * Review and operate comment reply drafts. There is no publish command:
 * publishing needs a person in a browser.
 *
 * Exit codes: 0 success, 1 error, 3 stopped by the kill switch, 4 stopped
 * at the daily cap.
 */
final class Command
{
    public const EXIT_DISABLED = 3;
    public const EXIT_CAP = 4;

    public function __construct(
        private readonly DraftRepository $drafts,
        private readonly Runner $runner,
        private readonly Backfill $backfill,
        private readonly Housekeeping $housekeeping,
        private readonly Publisher $publisher
    ) {
    }

    /**
     * Show the switch, hold, cap, counts, last error and integrations.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function status(array $args = [], array $assoc = []): void
    {
        $s = Settings::fresh();
        $hold = Hold::active();
        $cap = Settings::dailyCap($s);
        $counts = $this->drafts->countsByStatus();
        $failed = $this->drafts->list(['status' => 'failed'], 1, 1);
        $lines = [
            'Kill switch: ' . (Settings::isDisabled($s) ? 'on' . (Settings::constantDisabled() ? ' (constant)' : '') : 'off'),
            'Hold: ' . ($hold !== null ? 'until ' . gmdate('Y-m-d H:i', $hold['until']) . ' UTC (' . $hold['reason'] . ')' : 'none'),
            'Calls today: ' . DailyCap::callsToday() . ' of ' . $cap,
            'Generation: ' . (Runner::unavailableReason($s) ?? 'available'),
            'Prompt source: ' . (has_filter(PromptLibrary::RENDER_FILTER) ? 'prompt-library when the template exists, else built-in' : 'built-in'),
            'Integrations: gen-queue ' . (GenQueue::active() ? 'yes' : 'no') . ', spend-meter ' . (SpendMeter::active() ? 'yes' : 'no') . ', prompt-library ' . (has_filter(PromptLibrary::RENDER_FILTER) ? 'yes' : 'no'),
            'Last error: ' . ($failed !== [] ? 'draft ' . $failed[0]->id() . ' ' . (string) $failed[0]->lastReason() : 'none'),
        ];
        foreach ($counts as $status => $n) {
            $lines[] = sprintf('  %-10s %d', $status, $n);
        }
        foreach ($lines as $l) {
            \WP_CLI::line($l);
        }
    }

    /**
     * List drafts.
     *
     * ## OPTIONS
     *
     * [--status=<status>]
     * : Filter by status.
     *
     * [--post=<id>]
     * : Filter by post.
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * ---
     *
     * [--with-text]
     * : Include draft text.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function list(array $args = [], array $assoc = []): void
    {
        $status = (string) ($assoc['status'] ?? '');
        if ($status !== '' && !in_array($status, Draft::STATUSES, true)) {
            \WP_CLI::error('Unknown status.');
        }
        $rows = [];
        foreach ($this->drafts->list(['status' => $status, 'post' => (int) ($assoc['post'] ?? 0)], 1, 500) as $d) {
            $row = ['id' => $d->id(), 'comment' => $d->commentId(), 'post' => $d->postId(), 'status' => $d->status(), 'flags' => implode(',', $d->flags()), 'reason' => (string) $d->lastReason(), 'created' => gmdate('Y-m-d H:i', $d->createdAt())];
            if (isset($assoc['with-text'])) {
                $row['text'] = (string) $d->reply();
            }
            $rows[] = $row;
        }
        $format = (string) ($assoc['format'] ?? 'table');
        if (!in_array($format, ['table', 'json'], true)) {
            \WP_CLI::error('Format must be table or json.');
        }
        $fields = ['id', 'comment', 'post', 'status', 'flags', 'reason', 'created'];
        if (isset($assoc['with-text'])) {
            $fields[] = 'text';
        }
        \WP_CLI\Utils\format_items($format, $rows, $fields);
    }

    /**
     * Show one draft.
     *
     * <id>
     * : Draft id.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function show(array $args = [], array $assoc = []): void
    {
        $d = $this->drafts->find((int) ($args[0] ?? 0));
        if (!$d instanceof Draft) {
            \WP_CLI::error('No such draft.');

            return;
        }
        foreach ($d->toArray() as $k => $v) {
            if ($k === 'claim_token') {
                continue;
            }
            \WP_CLI::line(sprintf('%-17s %s', $k, $v === null ? '' : (string) $v));
        }
    }

    /**
     * Run the generation queue now, under the same caps and switch.
     *
     * [--max=<n>]
     * : Most drafts to process.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function run(array $args = [], array $assoc = []): void
    {
        $max = isset($assoc['max']) ? max(1, (int) $assoc['max']) : null;
        $report = $this->runner->run($max, 3600);
        update_option(\IndianOpinion\CommentDraftReplies\Admin\SiteHealth::LAST_RUN_OPTION, time(), false);
        foreach ($report['outcomes'] as $k => $n) {
            \WP_CLI::line(sprintf('%-10s %d', $k, $n));
        }
        \WP_CLI::line('Processed: ' . $report['processed']);
        $this->exitFor($report['stopped']);
    }

    /**
     * Queue drafts for recent comments.
     *
     * --since=<date>
     * : Oldest comment date (default and maximum: 7 days ago).
     *
     * [--post=<id>]
     * : Only this post.
     *
     * [--limit=<n>]
     * : Most drafts to queue.
     * ---
     * default: 50
     * ---
     *
     * [--dry-run]
     * : Print the counts and write nothing.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function backfill(array $args = [], array $assoc = []): void
    {
        $since = isset($assoc['since']) ? strtotime((string) $assoc['since']) : false;
        if ($since === false) {
            \WP_CLI::error('Give --since as a date, for example --since=2026-11-01.');

            return;
        }
        $report = $this->backfill->run($since, (int) ($assoc['post'] ?? 0), max(1, (int) ($assoc['limit'] ?? 50)), isset($assoc['dry-run']));
        \WP_CLI::line('Since: ' . $report['since'] . (isset($assoc['dry-run']) ? ' (dry run)' : ''));
        foreach ($report['decisions'] as $k => $n) {
            \WP_CLI::line(sprintf('%-10s %d', $k, $n));
        }
        foreach ($report['reasons'] as $k => $n) {
            \WP_CLI::line(sprintf('  %-16s %d', $k, $n));
        }
        $this->exitFor($report['stopped']);
    }

    /**
     * Discard drafts.
     *
     * <id>...
     * : Draft ids.
     *
     * [--reason=<reason>]
     * : wrong, tone, not_needed or other.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function discard(array $args = [], array $assoc = []): void
    {
        $reason = (string) ($assoc['reason'] ?? '');
        $user = get_current_user_id();
        $failed = 0;
        foreach ($args as $id) {
            $r = $this->publisher->discard((int) $id, $user, $reason);
            if ($r instanceof \WP_Error) {
                \WP_CLI::warning('Draft ' . (int) $id . ': ' . $r->get_error_message());
                $failed++;
            } else {
                \WP_CLI::line('Discarded ' . (int) $id);
            }
        }
        if ($failed > 0) {
            \WP_CLI::halt(1);
        }
    }

    /**
     * Turn the kill switch option on or off. Cannot override the constant.
     *
     * <state>
     * : on or off.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function kill(array $args = [], array $assoc = []): void
    {
        $state = (string) ($args[0] ?? '');
        if (!in_array($state, ['on', 'off'], true)) {
            \WP_CLI::error('Use: kill on|off');

            return;
        }
        $s = Settings::all();
        $s['kill'] = $state === 'on';
        update_option(Settings::OPTION, $s);
        \IndianOpinion\CommentDraftReplies\OptionStore::forget(Settings::OPTION);
        if ($state === 'off' && Settings::constantDisabled()) {
            \WP_CLI::warning('The option is off, but CDREPLIES_DISABLED keeps the switch on.');
        }
        \WP_CLI::success('Kill switch option ' . $state . '.');
    }

    /**
     * Set the daily cap.
     *
     * <n>
     * : Calls per day; 0 means none.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function cap(array $args = [], array $assoc = []): void
    {
        $n = (string) ($args[0] ?? '');
        if (preg_match('/^\d+$/', $n) !== 1) {
            \WP_CLI::error('The cap is a whole number.');

            return;
        }
        $s = Settings::all();
        $s['daily_cap'] = (int) $n;
        update_option(Settings::OPTION, $s);
        \IndianOpinion\CommentDraftReplies\OptionStore::forget(Settings::OPTION);
        if (defined('CDREPLIES_DAILY_CAP')) {
            \WP_CLI::warning('CDREPLIES_DAILY_CAP overrides the option.');
        }
        \WP_CLI::success('Daily cap set to ' . (int) $n . '.');
    }

    /**
     * End a provider hold.
     *
     * <clear>
     * : The word clear.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function hold(array $args = [], array $assoc = []): void
    {
        if (($args[0] ?? '') !== 'clear') {
            \WP_CLI::error('Use: hold clear');

            return;
        }
        Hold::clear();
        \WP_CLI::success('Hold cleared.');
    }

    /**
     * Run the retention purge now.
     *
     * [--dry-run]
     * : Count only.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function purge(array $args = [], array $assoc = []): void
    {
        $out = $this->housekeeping->purge(isset($assoc['dry-run']));
        \WP_CLI::line('Draft rows: ' . $out['purged']);
        \WP_CLI::line('Skip records: ' . $out['skip_meta']);
    }

    private function exitFor(?string $stopped): void
    {
        if ($stopped === 'disabled') {
            \WP_CLI::warning('Stopped: the kill switch is on.');
            \WP_CLI::halt(self::EXIT_DISABLED);
        }
        if ($stopped === 'cap') {
            \WP_CLI::warning('Stopped: the daily cap is reached.');
            \WP_CLI::halt(self::EXIT_CAP);
        }
        if (in_array($stopped, ['hold', 'unavailable', 'meter'], true)) {
            \WP_CLI::warning('Stopped: ' . $stopped . '.');
            \WP_CLI::halt(1);
        }
    }
}
