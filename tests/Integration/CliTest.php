<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Tests\Integration;

use IndianOpinion\CommentDraftReplies\Cli\Command;
use IndianOpinion\CommentDraftReplies\Hold;
use IndianOpinion\CommentDraftReplies\Settings;
use IndianOpinion\CommentDraftReplies\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/** Section 5.6: the WP-CLI commands and their exit codes. */
final class CliTest extends TestCase
{
    private function cli(): Command
    {
        $p = $this->plugin;

        return new Command($p->drafts, $p->runner, $p->backfill, $p->housekeeping, $p->publisher);
    }

    private function exitCode(callable $fn): int
    {
        try {
            $fn();
        } catch (\WP_CLI_Halt $e) {
            return $e->getCode();
        }

        return 0;
    }

    public function testStatusListShowNeverPrintTheKey(): void
    {
        $this->settings(['api_key' => 'sk-secretvalue-0000']);
        $d = $this->readyDraft([], 'Text only with flag.');
        $cli = $this->cli();
        $cli->status();
        $cli->list([], ['status' => 'ready']);
        $cli->show([(string) $d->id()]);
        $out = implode("\n", \WP_CLI::$out);
        self::assertStringContainsString('Kill switch: off', $out);
        self::assertStringContainsString('Calls today: 1 of 30', $out);
        self::assertStringNotContainsString('secretvalue', $out);
        self::assertStringNotContainsString('Text only with flag.', implode("\n", array_slice(\WP_CLI::$out, 0, 12)));
        \WP_CLI::$out = [];
        $cli->list([], ['with-text' => '', 'format' => 'json']);
        self::assertStringContainsString('Text only with flag.', implode("\n", \WP_CLI::$out));
    }

    public function testRunExitCodes(): void
    {
        $this->postComment();
        $this->settings(['kill' => true]);
        self::assertSame(Command::EXIT_DISABLED, $this->exitCode(fn () => $this->cli()->run()));
        $this->settings(['kill' => false, 'daily_cap' => 0]);
        self::assertSame(Command::EXIT_CAP, $this->exitCode(fn () => $this->cli()->run()));
        $this->settings(['daily_cap' => 5]);
        self::assertSame(0, $this->exitCode(fn () => $this->cli()->run([], ['max' => '1'])));
        self::assertSame(1, $this->plugin->drafts->count(['status' => 'ready']));
    }

    public function testBackfillDryRunAndExitCodes(): void
    {
        $this->insertComment();
        $cli = $this->cli();
        self::assertSame(0, $this->exitCode(fn () => $cli->backfill([], ['since' => '2026-11-13', 'dry-run' => ''])));
        self::assertSame(0, $this->plugin->drafts->count([]));
        self::assertContains('queued     1', \WP_CLI::$out);
        self::assertSame(1, $this->exitCode(fn () => $cli->backfill([], ['since' => 'not a date'])));
        $this->settings(['kill' => true]);
        self::assertSame(Command::EXIT_DISABLED, $this->exitCode(fn () => $cli->backfill([], ['since' => '2026-11-13'])));
    }

    public function testKillCapHoldDiscardPurge(): void
    {
        $cli = $this->cli();
        $cli->kill(['on']);
        self::assertTrue(Settings::isDisabled(Settings::fresh()));
        $cli->kill(['off']);
        self::assertFalse(Settings::isDisabled(Settings::fresh()));
        $cli->cap(['7']);
        self::assertSame(7, Settings::dailyCap(Settings::fresh()));
        self::assertSame(1, $this->exitCode(fn () => $cli->cap(['x'])));

        Hold::start('auth');
        $cli->hold(['clear']);
        self::assertNull(Hold::active());

        $id = $this->postComment();
        wp_set_current_user(self::EDITOR);
        $cli->discard([(string) $this->draftFor($id)->id()], ['reason' => 'other']);
        self::assertSame('discarded', $this->draftFor($id)->status());
        self::assertSame(1, $this->exitCode(fn () => $cli->discard(['99999'])));

        $cli->purge([], ['dry-run' => '']);
        self::assertContains('Draft rows: 0', \WP_CLI::$out);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCommandIsRegisteredUnderWpCli(): void
    {
        define('WP_CLI', true);
        \IndianOpinion\CommentDraftReplies\Plugin::reset();
        \IndianOpinion\CommentDraftReplies\Plugin::instance()->boot();
        self::assertInstanceOf(Command::class, \WP_CLI::$commands['comment-draft-replies']);
        self::assertFalse(method_exists(Command::class, 'publish'));
    }
}
