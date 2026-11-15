<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Tests\Integration;

use IndianOpinion\CommentDraftReplies\Cli\Command;
use IndianOpinion\CommentDraftReplies\Generation\Runner;
use IndianOpinion\CommentDraftReplies\Housekeeping;
use IndianOpinion\CommentDraftReplies\Tests\Support\TestCase;

/** Cases 49 and 50: no automatic path, and core's own notifications only. */
final class SafetyTest extends TestCase
{
    public function testT49EveryRouteHasAPermissionCallbackAndPublishIsCookieOnly(): void
    {
        do_action('rest_api_init');
        self::assertNotEmpty(\WPStub::$routes);
        foreach (\WPStub::$routes as $path => $route) {
            self::assertIsCallable($route['permission_callback'] ?? null, $path);
        }
        $publishRoutes = array_filter(array_keys(\WPStub::$routes), static fn ($p) => str_contains($p, 'publish'));
        self::assertCount(1, $publishRoutes);

        $d = $this->readyDraft(['comment_approved' => '1']);
        // Application password: 401, nothing created.
        wp_set_current_user(self::EDITOR);
        \WPStub::$appPassword = 'uuid-1';
        \WPStub::$cookieUser = self::EDITOR;
        $r = $this->rest('POST', '/comment-draft-replies/v1/drafts/' . $d->id() . '/publish', [], $this->nonceHeader());
        self::assertSame('cdreplies_auth_method', $r->get_error_code());
        self::assertSame(401, self::errorStatus($r));

        // Any other non-cookie method (no logged-in cookie for this user): 401.
        \WPStub::$appPassword = null;
        \WPStub::$cookieUser = false;
        self::assertSame(401, self::errorStatus($this->rest('POST', '/comment-draft-replies/v1/drafts/' . $d->id() . '/publish', [], $this->nonceHeader())));
        \WPStub::$cookieUser = self::ADMIN;
        self::assertSame(401, self::errorStatus($this->rest('POST', '/comment-draft-replies/v1/drafts/' . $d->id() . '/publish', [], $this->nonceHeader())));
        self::assertSame(1, $this->countComments());
    }

    public function testT49NoCronCliOrPublicFunctionPublishes(): void
    {
        $d = $this->readyDraft(['comment_approved' => '1']);
        $before = $this->countComments();

        foreach ([Runner::CRON_HOOK, Runner::SAFETY_HOOK, Housekeeping::HOOK] as $hook) {
            do_action($hook);
        }

        $methods = array_map(static fn (\ReflectionMethod $m) => $m->getName(), (new \ReflectionClass(Command::class))->getMethods(\ReflectionMethod::IS_PUBLIC));
        foreach ($methods as $m) {
            self::assertStringNotContainsStringIgnoringCase('publish', $m);
            self::assertStringNotContainsStringIgnoringCase('approve', $m);
        }

        $functions = array_filter(
            get_defined_functions()['user'],
            static fn ($f) => str_starts_with($f, 'cdreplies_') && str_ends_with((string) (new \ReflectionFunction($f))->getFileName(), 'src/functions.php')
        );
        self::assertCount(6, $functions);
        self::assertNotEmpty($functions);
        foreach ($functions as $f) {
            self::assertStringNotContainsString('publish', $f);
            $ref = new \ReflectionFunction($f);
            $args = $ref->getNumberOfRequiredParameters() > 0 ? [$d->commentId()] : [];
            $f(...$args);
        }

        $settingsKeys = array_keys(\IndianOpinion\CommentDraftReplies\Settings::defaults());
        foreach ($settingsKeys as $k) {
            self::assertDoesNotMatchRegularExpression('/auto|trusted|publish_without/', $k);
        }

        self::assertSame($before, $this->countComments());
        self::assertSame('ready', $this->draftFor($d->commentId())->status());
    }

    public function testT50PublishFiresCoreHooksOnceAndNoMail(): void
    {
        $d = $this->readyDraft(['comment_approved' => '1']);
        $fired = \WPStub::$actionsFired['comment_post'] ?? 0;
        $this->loginBrowser(self::EDITOR);
        $this->rest('POST', '/comment-draft-replies/v1/drafts/' . $d->id() . '/publish', [], $this->nonceHeader());
        self::assertSame($fired + 1, \WPStub::$actionsFired['comment_post']);
        self::assertSame([], \WPStub::$mail);
        self::assertSame(1, did_action('cdreplies_published'));
    }

    public function testSourceHasNoMailEvalShellOrSuppression(): void
    {
        $src = '';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src'));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $src .= (string) file_get_contents($file->getPathname());
            }
        }
        self::assertDoesNotMatchRegularExpression('/\bwp_mail\s*\(|\beval\s*\(|\bshell_exec\s*\(|\bexec\s*\(|\bsystem\s*\(|\bpassthru\s*\(|(?<![\w\'"])@\$|(?<![\w\'"])@[a-z_]+\(/', $src);
    }
}
