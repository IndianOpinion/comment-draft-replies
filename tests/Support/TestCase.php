<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Tests\Support;

use IndianOpinion\CommentDraftReplies\Clock;
use IndianOpinion\CommentDraftReplies\Draft;
use IndianOpinion\CommentDraftReplies\Generation\Runner;
use IndianOpinion\CommentDraftReplies\Installer;
use IndianOpinion\CommentDraftReplies\Intake;
use IndianOpinion\CommentDraftReplies\Plugin;
use IndianOpinion\CommentDraftReplies\Settings;

/**
 * Base for every test: fresh tables, users, one published post, frozen
 * clock, and the plugin booted.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** 2026-11-14 12:00:00 UTC. */
    public const NOW = 1794657600;
    public const ADMIN = 1;
    public const EDITOR = 2;
    public const READER = 3;
    public const MODERATOR = 4;
    public const SITE_ACCOUNT = 5;
    public const AUTHOR = 6;
    public const POST = 10;

    protected Plugin $plugin;
    protected FakeModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        \WPStub::reset();
        \WP_CLI::$out = [];
        Clock::freeze(self::NOW);
        Runner::$libraryOverride = null;
        Intake::$publishing = 0;
        foreach ([1, 2] as $blog) {
            self::resetTables($blog);
        }
        $this->users();
        $this->post(self::POST);
        Plugin::reset();
        $this->plugin = Plugin::instance();
        $this->plugin->boot();
        Installer::installSite();
        $this->settings(['model' => 'test/model', 'api_key' => 'test-key-123456']);
        update_option('thread_comments', '1');
        update_option('thread_comments_depth', '5');
        $this->model = new FakeModel();
        add_filter('cdreplies_model_caller', [$this->model, 'answer'], 10, 2);
    }

    protected function tearDown(): void
    {
        Clock::freeze(null);
        parent::tearDown();
    }

    public static function resetTables(int $blog): void
    {
        global $wpdb;
        $p = $blog === 1 ? 'wp_' : 'wp_' . $blog . '_';
        $db = $wpdb->raw();
        $db->query("DROP TABLE IF EXISTS {$p}cdreplies_drafts");
        foreach (['options', 'comments', 'commentmeta'] as $t) {
            $db->query("TRUNCATE TABLE {$p}{$t}");
        }
        $db->query("CREATE TABLE IF NOT EXISTS {$p}options (option_id bigint unsigned NOT NULL AUTO_INCREMENT, option_name varchar(191) NOT NULL, option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT 'yes', PRIMARY KEY (option_id), UNIQUE KEY option_name (option_name)) DEFAULT CHARSET=utf8mb4");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}comments (comment_ID bigint unsigned NOT NULL AUTO_INCREMENT, comment_post_ID bigint unsigned NOT NULL DEFAULT 0, comment_author tinytext NOT NULL, comment_author_email varchar(100) NOT NULL DEFAULT '', comment_author_url varchar(200) NOT NULL DEFAULT '', comment_author_IP varchar(100) NOT NULL DEFAULT '', comment_date datetime NOT NULL, comment_date_gmt datetime NOT NULL, comment_content text NOT NULL, comment_karma int NOT NULL DEFAULT 0, comment_approved varchar(20) NOT NULL DEFAULT '1', comment_agent varchar(255) NOT NULL DEFAULT '', comment_type varchar(20) NOT NULL DEFAULT 'comment', comment_parent bigint unsigned NOT NULL DEFAULT 0, user_id bigint unsigned NOT NULL DEFAULT 0, PRIMARY KEY (comment_ID)) DEFAULT CHARSET=utf8mb4");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}commentmeta (meta_id bigint unsigned NOT NULL AUTO_INCREMENT, comment_id bigint unsigned NOT NULL DEFAULT 0, meta_key varchar(255) NULL, meta_value longtext NULL, PRIMARY KEY (meta_id), KEY comment_id (comment_id)) DEFAULT CHARSET=utf8mb4");
    }

    private function users(): void
    {
        $add = static function (int $id, string $name, array $caps): void {
            \WPStub::$users[$id] = new \WP_User(['ID' => $id, 'display_name' => $name, 'user_email' => strtolower(str_replace(' ', '', $name)) . '@example.org', 'user_url' => '', 'caps' => $caps]);
        };
        $add(self::ADMIN, 'Admin', ['administrator']);
        $add(self::EDITOR, 'Edna Editor', ['moderate_comments', 'edit_posts', 'edit_others_posts', 'manage_options']);
        $add(self::READER, 'Rita Reader', ['read']);
        $add(self::MODERATOR, 'Mo Moderator', ['moderate_comments']);
        $add(self::SITE_ACCOUNT, 'Site Editors', ['edit_posts']);
        $add(self::AUTHOR, 'Arun Author', ['edit_posts']);
    }

    /** @param array<string, mixed> $over */
    protected function post(int $id, array $over = []): \WP_Post
    {
        $p = new \WP_Post($over + [
            'ID' => $id,
            'post_author' => (string) self::AUTHOR,
            'post_title' => 'Rail fares rise in March',
            'post_content' => '<p>Rail fares will rise by three per cent in March, the operator said on Tuesday.</p>',
            'post_excerpt' => '',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_password' => '',
            'comment_status' => 'open',
        ]);
        \WPStub::$posts[$id] = $p;

        return $p;
    }

    /** @param array<string, mixed> $values */
    protected function settings(array $values): void
    {
        $s = array_merge(Settings::all(), $values);
        update_option(Settings::OPTION, $s);
    }

    /**
     * Insert a comment without firing comment_post.
     *
     * @param array<string, mixed> $over
     */
    protected function insertComment(array $over = []): int
    {
        $id = wp_insert_comment($over + [
            'comment_post_ID' => self::POST,
            'comment_author' => 'Priya',
            'comment_author_email' => 'priya@example.net',
            'comment_content' => 'Does the rise apply to season tickets as well?',
            'comment_approved' => '0',
        ]);
        self::assertIsInt($id);

        return $id;
    }

    /**
     * Insert a comment and fire comment_post, as core does on submission.
     *
     * @param array<string, mixed> $over
     */
    protected function postComment(array $over = []): int
    {
        $id = $this->insertComment($over);
        $c = get_comment($id);
        do_action('comment_post', $id, $c instanceof \WP_Comment ? $c->comment_approved : '0', []);

        return $id;
    }

    protected function draftFor(int $commentId): ?Draft
    {
        return $this->plugin->drafts->findByComment($commentId);
    }

    /** @return list<string> */
    protected function skipReasons(int $commentId): array
    {
        $meta = get_comment_meta($commentId, Intake::SKIP_META, true);
        if (!is_string($meta) || $meta === '') {
            return [];
        }
        $d = json_decode($meta, true);

        return is_array($d) ? array_values($d['reasons']) : [];
    }

    /** Queue and generate a ready draft for a fresh held comment. */
    protected function readyDraft(array $over = [], ?string $reply = null): Draft
    {
        $id = $this->postComment($over);
        $this->model->push(FakeModel::reply($reply ?? 'Yes, season tickets rise by the same three per cent.'));
        $this->plugin->runner->run();
        $d = $this->draftFor($id);
        self::assertNotNull($d);
        self::assertSame('ready', $d->status(), 'draft should be ready, last reason ' . (string) $d->lastReason());

        return $d;
    }

    protected function countComments(): int
    {
        global $wpdb;

        return (int) \WPStub::internal(static fn () => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments}"));
    }

    /** Log in a browser user: current user, logged-in cookie. */
    protected function loginBrowser(int $userId): void
    {
        wp_set_current_user($userId);
        \WPStub::$cookieUser = $userId;
    }

    /**
     * Dispatch a REST request through the registered routes.
     *
     * @param array<string, mixed> $params
     * @param array<string, string> $headers
     */
    protected function rest(string $method, string $path, array $params = [], array $headers = []): \WP_REST_Response|\WP_Error
    {
        if (\WPStub::$routes === []) {
            do_action('rest_api_init');
        }
        foreach (\WPStub::$routes as $pattern => $route) {
            if (!preg_match('#^' . $pattern . '$#', $path, $m) || $route['methods'] !== $method) {
                continue;
            }
            $req = new \WP_REST_Request($method, $path);
            foreach ($route['args'] ?? [] as $name => $spec) {
                if (array_key_exists('default', $spec)) {
                    $req->set_param($name, $spec['default']);
                }
            }
            foreach ($m as $k => $v) {
                if (is_string($k)) {
                    $req->set_param($k, (int) $v);
                }
            }
            foreach ($params as $k => $v) {
                $req->set_param($k, $v);
            }
            foreach ($headers as $k => $v) {
                $req->set_header($k, $v);
            }
            $ok = ($route['permission_callback'])($req);
            if ($ok !== true) {
                return $ok instanceof \WP_Error ? $ok : new \WP_Error('rest_forbidden', 'Forbidden', ['status' => 403]);
            }

            return ($route['callback'])($req);
        }

        return new \WP_Error('rest_no_route', 'No route', ['status' => 404]);
    }

    /** @return array<string, string> headers with a valid action nonce */
    protected function nonceHeader(): array
    {
        return ['X-Cdreplies-Nonce' => wp_create_nonce('cdreplies_action')];
    }

    protected static function errorStatus(mixed $r): int
    {
        if (!$r instanceof \WP_Error) {
            return 200;
        }
        $data = $r->get_error_data();

        return is_array($data) ? (int) ($data['status'] ?? 0) : (int) $data;
    }
}
