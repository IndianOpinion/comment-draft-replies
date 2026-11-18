<?php

declare(strict_types=1);

// Hooks.
function add_filter(string $tag, callable $cb, int $priority = 10, int $args = 1): bool
{
    WPStub::$hooks[$tag][$priority][] = [$cb, $args];
    return true;
}
function add_action(string $tag, callable $cb, int $priority = 10, int $args = 1): bool
{
    return add_filter($tag, $cb, $priority, $args);
}
function has_filter(string $tag, mixed $cb = false): bool|int
{
    if (empty(WPStub::$hooks[$tag])) {
        return false;
    }
    if ($cb === false) {
        return true;
    }
    foreach (WPStub::$hooks[$tag] as $p => $list) {
        foreach ($list as [$c]) {
            if ($c === $cb) {
                return $p;
            }
        }
    }
    return false;
}
function has_action(string $tag, mixed $cb = false): bool|int
{
    return has_filter($tag, $cb);
}
function remove_all_filters(string $tag): bool
{
    unset(WPStub::$hooks[$tag]);
    return true;
}
function apply_filters(string $tag, mixed $value, mixed ...$args): mixed
{
    if (empty(WPStub::$hooks[$tag])) {
        return $value;
    }
    $hooks = WPStub::$hooks[$tag];
    ksort($hooks);
    foreach ($hooks as $list) {
        foreach ($list as [$cb, $n]) {
            $value = $cb(...array_slice(array_merge([$value], $args), 0, $n));
        }
    }
    return $value;
}
function do_action(string $tag, mixed ...$args): void
{
    WPStub::$actionsFired[$tag] = (WPStub::$actionsFired[$tag] ?? 0) + 1;
    if (empty(WPStub::$hooks[$tag])) {
        return;
    }
    $hooks = WPStub::$hooks[$tag];
    ksort($hooks);
    foreach ($hooks as $list) {
        foreach ($list as [$cb, $n]) {
            $cb(...array_slice($args, 0, $n));
        }
    }
}
function did_action(string $tag): int
{
    return WPStub::$actionsFired[$tag] ?? 0;
}

