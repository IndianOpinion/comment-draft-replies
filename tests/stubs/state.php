<?php

declare(strict_types=1);

/**
 * Mutable state behind the WordPress stubs. Reset before every test.
 */
final class WPStub
{
    public static int $internal = 0;
    public static bool $echoErrors = true;
    /** @var array<string, array<int, list<array{0:callable,1:int}>>> */
    public static array $hooks = [];
    /** @var array<string, int> */
    public static array $actionsFired = [];
    /** @var array<int, WP_Post> */
    public static array $posts = [];
    /** @var array<int, WP_User> */
    public static array $users = [];
    public static int $currentUser = 0;
    public static int|false $cookieUser = false;
    public static ?string $appPassword = null;
    public static bool $isAdmin = false;
    public static bool $multisite = false;
    /** @var array<int, array<string, array{0:mixed,1:int}>> */
    public static array $transients = [];
    /** @var list<array{hook:string,ts:int,recurrence:?string}> */
    public static array $cron = [];
    /** @var list<array<mixed>> */
    public static array $mail = [];
    /** @var list<array<mixed>> */
    public static array $http = [];
    /** @var array<string, mixed>|WP_Error|null */
    public static array|WP_Error|null $httpResponse = null;
    /** @var array<string, array<string, mixed>> */
    public static array $routes = [];
    /** @var list<array<mixed>> */
    public static array $settingsErrors = [];
    /** @var list<array<mixed>> */
    public static array $menus = [];
    /** @var list<array<mixed>> */
    public static array $policy = [];
    public static string $timezone = 'UTC';
    /** When set, wp_set_comment_status(..., 'approve') fails. */
    public static bool $failApprove = false;
    /** @var array<string, mixed> */
    public static array $request = [];
    public static int $blog = 1;
    /** @var list<int> */
    public static array $blogStack = [];

    public static function reset(): void
    {
        self::$hooks = [];
        self::$actionsFired = [];
        self::$posts = [];
        self::$users = [];
        self::$currentUser = 0;
        self::$cookieUser = false;
        self::$appPassword = null;
        self::$isAdmin = false;
        self::$multisite = false;
        self::$transients = [];
        self::$cron = [];
        self::$mail = [];
        self::$http = [];
        self::$httpResponse = null;
        self::$routes = [];
        self::$settingsErrors = [];
        self::$menus = [];
        self::$policy = [];
        self::$timezone = 'UTC';
        self::$failApprove = false;
        self::$request = [];
        self::$blog = 1;
        self::$blogStack = [];
        $GLOBALS['wpdb']->set_blog_id(1);
    }

    /**
     * Run core-stub database work without counting it as plugin queries.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function internal(callable $fn): mixed
    {
        self::$internal++;
        try {
            return $fn();
        } finally {
            self::$internal--;
        }
    }
}
