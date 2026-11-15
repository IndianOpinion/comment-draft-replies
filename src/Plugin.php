<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies;

use IndianOpinion\CommentDraftReplies\Admin\CommentsScreen;
use IndianOpinion\CommentDraftReplies\Admin\DraftsScreen;
use IndianOpinion\CommentDraftReplies\Admin\Notices;
use IndianOpinion\CommentDraftReplies\Admin\SettingsPage;
use IndianOpinion\CommentDraftReplies\Admin\SiteHealth;
use IndianOpinion\CommentDraftReplies\Cli\Command;
use IndianOpinion\CommentDraftReplies\Generation\Runner;
use IndianOpinion\CommentDraftReplies\Integration\GenQueue;
use IndianOpinion\CommentDraftReplies\Integration\PromptLibrary;
use IndianOpinion\CommentDraftReplies\Rest\Controller;
use IndianOpinion\CommentDraftReplies\Review\Publisher;

/**
 * Wires every hook. One instance per request.
 */
final class Plugin
{
    private static ?self $instance = null;

    public readonly DraftRepository $drafts;
    public readonly Intake $intake;
    public readonly Runner $runner;
    public readonly Publisher $publisher;
    public readonly Lifecycle $lifecycle;
    public readonly Housekeeping $housekeeping;
    public readonly Backfill $backfill;
    public readonly Controller $rest;
    public readonly CommentsScreen $commentsScreen;
    public readonly DraftsScreen $draftsScreen;

    private function __construct()
    {
        $this->drafts = new DraftRepository();
        $this->intake = new Intake($this->drafts);
        $this->runner = new Runner($this->drafts);
        $this->publisher = new Publisher($this->drafts);
        $this->lifecycle = new Lifecycle($this->drafts);
        $this->housekeeping = new Housekeeping($this->drafts);
        $this->backfill = new Backfill($this->intake, $this->drafts);
        $this->rest = new Controller($this->drafts, $this->publisher);
        $this->commentsScreen = new CommentsScreen($this->drafts);
        $this->draftsScreen = new DraftsScreen($this->drafts, $this->publisher);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** Tests only: forget the instance. */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function boot(): void
    {
        add_action('comment_post', [$this->intake, 'onCommentPost'], 20, 2);
        $this->lifecycle->register();
        add_filter('cron_schedules', [self::class, 'schedules']);
        add_action(Runner::CRON_HOOK, [$this, 'cronRun']);
        add_action(Runner::SAFETY_HOOK, [$this, 'cronRun']);
        add_action(Housekeeping::HOOK, [$this, 'cronHousekeeping']);
        add_action('rest_api_init', [$this->rest, 'register']);
        add_action('init', [PromptLibrary::class, 'registerDefault']);
        add_action('wp_initialize_site', [Installer::class, 'onNewSite'], 200);
        GenQueue::register([$this->runner, 'handleJob']);
        (new Privacy($this->drafts))->register();

        if (is_admin()) {
            (new Notices())->register();
            (new SettingsPage())->register();
            (new SiteHealth())->register();
            $this->commentsScreen->register();
            $this->draftsScreen->register();
        }
        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('WP_CLI')) {
            \WP_CLI::add_command('comment-draft-replies', new Command($this->drafts, $this->runner, $this->backfill, $this->housekeeping, $this->publisher));
        }
    }

    /**
     * @param array<string, mixed> $schedules
     * @return array<string, mixed>
     */
    public static function schedules(array $schedules): array
    {
        $schedules[Runner::SCHEDULE] = ['interval' => 300, 'display' => 'Every five minutes (comment reply drafts)'];

        return $schedules;
    }

    public function cronRun(): void
    {
        update_option(SiteHealth::LAST_RUN_OPTION, Clock::now(), false);
        $this->runner->run();
    }

    public function cronHousekeeping(): void
    {
        $this->housekeeping->run();
    }
}