// Serialisation and plain helpers.
function is_serialized(mixed $data): bool
{
    return is_string($data) && preg_match('/^(a:\d+:\{|s:\d+:"|i:-?\d+;|b:[01];|d:[0-9.eE+\-]+;|N;)/', $data) === 1;
}
function maybe_serialize(mixed $data): mixed
{
    return is_array($data) || is_object($data) ? serialize($data) : $data;
}
function maybe_unserialize(mixed $data): mixed
{
    return is_serialized($data) ? unserialize((string) $data) : $data;
}
function wp_json_encode(mixed $data, int $flags = 0): string|false
{
    return json_encode($data, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function absint(mixed $v): int
{
    return abs((int) $v);
}
function wp_unslash(mixed $v): mixed
{
    return is_array($v) ? array_map('wp_unslash', $v) : (is_string($v) ? stripslashes($v) : $v);
}
function wp_slash(mixed $v): mixed
{
    return is_array($v) ? array_map('wp_slash', $v) : (is_string($v) ? addslashes($v) : $v);
}
function sanitize_text_field(string $s): string
{
    return trim((string) preg_replace('/\s+/', ' ', strip_tags($s)));
}
function sanitize_textarea_field(string $s): string
{
    return trim(strip_tags($s));
}
function sanitize_key(string $s): string
{
    return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($s));
}
function esc_html(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function esc_attr(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function esc_textarea(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function esc_url(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function esc_url_raw(string $s): string
{
    return $s;
}
function __(string $s, string $d = 'default'): string
{
    return $s;
}
function esc_html__(string $s, string $d = 'default'): string
{
    return esc_html($s);
}
function esc_attr__(string $s, string $d = 'default'): string
{
    return esc_attr($s);
}
function number_format_i18n(float|int $n): string
{
    return number_format((float) $n);
}
function human_time_diff(int $from, int $to = 0): string
{
    return (string) max(0, intdiv(abs($to - $from), 60)) . ' mins';
}
function add_query_arg(array $args, string $url): string
{
    $args = array_filter($args, static fn ($v) => $v !== null);
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
}
function admin_url(string $path = ''): string
{
    return 'https://example.org/wp-admin/' . $path;
}
function rest_url(string $path = ''): string
{
    return 'https://example.org/wp-json/' . $path;
}
function home_url(string $path = ''): string
{
    return 'https://example.org' . $path;
}
function plugins_url(string $path = '', string $file = ''): string
{
    return 'https://example.org/wp-content/plugins/comment-draft-replies/' . $path;
}
function plugin_basename(string $file): string
{
    return 'comment-draft-replies/' . basename($file);
}
function get_bloginfo(string $what = ''): string
{
    return match ($what) {
        'name' => 'Example News',
        'language' => 'en-GB',
        default => '',
    };
}
function wp_timezone(): DateTimeZone
{
    return new DateTimeZone(WPStub::$timezone);
}
function wp_salt(string $scheme = 'auth'): string
{
    return 'test-salt-' . $scheme;
}
function is_admin(): bool
{
    return WPStub::$isAdmin;
}
function wp_die(string $message = '', mixed $title = '', mixed $args = []): never
{
    throw new WPDieException($message);
}
function wp_safe_redirect(string $url): never
{
    throw new WPRedirectException($url);
}
function wp_mail(mixed ...$args): bool
{
    WPStub::$mail[] = $args;
    return true;
}
function wp_remote_post(string $url, array $args = []): array|WP_Error
{
    WPStub::$http[] = [$url, $args];
    return WPStub::$httpResponse ?? ['response' => ['code' => 500], 'body' => ''];
}
function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}
function wp_remote_retrieve_response_code(mixed $r): int|string
{
    return is_array($r) ? (int) ($r['response']['code'] ?? 0) : '';
}
function wp_remote_retrieve_body(mixed $r): string
{
    return is_array($r) ? (string) ($r['body'] ?? '') : '';
}
function wp_remote_request(string $url, array $args = []): array
{
    return wp_remote_post($url, $args);
}
function get_the_title(int $id): string
{
    return isset(WPStub::$posts[$id]) ? WPStub::$posts[$id]->post_title : '';
}
function wp_kses_allowed_html(string $context = ''): array
{
    return ['a' => ['href' => true, 'title' => true], 'b' => [], 'strong' => [], 'em' => [], 'i' => [], 'code' => [], 'blockquote' => [], 'q' => [], 'cite' => [], 'del' => [], 'abbr' => []];
}
function wp_kses(string $text, array $allowed): string
{
    $tags = implode('', array_map(static fn ($t) => '<' . $t . '>', array_keys($allowed)));
    $text = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $text);
    $text = strip_tags($text, $tags);
    // Drop attributes that are not allowed, crudely: event handlers.
    return (string) preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|\S+)/i', '', $text);
}

// Users and capabilities.
function get_userdata(int $id): WP_User|false
{
    return WPStub::$users[$id] ?? false;
}
function get_current_user_id(): int
{
    return WPStub::$currentUser;
}
function wp_set_current_user(int $id): void
{
    WPStub::$currentUser = $id;
}
function is_user_logged_in(): bool
{
    return WPStub::$currentUser > 0;
}
function user_can(mixed $user, string $cap, mixed ...$args): bool
{
    $u = $user instanceof WP_User ? $user : (WPStub::$users[(int) $user] ?? null);
    if (!$u instanceof WP_User) {
        return false;
    }
    if (in_array('administrator', $u->caps, true)) {
        return true;
    }
    if ($cap === 'edit_post') {
        $pid = (int) ($args[0] ?? 0);
        $post = WPStub::$posts[$pid] ?? null;
        if (in_array('edit_post:' . $pid, $u->caps, true)) {
            return true;
        }
        if ($post && (int) $post->post_author === $u->ID && in_array('edit_posts', $u->caps, true)) {
            return true;
        }
        return in_array('edit_others_posts', $u->caps, true);
    }
    return in_array($cap, $u->caps, true);
}
function current_user_can(string $cap, mixed ...$args): bool
{
    return user_can(WPStub::$currentUser, $cap, ...$args);
}
function wp_create_nonce(string $action = ''): string
{
    return substr(md5('nonce|' . $action . '|' . WPStub::$currentUser), 0, 10);
}
function wp_verify_nonce(string $nonce, string $action = ''): int|false
{
    return hash_equals(wp_create_nonce($action), $nonce) ? 1 : false;
}
function check_admin_referer(string $action = '', string $arg = '_wpnonce'): int
{
    $n = (string) (WPStub::$request[$arg] ?? '');
    if (wp_verify_nonce($n, $action) === false) {
        wp_die('The link you followed has expired.');
    }
    return 1;
}
function wp_nonce_field(string $action = ''): void
{
    echo '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce($action)) . '">';
}
function wp_validate_auth_cookie(string $cookie = '', string $scheme = ''): int|false
{
    return WPStub::$cookieUser;
}
function rest_get_authenticated_app_password(): ?string
{
    return WPStub::$appPassword;
}
function rest_authorization_required_code(): int
{
    return is_user_logged_in() ? 403 : 401;
}
function register_rest_route(string $ns, string $route, array $args): bool
{
    WPStub::$routes['/' . $ns . $route] = $args;
    return true;
}

// Options (in the test database, never cached).
function get_option(string $name, mixed $default = false): mixed
{
    global $wpdb;
    return WPStub::internal(static function () use ($wpdb, $name, $default) {
        $v = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        return $v === null ? $default : maybe_unserialize($v);
    });
}
function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    global $wpdb;
    return WPStub::internal(static function () use ($wpdb, $name, $value) {
        $v = maybe_serialize($value);
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'yes') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
            $name,
            is_scalar($v) ? (string) $v : ''
        ));
        return true;
    });
}
function add_option(string $name, mixed $value = '', string $deprecated = '', mixed $autoload = 'yes'): bool
{
    if (get_option($name, null) !== null) {
        return false;
    }
    return update_option($name, $value);
}
function delete_option(string $name): bool
{
    global $wpdb;
    return WPStub::internal(static fn () => (bool) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $name)));
}
function wp_cache_delete(string $key, string $group = ''): bool
{
    return true;
}

