<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Tests\Integration;

use IndianOpinion\CommentDraftReplies\Admin\SettingsPage;
use IndianOpinion\CommentDraftReplies\Admin\SiteHealth;
use IndianOpinion\CommentDraftReplies\Settings;
use IndianOpinion\CommentDraftReplies\Tests\Support\TestCase;

/** Section 7 and section 11: settings screen and Site Health. */
final class AdminTest extends TestCase
{
    public function testSettingsSaveRefusesBadValuesAndKeepsOldOnes(): void
    {
        wp_set_current_user(self::ADMIN);
        $page = new SettingsPage();
        $saved = $page->sanitize(['daily_cap' => 'lots', 'model' => 'good/model', 'skip_links' => '1']);
        self::assertSame(30, $saved['daily_cap']);
        self::assertSame('good/model', $saved['model']);
        self::assertCount(1, \WPStub::$settingsErrors);

        wp_set_current_user(self::READER);
        $kept = $page->sanitize(['daily_cap' => '5']);
        self::assertSame(30, $kept['daily_cap']);
    }

    public function testSettingsScreenNeverPrintsTheKey(): void
    {
        $this->settings(['api_key' => 'sk-very-secret-9876', 'voice' => '<script>x</script>Plain.']);
        wp_set_current_user(self::ADMIN);
        ob_start();
        (new SettingsPage())->render();
        $html = (string) ob_get_clean();
        self::assertStringNotContainsString('very-secret', $html);
        self::assertStringContainsString('********9876', $html);
        self::assertStringNotContainsString('<script>x', $html);
        self::assertStringContainsString('sent to the provider', $html);
    }

    public function testSiteHealthReportsState(): void
    {
        $tests = (new SiteHealth())->tests([]);
        self::assertArrayHasKey('cdreplies', $tests['direct']);
        $r = (new SiteHealth())->result();
        self::assertSame('good', $r['status']);
        $this->settings(['kill' => true]);
        self::assertStringContainsString('Kill switch: on', (new SiteHealth())->result()['description']);
        remove_all_filters('cdreplies_model_caller');
        \IndianOpinion\CommentDraftReplies\Generation\Runner::$libraryOverride = false;
        self::assertSame('recommended', (new SiteHealth())->result()['status']);
        self::assertFalse(Settings::isDisabled(['kill' => false]));
    }
}
