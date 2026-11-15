<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies;

/**
 * Small read helpers over core comments and posts.
 */
final class Comments
{
    /**
     * Ancestors of a comment, nearest first. Broken chains end early.
     *
     * @return list<\WP_Comment>
     */
    public static function ancestors(\WP_Comment $comment, int $max = 100): array
    {
        $out = [];
        $seen = [(int) $comment->comment_ID => true];
        $parent = (int) $comment->comment_parent;
        while ($parent > 0 && count($out) < $max) {
            if (isset($seen[$parent])) {
                break;
            }
            $seen[$parent] = true;
            $c = get_comment($parent);
            if (!$c instanceof \WP_Comment) {
                break;
            }
            $out[] = $c;
            $parent = (int) $c->comment_parent;
        }

        return $out;
    }

    /** The thread root: the last ancestor found, or the comment itself. */
    public static function threadRoot(\WP_Comment $comment): int
    {
        $ancestors = self::ancestors($comment);

        return $ancestors === [] ? (int) $comment->comment_ID : (int) end($ancestors)->comment_ID;
    }

    /** Depth: a top-level comment is 1. */
    public static function depth(\WP_Comment $comment): int
    {
        return 1 + count(self::ancestors($comment));
    }

    /**
     * Parent for a published reply (ruling 2): the comment itself, unless
     * threading is on and the comment sits at the maximum depth, in which
     * case the reply attaches to the comment's parent, as core does.
     */
    public static function replyParent(\WP_Comment $comment): int
    {
        $id = (int) $comment->comment_ID;
        if (!get_option('thread_comments')) {
            return $id;
        }
        $max = (int) get_option('thread_comments_depth', 5);
        $parent = (int) $comment->comment_parent;
        if ($max > 0 && $parent > 0 && self::depth($comment) >= $max) {
            return $parent;
        }

        return $id;
    }

    public static function isOrdinary(\WP_Comment $comment): bool
    {
        $types = (array) apply_filters('cdreplies_comment_types', ['', 'comment']);

        return in_array((string) $comment->comment_type, array_map('strval', $types), true);
    }

    /** Staff: decided from the user id only (case 6). */
    public static function isStaff(\WP_Comment $comment, ?\WP_Post $post): bool
    {
        $uid = (int) $comment->user_id;
        if ($uid <= 0) {
            return false;
        }
        if ($post instanceof \WP_Post && (int) $post->post_author === $uid) {
            return true;
        }
        if (user_can($uid, 'moderate_comments')) {
            return true;
        }

        return $post instanceof \WP_Post && user_can($uid, 'edit_post', (int) $post->ID);
    }

    /** Post eligibility (case 8). */
    public static function postEligible(?\WP_Post $post): bool
    {
        if (!$post instanceof \WP_Post) {
            return false;
        }
        $types = (array) apply_filters('cdreplies_post_types', ['post', 'page']);

        return $post->post_status === 'publish'
            && (string) $post->post_password === ''
            && in_array($post->post_type, array_map('strval', $types), true);
    }

    /** Salted author key (section 6). */
    public static function authorKey(\WP_Comment $comment): string
    {
        $uid = (int) $comment->user_id;
        $email = strtolower(trim((string) $comment->comment_author_email));
        if ($uid > 0) {
            $basis = 'u:' . $uid;
        } elseif ($email !== '') {
            $basis = 'e:' . $email;
        } else {
            $basis = 'n:' . (int) $comment->comment_ID;
        }

        return hash_hmac('sha256', $basis, wp_salt('auth'));
    }

    public static function textHash(\WP_Comment $comment): string
    {
        return hash('sha256', (string) $comment->comment_content);
    }

    /** True when the author has another comment in spam or trash (case 14). */
    public static function authorFlagged(\WP_Comment $comment): bool
    {
        global $wpdb;
        $uid = (int) $comment->user_id;
        $email = trim((string) $comment->comment_author_email);
        $conds = [];
        $args = [(int) $comment->comment_ID];
        if ($uid > 0) {
            $conds[] = 'user_id = %d';
            $args[] = $uid;
        }
        if ($email !== '') {
            $conds[] = 'comment_author_email = %s';
            $args[] = $email;
        }
        if ($conds === []) {
            return false;
        }
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved IN ('spam','trash') AND comment_ID <> %d AND (" . implode(' OR ', $conds) . ') LIMIT 1',
            ...$args
        ));

        return $found !== null;
    }
}