// Transients (in memory, per site).
function get_transient(string $key): mixed
{
    $t = WPStub::$transients[WPStub::$blog][$key] ?? null;
    if ($t === null || ($t[1] > 0 && $t[1] < \IndianOpinion\CommentDraftReplies\Clock::now())) {
        return false;
    }
    return $t[0];
}
function set_transient(string $key, mixed $value, int $ttl = 0): bool
{
    WPStub::$transients[WPStub::$blog][$key] = [$value, $ttl > 0 ? \IndianOpinion\CommentDraftReplies\Clock::now() + $ttl : 0];
    return true;
}
function delete_transient(string $key): bool
{
    unset(WPStub::$transients[WPStub::$blog][$key]);
    return true;
}

// Cron.
function wp_next_scheduled(string $hook): int|false
{
    foreach (WPStub::$cron as $e) {
        if ($e['hook'] === $hook && $e['blog'] === WPStub::$blog) {
            return $e['ts'];
        }
    }
    return false;
}
function wp_schedule_single_event(int $ts, string $hook): bool
{
    WPStub::$cron[] = ['hook' => $hook, 'ts' => $ts, 'recurrence' => null, 'blog' => WPStub::$blog];
    return true;
}
function wp_schedule_event(int $ts, string $recurrence, string $hook): bool
{
    WPStub::$cron[] = ['hook' => $hook, 'ts' => $ts, 'recurrence' => $recurrence, 'blog' => WPStub::$blog];
    return true;
}
function wp_clear_scheduled_hook(string $hook): int
{
    $before = count(WPStub::$cron);
    WPStub::$cron = array_values(array_filter(WPStub::$cron, static fn ($e) => !($e['hook'] === $hook && $e['blog'] === WPStub::$blog)));
    return $before - count(WPStub::$cron);
}

// Multisite.
function is_multisite(): bool
{
    return WPStub::$multisite;
}
function get_sites(array $args = []): array
{
    return [1, 2];
}
function get_current_blog_id(): int
{
    return WPStub::$blog;
}
function switch_to_blog(int $id): bool
{
    WPStub::$blogStack[] = WPStub::$blog;
    WPStub::$blog = $id;
    $GLOBALS['wpdb']->set_blog_id($id);
    return true;
}
function restore_current_blog(): bool
{
    $id = array_pop(WPStub::$blogStack) ?? 1;
    WPStub::$blog = $id;
    $GLOBALS['wpdb']->set_blog_id($id);
    return true;
}
function is_plugin_active_for_network(string $plugin): bool
{
    return true;
}

