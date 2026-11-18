<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Admin;

use IndianOpinion\CommentDraftReplies\DailyCap;
use IndianOpinion\CommentDraftReplies\Draft;
use IndianOpinion\CommentDraftReplies\DraftRepository;
use IndianOpinion\CommentDraftReplies\Hold;
use IndianOpinion\CommentDraftReplies\Review\Publisher;
use IndianOpinion\CommentDraftReplies\Settings;
use IndianOpinion\CommentDraftReplies\Text;

/**
 * The Reply drafts screen under Comments (case 37) and bulk discard.
 */
final class DraftsScreen
{
    public const SLUG = 'cdreplies-drafts';
    public const COUNT_TRANSIENT = 'cdreplies_ready_count';
    public const BULK_ACTION = 'cdreplies_bulk_discard';

    public function __construct(private readonly DraftRepository $drafts, private readonly Publisher $publisher)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_' . self::BULK_ACTION, [$this, 'bulkDiscard']);
    }

    public function readyCount(): int
    {
        $cached = get_transient(self::COUNT_TRANSIENT);
        if ($cached !== false && is_numeric($cached)) {
            return (int) $cached;
        }
        $n = $this->drafts->count(['status' => 'ready']);
        set_transient(self::COUNT_TRANSIENT, $n, 60);

        return $n;
    }

    public function menu(): void
    {
        $n = $this->readyCount();
        $label = __('Reply drafts', 'comment-draft-replies');
        if ($n > 0) {
            $label .= ' <span class="awaiting-mod count-' . (int) $n . '"><span class="pending-count">' . esc_html(number_format_i18n($n)) . '</span></span>';
        }
        $hook = add_comments_page(__('Reply drafts', 'comment-draft-replies'), $label, Publisher::reviewCapability(), self::SLUG, [$this, 'render']);
        if (is_string($hook)) {
            add_action('load-' . $hook, static function (): void {
                add_action('admin_enqueue_scripts', [Assets::class, 'enqueue']);
            });
        }
    }

    /**
     * Render the screen. Query values come from $_GET and are validated.
     *
     * @param array<string, mixed>|null $query
     */
    public function render(?array $query = null): void
    {
        if (!current_user_can(Publisher::reviewCapability())) {
            wp_die(esc_html__('Sorry, you cannot review reply drafts.', 'comment-draft-replies'), 403);
        }
        $q = $query ?? wp_unslash($_GET);
        $status = isset($q['status']) && is_string($q['status']) ? sanitize_key($q['status']) : 'ready';
        if ($status !== 'all' && !in_array($status, Draft::STATUSES, true)) {
            $status = 'ready';
        }
        $post = isset($q['post']) && is_numeric($q['post']) ? absint($q['post']) : 0;
        $page = isset($q['paged']) && is_numeric($q['paged']) ? max(1, absint($q['paged'])) : 1;
        $filters = ['status' => $status === 'all' ? '' : $status, 'post' => $post];

        $counts = $this->drafts->countsByStatus();
        $s = Settings::fresh();
        echo '<div class="wrap"><h1>' . esc_html__('Reply drafts', 'comment-draft-replies') . '</h1>';
        if (Settings::isDisabled($s)) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('The kill switch is on: no new drafts are written. Existing drafts can still be reviewed.', 'comment-draft-replies') . '</p></div>';
        }
        if (Hold::active() !== null) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Generation is on hold after a provider account error.', 'comment-draft-replies') . '</p></div>';
        }
        if (DailyCap::remaining(Settings::dailyCap($s)) === 0) {
            echo '<div class="notice notice-info"><p>' . esc_html__('Today\'s generation cap is reached.', 'comment-draft-replies') . '</p></div>';
        }

        echo '<ul class="subsubsub">';
        $links = [];
        foreach (array_merge(['all'], Draft::STATUSES) as $st) {
            $n = $st === 'all' ? array_sum($counts) : ($counts[$st] ?? 0);
            $url = add_query_arg(['page' => self::SLUG, 'status' => $st, 'post' => $post ?: null], admin_url('edit-comments.php'));
            $links[] = '<li><a href="' . esc_url($url) . '"' . ($st === $status ? ' class="current" aria-current="page"' : '') . '>' . esc_html($st) . ' <span class="count">(' . (int) $n . ')</span></a></li>';
        }
        echo implode(' | ', $links) . '</ul>';

        $rows = $this->drafts->list($filters, $page, 20);
        $userId = get_current_user_id();
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::BULK_ACTION) . '">';
        wp_nonce_field(self::BULK_ACTION);
        echo '<table class="widefat striped cdreplies-table"><thead><tr><td class="check-column"></td><th>' . esc_html__('Comment', 'comment-draft-replies') . '</th><th>' . esc_html__('Post', 'comment-draft-replies') . '</th><th>' . esc_html__('Author', 'comment-draft-replies') . '</th><th>' . esc_html__('Draft', 'comment-draft-replies') . '</th><th>' . esc_html__('Flags', 'comment-draft-replies') . '</th><th>' . esc_html__('Status', 'comment-draft-replies') . '</th><th>' . esc_html__('Age', 'comment-draft-replies') . '</th></tr></thead><tbody>';
        foreach ($rows as $d) {
            if (!Publisher::canReview($userId, $d->postId())) {
                continue;
            }
            $comment = get_comment($d->commentId());
            $excerpt = $comment instanceof \WP_Comment ? Text::cut(Text::plain((string) $comment->comment_content), 120) : '';
            $author = $comment instanceof \WP_Comment ? (string) $comment->comment_author : '';
            echo '<tr class="cdreplies-row" data-draft="' . esc_attr((string) $d->id()) . '">';
            echo '<th class="check-column"><input type="checkbox" name="ids[]" value="' . esc_attr((string) $d->id()) . '"></th>';
            echo '<td>' . esc_html($excerpt) . '</td>';
            echo '<td>' . esc_html(get_the_title($d->postId())) . '</td>';
            echo '<td>' . esc_html($author) . '</td>';
            echo '<td>' . esc_html(Text::cut((string) $d->reply(), 160));
            if ($comment instanceof \WP_Comment && in_array($d->status(), Panel::SHOWN, true)) {
                echo Panel::render($d, $comment); // Escaped inside Panel::render().
            }
            echo '</td>';
            echo '<td>' . esc_html(implode(', ', $d->flags())) . '</td>';
            echo '<td>' . esc_html($d->status()) . '</td>';
            echo '<td>' . esc_html(human_time_diff($d->createdAt(), \IndianOpinion\CommentDraftReplies\Clock::now())) . '</td>';
            echo '</tr>';
        }
        if ($rows === []) {
            echo '<tr><td colspan="8">' . esc_html__('No drafts here.', 'comment-draft-replies') . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><select name="reason"><option value="">' . esc_html__('No reason', 'comment-draft-replies') . '</option>';
        foreach (Settings::DISCARD_REASONS as $r) {
            echo '<option value="' . esc_attr($r) . '">' . esc_html($r) . '</option>';
        }
        echo '</select> <button type="submit" class="button">' . esc_html__('Discard selected', 'comment-draft-replies') . '</button></p></form>';
        $total = $this->drafts->count($filters);
        if ($total > $page * 20) {
            $next = add_query_arg(['page' => self::SLUG, 'status' => $status, 'post' => $post ?: null, 'paged' => $page + 1], admin_url('edit-comments.php'));
            echo '<p><a class="button" href="' . esc_url($next) . '">' . esc_html__('Next page', 'comment-draft-replies') . '</a></p>';
        }
        echo '</div>';
    }

    /**
     * Bulk discard (case 42). Returns the number discarded.
     *
     * @param array<string, mixed>|null $post
     */
    public function bulkDiscard(?array $post = null, bool $redirect = true): int
    {
        check_admin_referer(self::BULK_ACTION);
        if (!current_user_can(Publisher::reviewCapability())) {
            wp_die(esc_html__('Sorry, you cannot review reply drafts.', 'comment-draft-replies'), 403);
        }
        $p = $post ?? wp_unslash($_POST);
        $ids = isset($p['ids']) && is_array($p['ids']) ? array_map('absint', $p['ids']) : [];
        $reason = isset($p['reason']) && is_string($p['reason']) ? sanitize_key($p['reason']) : '';
        $done = 0;
        foreach (array_slice(array_unique($ids), 0, 200) as $id) {
            if ($id > 0 && $this->publisher->discard($id, get_current_user_id(), $reason) instanceof Draft) {
                $done++;
            }
        }
        delete_transient(self::COUNT_TRANSIENT);
        if ($redirect) {
            wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'discarded' => $done], admin_url('edit-comments.php')));
            exit;
        }

        return $done;
    }
}
