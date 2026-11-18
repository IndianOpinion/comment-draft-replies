<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Tests\Integration;

use IndianOpinion\CommentDraftReplies\Backfill;
use IndianOpinion\CommentDraftReplies\Clock;
use IndianOpinion\CommentDraftReplies\DailyCap;
use IndianOpinion\CommentDraftReplies\Generation\Runner;
use IndianOpinion\CommentDraftReplies\Housekeeping;
use IndianOpinion\CommentDraftReplies\Installer;
use IndianOpinion\CommentDraftReplies\OptionStore;
use IndianOpinion\CommentDraftReplies\Privacy;
use IndianOpinion\CommentDraftReplies\Schema;
use IndianOpinion\CommentDraftReplies\Settings;
use IndianOpinion\CommentDraftReplies\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/** Cases 54 to 64, 66 and 69. */
final class LifecycleTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testT54ConstantForcesTheSwitchOn(): void
    {
        define('CDREPLIES_DISABLED', true);
        $this->settings(['kill' => false]);
        self::assertFalse(cdreplies_is_enabled());
        $page = new \IndianOpinion\CommentDraftReplies\Admin\SettingsPage();
        wp_set_current_user(self::ADMIN);
        $saved = $page->sanitize(['kill' => '']);
        self::assertFalse($saved['kill']);
        self::assertTrue(Settings::isDisabled($saved));
        $id = $this->postComment();
        self::assertContains('disabled', $this->skipReasons($id));
    }

    public function testT55RaceForTheLastSlotAndSiteMidnight(): void
    {
        self::assertTrue(DailyCap::take(2));
        // Runner B reads the counter, then runner A takes the last slot.
        $raw = OptionStore::readFresh([DailyCap::OPTION])[DailyCap::OPTION];
        self::assertTrue(DailyCap::take(2));
        // Runner B's stale compare-and-swap loses.
        $state = DailyCap::stateFromRaw($raw);
        $state['calls']++;
        self::assertFalse(OptionStore::swap(DailyCap::OPTION, $raw, (string) wp_json_encode($state)));
        self::assertFalse(DailyCap::take(2));
        self::assertSame(2, DailyCap::callsToday());

        // Site in Kolkata (UTC+5:30): local midnight is 18:30 UTC.
        \WPStub::$timezone = 'Asia/Kolkata';
        delete_option(DailyCap::OPTION);
        Clock::freeze(strtotime('2026-11-14T18:00:00Z'));
        self::assertTrue(DailyCap::take(1));
        self::assertFalse(DailyCap::take(1));
        Clock::freeze(strtotime('2026-11-14T18:31:00Z'));
        self::assertSame(0, DailyCap::callsToday());
        self::assertTrue(DailyCap::take(1));
    }

    public function testT69UtcMidnightDoesNotResetAndTimezoneChangeWaitsForTheDayBoundary(): void
    {
        \WPStub::$timezone = 'America/New_York';
        Clock::freeze(strtotime('2026-11-14T23:30:00Z')); // 18:30 local.
        self::assertTrue(DailyCap::take(5));
        Clock::freeze(strtotime('2026-11-15T00:30:00Z')); // UTC midnight passed, 19:30 local.
        self::assertSame(1, DailyCap::callsToday());

        // Operator switches to Kolkata, where it is already the 15th.
        \WPStub::$timezone = 'Asia/Kolkata';
        self::assertSame(1, DailyCap::callsToday(), 'no early reset');
        Clock::freeze(strtotime('2026-11-15T05:01:00Z')); // Midnight New York passed.
        self::assertSame(0, DailyCap::callsToday());
        self::assertTrue(DailyCap::take(5));
        $state = json_decode(OptionStore::readFresh([DailyCap::OPTION])[DailyCap::OPTION], true);
        self::assertSame('Asia/Kolkata', $state['tz']);
    }

    public function testCapReachedFiresOncePerDay(): void
    {
        $this->settings(['daily_cap' => 1]);
        DailyCap::take(1);
        DailyCap::take(1);
        DailyCap::take(1);
        self::assertSame(1, did_action('cdreplies_cap_reached'));
    }

    public function testT56LimitsCountAllStatusesAndBackfill(): void
    {
        $this->settings(['limit_post' => [2, 3600], 'limit_author' => [0, 86400]]);
        $a = $this->postComment(['comment_author_email' => 'a@example.net']);
        $this->plugin->publisher->discard($this->draftFor($a)->id(), self::EDITOR, '');
        $old = $this->insertComment(['comment_author_email' => 'b@example.net', 'comment_content' => 'Backfilled question?']);
        (new Backfill($this->plugin->intake, $this->plugin->drafts))->run(Clock::now() - 3600);
        self::assertNotNull($this->draftFor($old));
        $c = $this->postComment(['comment_author_email' => 'c@example.net', 'comment_content' => 'Third on the post?']);
        self::assertContains('rate_post', $this->skipReasons($c));
    }

    public function testT58ActivationCreatesAndVerifiesAndDraftsNothing(): void
    {
        $existing = $this->insertComment();
        Schema::drop();
        delete_option(Schema::VERSION_OPTION);
        Installer::activate();
        self::assertSame([], Schema::missingColumns());
        self::assertSame(Schema::VERSION, get_option(Schema::VERSION_OPTION));
        self::assertNull($this->draftFor($existing));
        self::assertNotFalse(wp_next_scheduled(Housekeeping::HOOK));
        self::assertNotFalse(wp_next_scheduled(Runner::SAFETY_HOOK));
        self::assertIsArray(get_option(Settings::OPTION));
    }

    public function testT59Backfill(): void
    {
        $this->settings(['limit_post' => [0, 3600], 'limit_author' => [0, 86400], 'limit_thread' => [0, 86400]]);
        $tooOld = $this->insertComment(['comment_date_gmt' => Clock::mysql(Clock::now() - 9 * 86400), 'comment_author_email' => 'o@example.net']);
        $ids = [];
        for ($i = 0; $i < 4; $i++) {
            $ids[] = $this->insertComment(['comment_date_gmt' => Clock::mysql(Clock::now() - (4 - $i) * 3600), 'comment_author_email' => "bf{$i}@example.net", 'comment_content' => "Backfill question {$i}?"]);
        }
        $ping = $this->insertComment(['comment_type' => 'pingback', 'comment_author_email' => 'ping@example.net']);
        $bf = new Backfill($this->plugin->intake, $this->plugin->drafts);

        $dry = $bf->run(Clock::now() - 30 * 86400, 0, 50, true);
        self::assertSame(4, $dry['decisions']['queued']);
        self::assertSame(['type' => 1], $dry['reasons']);
        self::assertSame(0, $this->plugin->drafts->count([]));
        self::assertSame('', get_comment_meta($ping, '_cdreplies_skip', true));
        self::assertSame(gmdate('c', Clock::now() - 7 * 86400), $dry['since'], 'age limit');

        $limited = $bf->run(Clock::now() - 86400, 0, 2);
        self::assertSame(2, $limited['queued']);
        self::assertSame('limit', $limited['stopped']);
        self::assertNotNull($this->draftFor($ids[0]));
        self::assertNull($this->draftFor($ids[2]));
        self::assertNull($this->draftFor($tooOld));

        $this->settings(['daily_cap' => 1]);
        $capped = $bf->run(Clock::now() - 86400);
        self::assertSame(1, $capped['queued']);
        self::assertSame('cap', $capped['stopped']);

        $this->settings(['kill' => true, 'daily_cap' => 30]);
        $killed = $bf->run(Clock::now() - 86400);
        self::assertSame('disabled', $killed['stopped']);
        self::assertSame(0, $killed['queued']);
    }

    public function testT60DeactivationClearsEventsAndKeepsRows(): void
    {
        $this->postComment();
        self::assertNotFalse(wp_next_scheduled(Housekeeping::HOOK));
        Installer::deactivate();
        foreach (Installer::HOOKS as $hook) {
            self::assertFalse(wp_next_scheduled($hook));
        }
        self::assertSame(1, $this->plugin->drafts->count([]));
    }

    public function testT61UninstallRemovesEverythingButReplies(): void
    {
        $d = $this->readyDraft(['comment_approved' => '1']);
        $this->loginBrowser(self::EDITOR);
        $r = $this->rest('POST', '/comment-draft-replies/v1/drafts/' . $d->id() . '/publish', [], $this->nonceHeader());
        $replyId = $r->get_data()['reply_comment_id'];
        $skipped = $this->postComment(['comment_type' => 'pingback']);
        self::assertTrue(Installer::uninstall());
        self::assertFalse(Schema::tableExists());
        foreach (Installer::OPTIONS as $o) {
            self::assertFalse(get_option($o), $o);
        }
        foreach (Installer::HOOKS as $hook) {
            self::assertFalse(wp_next_scheduled($hook));
        }
        self::assertSame('', get_comment_meta($replyId, '_cdreplies_draft', true));
        self::assertSame('', get_comment_meta($skipped, '_cdreplies_skip', true));
        self::assertNotNull(get_comment($replyId));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testT61KeepConstantKeepsEverything(): void
    {
        define('CDREPLIES_KEEP_DATA', true);
        $this->postComment();
        self::assertFalse(Installer::uninstall());
        self::assertTrue(Schema::tableExists());
        self::assertSame(1, $this->plugin->drafts->count([]));
    }

    public function testT62MultisiteEachSiteHasItsOwnTableCapAndSwitch(): void
    {
        \WPStub::$multisite = true;
        Installer::activate(true);
        switch_to_blog(2);
        self::assertTrue(Schema::tableExists());
        self::assertSame('wp_2_cdreplies_drafts', Schema::table());
        update_option(Settings::OPTION, array_merge(Settings::defaults(), ['kill' => true, 'daily_cap' => 3]));
        DailyCap::take(3);
        restore_current_blog();
        self::assertFalse(Settings::isDisabled(Settings::fresh()));
        self::assertSame(0, DailyCap::callsToday());
        switch_to_blog(2);
        self::assertSame(1, DailyCap::callsToday());
        restore_current_blog();

        global $wpdb;
        $wpdb->raw()->query('DROP TABLE IF EXISTS wp_2_cdreplies_drafts');
        Installer::onNewSite((object) ['blog_id' => 2]);
        switch_to_blog(2);
        self::assertTrue(Schema::tableExists());
        restore_current_blog();

        Installer::uninstall();
        switch_to_blog(2);
        self::assertFalse(Schema::tableExists());
        restore_current_blog();
        self::assertFalse(Schema::tableExists());
    }

    public function testT63RetentionPurgesOldTerminalRowsAndCounts(): void
    {
        $old = $this->postComment(['comment_author_email' => 'old@example.net']);
        $young = $this->postComment(['comment_author_email' => 'young@example.net', 'comment_content' => 'Young question here?']);
        $open = $this->postComment(['comment_author_email' => 'open@example.net', 'comment_content' => 'Still queued question?']);
        foreach ([$old, $young] as $c) {
            $this->plugin->publisher->discard($this->draftFor($c)->id(), self::EDITOR, '');
        }
        $this->plugin->drafts->update($this->draftFor($old)->id(), ['created_at' => Clock::mysql(Clock::now() - 91 * 86400)]);
        $this->plugin->drafts->update($this->draftFor($young)->id(), ['created_at' => Clock::mysql(Clock::now() - 89 * 86400)]);
        $this->plugin->drafts->update($this->draftFor($open)->id(), ['created_at' => Clock::mysql(Clock::now() - 91 * 86400), 'status' => 'ready']);
        $skipOld = $this->insertComment(['comment_author_email' => 's@example.net']);
        add_comment_meta($skipOld, '_cdreplies_skip', (string) wp_json_encode(['at' => gmdate('Y-m-d\TH:i:s\Z', Clock::now() - 91 * 86400), 'reasons' => ['type'], 'lane' => 'intake']));

        $hk = new Housekeeping($this->plugin->drafts);
        $dry = $hk->purge(true);
        self::assertSame(1, $dry['purged']);
        self::assertNotNull($this->draftFor($old));

        $out = $hk->purge();
        self::assertSame(1, $out['purged']);
        self::assertSame(1, $out['skip_meta']);
        self::assertNull($this->draftFor($old));
        self::assertNotNull($this->draftFor($young));
        self::assertNotNull($this->draftFor($open), 'ready is not terminal');
        $stats = get_option(Housekeeping::STATS_OPTION);
        self::assertSame(1, array_sum(array_map(static fn ($d) => $d['discarded'] ?? 0, $stats)));
        self::assertSame('', get_comment_meta($skipOld, '_cdreplies_skip', true));
    }

    public function testT64PrivacyExportEraseAndPolicy(): void
    {
        $d = $this->readyDraft(['comment_author_email' => 'subject@example.net'], 'Draft for the subject.');
        $this->readyDraft(['comment_author_email' => 'other@example.net', 'comment_content' => 'Someone else asks?'], 'Draft for someone else.');
        $p = new Privacy($this->plugin->drafts);
        $export = $p->export('subject@example.net');
        self::assertCount(1, $export['data']);
        self::assertStringContainsString('Draft for the subject.', json_encode($export['data']));
        self::assertTrue($export['done']);

        $erase = $p->erase('subject@example.net');
        self::assertSame(1, $erase['items_removed']);
        $after = $this->plugin->drafts->find($d->id());
        self::assertNull($after->reply());
        self::assertSame('erased', $after->lastReason());

        Privacy::policyText();
        self::assertNotEmpty(\WPStub::$policy);
        self::assertStringContainsString('language model provider', \WPStub::$policy[0][1]);
        self::assertArrayHasKey('comment-draft-replies', apply_filters('wp_privacy_personal_data_exporters', []));
        self::assertArrayHasKey('comment-draft-replies', apply_filters('wp_privacy_personal_data_erasers', []));
    }

    public function testT66MissingColumnStopsIntakeLogsHourlyAndAdminLoadRepairs(): void
    {
        global $wpdb;
        \WPStub::$echoErrors = false;
        $wpdb->raw()->query('ALTER TABLE wp_cdreplies_drafts DROP COLUMN comment_hash');
        $logs = 0;
        add_action('cdreplies_log', static function ($l, $e) use (&$logs) {
            if ($e === 'schema') {
                $logs++;
            }
        }, 10, 2);
        $a = $this->postComment();
        $b = $this->postComment(['comment_author_email' => 'b@example.net', 'comment_content' => 'Second question?']);
        \WPStub::$echoErrors = true;
        self::assertSame(1, $logs);
        self::assertNull($this->draftFor($a));
        self::assertNotNull(get_comment($b));
        self::assertTrue(Schema::isMarkedBroken());
        wp_set_current_user(self::ADMIN);
        self::assertNotSame([], \IndianOpinion\CommentDraftReplies\Admin\Notices::messages());

        \IndianOpinion\CommentDraftReplies\Admin\Notices::maybeRepairSchema();
        self::assertSame([], Schema::missingColumns());
        self::assertFalse(Schema::isMarkedBroken());
        $c = $this->postComment(['comment_author_email' => 'c@example.net', 'comment_content' => 'Third question?']);
        self::assertNotNull($this->draftFor($c));
    }

    public function testSettingsValidationKeepsOldValuesOnError(): void
    {
        [$new, $errors] = Settings::validate(['daily_cap' => '-1', 'base_url' => 'http://insecure', 'model' => 'ok/model', 'voice' => str_repeat('v', 2001), 'log_file' => 'relative.log'], Settings::defaults());
        self::assertCount(4, $errors);
        self::assertSame(30, $new['daily_cap']);
        self::assertSame(Settings::DEFAULT_BASE_URL, $new['base_url']);
        self::assertSame('ok/model', $new['model']);
        [$new] = Settings::validate(['queue_age' => '2', 'draft_age' => '3', 'limit_post_count' => '0', 'limit_post_window' => '3600'], Settings::defaults());
        self::assertSame(7200, $new['queue_age']);
        self::assertSame(3 * 86400, $new['draft_age']);
        self::assertSame([0, 3600], $new['limit_post']);
        self::assertSame('********5678', Settings::maskedKey('abcd12345678'));
        [$kept] = Settings::validate(['api_key' => ''], ['api_key' => 'existing-key-1']);
        self::assertSame('existing-key-1', $kept['api_key']);
    }
}