// Posts (in memory).
function get_post(mixed $post = null): ?WP_Post
{
    if ($post instanceof WP_Post) {
        return $post;
    }
    return WPStub::$posts[(int) $post] ?? null;
}
function comments_open(mixed $post): bool
{
    $p = get_post($post);
    return $p instanceof WP_Post && $p->comment_status === 'open';
}

// Comments (in the test database).
function get_comment(mixed $id): ?WP_Comment
{
    global $wpdb;
    if ($id instanceof WP_Comment) {
        $id = (int) $id->comment_ID;
    }
    return WPStub::internal(static function () use ($wpdb, $id) {
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->comments} WHERE comment_ID = %d", (int) $id), ARRAY_A);
        return is_array($row) ? new WP_Comment($row) : null;
    });
}
function wp_insert_comment(array $data): int|false
{
    global $wpdb;
    $now = gmdate('Y-m-d H:i:s', \IndianOpinion\CommentDraftReplies\Clock::now());
    $data += [
        'comment_post_ID' => 0, 'comment_author' => '', 'comment_author_email' => '', 'comment_author_url' => '',
        'comment_author_IP' => '127.0.0.1', 'comment_date' => $now, 'comment_date_gmt' => $now, 'comment_content' => '',
        'comment_karma' => 0, 'comment_approved' => '1', 'comment_agent' => 'test', 'comment_type' => 'comment',
        'comment_parent' => 0, 'user_id' => 0,
    ];
    $id = WPStub::internal(static function () use ($wpdb, $data) {
        $cols = array_keys($data);
        $sql = "INSERT INTO {$wpdb->comments} (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '%s')) . ')';
        $ok = $wpdb->query($wpdb->prepare($sql, ...array_map('strval', array_values($data))));
        return $ok === false ? false : $wpdb->insert_id;
    });
    if ($id !== false) {
        do_action('wp_insert_comment', $id, get_comment($id));
    }
    return $id;
}
function wp_new_comment(array $data, bool $wp_error = false): int|false|WP_Error
{
    $data = wp_unslash($data);
    global $wpdb;
    $dupe = WPStub::internal(static fn () => $wpdb->get_var($wpdb->prepare(
        "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID = %d AND comment_parent = %d AND comment_content = %s AND user_id = %d LIMIT 1",
        (int) $data['comment_post_ID'],
        (int) ($data['comment_parent'] ?? 0),
        (string) $data['comment_content'],
        (int) ($data['user_id'] ?? 0)
    )));
    if ($dupe !== null) {
        return $wp_error ? new WP_Error('comment_duplicate', 'Duplicate comment detected; it looks as though you have already said that!', 409) : false;
    }
    $approved = user_can((int) ($data['user_id'] ?? 0), 'moderate_comments') ? 1 : 0;
    $approved = apply_filters('pre_comment_approved', $approved, $data);
    if ($approved instanceof WP_Error) {
        return $wp_error ? $approved : false;
    }
    $data['comment_approved'] = (string) $approved;
    $data['comment_content'] = wp_kses((string) $data['comment_content'], wp_kses_allowed_html('pre_comment_content'));
    $id = wp_insert_comment($data);
    if ($id === false) {
        return $wp_error ? new WP_Error('db_insert_error', 'Could not insert comment.') : false;
    }
    do_action('comment_post', $id, $data['comment_approved'], $data);
    return $id;
}
function wp_update_comment(array $data): int
{
    global $wpdb;
    $id = (int) $data['comment_ID'];
    unset($data['comment_ID']);
    WPStub::internal(static function () use ($wpdb, $id, $data) {
        foreach ($data as $k => $v) {
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->comments} SET {$k} = %s WHERE comment_ID = %d", (string) $v, $id));
        }
    });
    do_action('edit_comment', $id, $data);
    return 1;
}
function wp_set_comment_status(mixed $comment, string $status, bool $wp_error = false): bool|WP_Error
{
    global $wpdb;
    $c = get_comment($comment);
    if (!$c) {
        return false;
    }
    if ($status === 'approve' && WPStub::$failApprove) {
        return $wp_error ? new WP_Error('db_update_error', 'Could not update comment status.') : false;
    }
    $map = ['approve' => '1', 'hold' => '0', 'spam' => 'spam', 'trash' => 'trash', '1' => '1', '0' => '0'];
    $new = $map[$status] ?? $status;
    $names = ['1' => 'approved', '0' => 'unapproved', 'spam' => 'spam', 'trash' => 'trash'];
    $old = $c->comment_approved;
    WPStub::internal(static fn () => $wpdb->query($wpdb->prepare("UPDATE {$wpdb->comments} SET comment_approved = %s WHERE comment_ID = %d", $new, (int) $c->comment_ID)));
    do_action('transition_comment_status', $names[$new] ?? $new, $names[$old] ?? $old, get_comment((int) $c->comment_ID));
    return true;
}
function wp_delete_comment(mixed $id, bool $force = false): bool
{
    global $wpdb;
    $c = get_comment($id);
    if (!$c) {
        return false;
    }
    do_action('delete_comment', (int) $c->comment_ID, $c);
    WPStub::internal(static function () use ($wpdb, $c) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->comments} WHERE comment_ID = %d", (int) $c->comment_ID));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->commentmeta} WHERE comment_id = %d", (int) $c->comment_ID));
    });
    do_action('deleted_comment', (int) $c->comment_ID, $c);
    return true;
}
function get_comment_meta(int $id, string $key = '', bool $single = false): mixed
{
    global $wpdb;
    $vals = WPStub::internal(static fn () => $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->commentmeta} WHERE comment_id = %d AND meta_key = %s ORDER BY meta_id", $id, $key)));
    if ($single) {
        return isset($vals[0]) ? maybe_unserialize($vals[0]) : '';
    }
    return array_map('maybe_unserialize', $vals);
}
function add_comment_meta(int $id, string $key, mixed $value, bool $unique = false): int|false
{
    global $wpdb;
    if ($unique && get_comment_meta($id, $key) !== []) {
        return false;
    }
    return WPStub::internal(static function () use ($wpdb, $id, $key, $value) {
        $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->commentmeta} (comment_id, meta_key, meta_value) VALUES (%d, %s, %s)", $id, $key, (string) maybe_serialize($value)));
        return $wpdb->insert_id;
    });
}
function update_comment_meta(int $id, string $key, mixed $value): bool
{
    delete_comment_meta($id, $key);
    return add_comment_meta($id, $key, $value) !== false;
}
function delete_comment_meta(int $id, string $key, mixed $value = ''): bool
{
    global $wpdb;
    return WPStub::internal(static fn () => (bool) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->commentmeta} WHERE comment_id = %d AND meta_key = %s", $id, $key)));
}
function delete_metadata(string $type, int $id, string $key, mixed $value = '', bool $all = false): bool
{
    global $wpdb;
    return WPStub::internal(static fn () => (bool) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->commentmeta} WHERE meta_key = %s", $key)));
}

