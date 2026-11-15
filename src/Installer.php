<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies;

use IndianOpinion\CommentDraftReplies\Generation\Runner;

/**
 * Activation, deactivation, multisite set-up and uninstall (cases 58, 60,
 * 61, 62).
 */
final class Installer
{
    public const OPTIONS = [Settings::OPTION, DailyCap::OPTION, Hold::OPTION, Housekeeping::STATS_OPTION, Schema::VERSION_OPTION, Admin\SiteHealth::LAST_RUN_OPTION];
    public const HOOKS = [Runner::CRON_HOOK, Runner::SAFETY_HOOK, Housekeeping::HOOK];

    public static function activate(bool $networkWide = false): void
    {
        if ($networkWide && is_multisite()) {
            foreach (self::siteIds() as $siteId) {
                switch_to_blog($siteId);
                self::installSite();
                restore_current_blog();
            }

            return;
        }
        self::installSite();
    }

    /** Set up the current site. Drafts nothing for existing comments. */
    public static function installSite(): void
    {
        Schema::install();
        add_option(Settings::OPTION, Settings::defaults(), '', true);
        self::schedule();
    }

    public static function schedule(): void
    {
        if (wp_next_scheduled(Housekeeping::HOOK) === false) {
            wp_schedule_event(Clock::now() + HOUR_IN_SECONDS, 'daily', Housekeeping::HOOK);
        }
        if (wp_next_scheduled(Runner::SAFETY_HOOK) === false) {
            wp_schedule_event(Clock::now() + 300, Runner::SCHEDULE, Runner::SAFETY_HOOK);
        }
    }

    public static function unschedule(): void
    {
        foreach (self::HOOKS as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }

    public static function deactivate(bool $networkWide = false): void
    {
        if ($networkWide && is_multisite()) {
            foreach (self::siteIds() as $siteId) {
                switch_to_blog($siteId);
                self::unschedule();
                restore_current_blog();
            }

            return;
        }
        self::unschedule();
    }

    /** New site on a network where the plugin is network-active (case 62). */
    public static function onNewSite(mixed $site): void
    {
        if (!is_object($site) || !isset($site->blog_id)) {
            return;
        }
        if (!function_exists('is_plugin_active_for_network') || !is_plugin_active_for_network(plugin_basename(CDREPLIES_FILE))) {
            return;
        }
        switch_to_blog((int) $site->blog_id);
        self::installSite();
        restore_current_blog();
    }

    /** Remove everything for every site, unless the keep constant is set. */
    public static function uninstall(): bool
    {
        if (defined('CDREPLIES_KEEP_DATA') && constant('CDREPLIES_KEEP_DATA')) {
            return false;
        }
        if (is_multisite()) {
            foreach (self::siteIds() as $siteId) {
                switch_to_blog($siteId);
                self::uninstallSite();
                restore_current_blog();
            }
        } else {
            self::uninstallSite();
        }

        return true;
    }

    public static function uninstallSite(): void
    {
        global $wpdb;
        Schema::drop();
        foreach (self::OPTIONS as $option) {
            delete_option($option);
        }
        foreach (['cdreplies_ready_count', Schema::BROKEN_TRANSIENT] as $t) {
            delete_transient($t);
        }
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_cdreplies\\_%' OR option_name LIKE '\\_transient\\_timeout\\_cdreplies\\_%'");
        self::unschedule();
        delete_metadata('comment', 0, Intake::SKIP_META, '', true);
        delete_metadata('comment', 0, Review\Publisher::PROVENANCE_META, '', true);
    }

    /** @return list<int> */
    private static function siteIds(): array
    {
        $ids = get_sites(['fields' => 'ids', 'number' => 0]);

        return array_values(array_map('intval', is_array($ids) ? $ids : []));
    }
}