// Schema.
function dbDelta(string $sql): array
{
    global $wpdb;
    // Like core at its worst: creates a missing table, never adds a column.
    WPStub::internal(static fn () => $wpdb->query((string) preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $sql)));
    return [];
}

// Admin bits used by the screens.
function add_settings_error(string $setting, string $code, string $message, string $type = 'error'): void
{
    WPStub::$settingsErrors[] = [$setting, $code, $message, $type];
}
function settings_errors(string $setting = ''): void
{
}
function register_setting(string $group, string $name, array $args = []): void
{
}
function settings_fields(string $group): void
{
}
function submit_button(): void
{
    echo '<button type="submit">Save</button>';
}
function add_comments_page(string $title, string $menu, string $cap, string $slug, callable $cb): string
{
    WPStub::$menus[] = ['comments', $title, $menu, $cap, $slug];
    return 'comments_page_' . $slug;
}
function add_options_page(string $title, string $menu, string $cap, string $slug, callable $cb): string
{
    WPStub::$menus[] = ['options', $title, $menu, $cap, $slug];
    return 'settings_page_' . $slug;
}
function wp_dropdown_users(array $args): string
{
    return '<select name="' . esc_attr((string) $args['name']) . '"></select>';
}
function wp_enqueue_style(mixed ...$a): void
{
}
function wp_enqueue_script(mixed ...$a): void
{
}
function wp_localize_script(mixed ...$a): bool
{
    return true;
}
function wp_add_privacy_policy_content(string $name, string $text): void
{
    WPStub::$policy[] = [$name, $text];
}
function __return_true(): bool
{
    return true;
}
function __return_false(): bool
{
    return false;
}
function wp_generate_uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
