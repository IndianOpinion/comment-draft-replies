<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Rest;

use IndianOpinion\CommentDraftReplies\Comments;
use IndianOpinion\CommentDraftReplies\Draft;
use IndianOpinion\CommentDraftReplies\DraftRepository;
use IndianOpinion\CommentDraftReplies\Review\Publisher;
use IndianOpinion\CommentDraftReplies\Text;

/**
 * REST routes (section 5.5). Every route has a permission callback. The
 * publish route accepts cookie authentication only (case 49).
 */
final class Controller
{
    public const NAMESPACE = 'comment-draft-replies/v1';
    public const NONCE_ACTION = 'cdreplies_action';

    public function __construct(private readonly DraftRepository $drafts, private readonly Publisher $publisher)
    {
    }

    public function register(): void
    {
        $id = ['id' => ['type' => 'integer', 'required' => true, 'minimum' => 1]];
        register_rest_route(self::NAMESPACE, '/drafts', [
            'methods' => 'GET',
            'callback' => [$this, 'list'],
            'permission_callback' => [$this, 'canList'],
            'args' => [
                'status' => ['type' => 'string', 'enum' => array_merge([''], Draft::STATUSES), 'default' => ''],
                'post' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/drafts/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'show'],
            'permission_callback' => [$this, 'canReviewDraft'],
            'args' => $id,
        ]);
        register_rest_route(self::NAMESPACE, '/drafts/(?P<id>\d+)/publish', [
            'methods' => 'POST',
            'callback' => [$this, 'publish'],
            'permission_callback' => [$this, 'canPublish'],
            'args' => $id + [
                'text' => ['type' => 'string'],
                'approve_parent' => ['type' => 'boolean', 'default' => false],
                'confirm' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => []],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/drafts/(?P<id>\d+)/discard', [
            'methods' => 'POST',
            'callback' => [$this, 'discard'],
            'permission_callback' => [$this, 'canAct'],
            'args' => $id + ['reason' => ['type' => 'string', 'default' => '']],
        ]);
        register_rest_route(self::NAMESPACE, '/drafts/(?P<id>\d+)/regenerate', [
            'methods' => 'POST',
            'callback' => [$this, 'regenerate'],
            'permission_callback' => [$this, 'canAct'],
            'args' => $id + [
                'note' => ['type' => 'string', 'default' => ''],
                'anyway' => ['type' => 'boolean', 'default' => false],
            ],
        ]);
    }

    public function canList(\WP_REST_Request $request): bool|\WP_Error
    {
        return current_user_can(Publisher::reviewCapability())
            ? true
            : new \WP_Error('rest_forbidden', 'Sorry, you cannot review reply drafts.', ['status' => rest_authorization_required_code()]);
    }

    public function canReviewDraft(\WP_REST_Request $request): bool|\WP_Error
    {
        $draft = $this->drafts->find((int) $request['id']);
        if (!$draft instanceof Draft) {
            return current_user_can(Publisher::reviewCapability())
                ? new \WP_Error('cdreplies_not_found', 'No such draft.', ['status' => 404])
                : new \WP_Error('rest_forbidden', 'Sorry, you cannot review reply drafts.', ['status' => rest_authorization_required_code()]);
        }

        return Publisher::canReview(get_current_user_id(), $draft->postId())
            ? true
            : new \WP_Error('rest_forbidden', 'Sorry, you cannot review drafts on this post.', ['status' => 403]);
    }

    /** POST actions: review capability, edit_post, and our nonce. */
    public function canAct(\WP_REST_Request $request): bool|\WP_Error
    {
        $ok = $this->canReviewDraft($request);
        if ($ok !== true) {
            return $ok;
        }
        $nonce = (string) ($request->get_header('x_cdreplies_nonce') ?? $request->get_param('_cdreplies_nonce') ?? '');
        if ($nonce === '' || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return new \WP_Error('rest_cookie_invalid_nonce', 'The link you followed has expired.', ['status' => 403]);
        }

        return true;
    }

    /** Publish: cookie session only, then the same checks as other actions. */
    public function canPublish(\WP_REST_Request $request): bool|\WP_Error
    {
        $auth = self::authMethodError();
        if ($auth !== null) {
            return $auth;
        }

        return $this->canAct($request);
    }

    /**
     * Null when the current user is authenticated by a logged-in cookie for
     * the same user and not by an application password or any other
     * method; else a 401 error.
     */
    public static function authMethodError(): ?\WP_Error
    {
        $err = new \WP_Error('cdreplies_auth_method', 'Publishing requires a logged-in browser session.', ['status' => 401]);
        if (function_exists('rest_get_authenticated_app_password') && rest_get_authenticated_app_password() !== null) {
            return $err;
        }
        $uid = get_current_user_id();
        if ($uid <= 0) {
            return $err;
        }
        $cookieUser = wp_validate_auth_cookie('', 'logged_in');
        if ($cookieUser === false || (int) $cookieUser !== $uid) {
            return $err;
        }

        return null;
    }

    public function list(\WP_REST_Request $request): \WP_REST_Response
    {
        $filters = ['status' => (string) $request['status'], 'post' => (int) $request['post']];
        $userId = get_current_user_id();
        $out = [];
        foreach ($this->drafts->list($filters, max(1, (int) $request['page'])) as $d) {
            if (Publisher::canReview($userId, $d->postId())) {
                $out[] = self::shape($d, false);
            }
        }
        $response = new \WP_REST_Response($out);
        $response->header('X-WP-Total', (string) $this->drafts->count($filters));
        $response->header('Cache-Control', 'no-store, private');

        return $response;
    }

    public function show(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $draft = $this->drafts->find((int) $request['id']);
        if (!$draft instanceof Draft) {
            return new \WP_Error('cdreplies_not_found', 'No such draft.', ['status' => 404]);
        }
        $response = new \WP_REST_Response(self::shape($draft, true));
        $response->header('Cache-Control', 'no-store, private');

        return $response;
    }

    public function publish(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $text = $request->get_param('text');
        $confirm = array_values(array_map('strval', (array) $request->get_param('confirm')));
        $result = $this->publisher->publish(
            (int) $request['id'],
            get_current_user_id(),
            is_string($text) ? $text : null,
            (bool) $request->get_param('approve_parent'),
            $confirm
        );
        if ($result instanceof \WP_Error) {
            return $result;
        }

        return new \WP_REST_Response(['draft' => self::shape($result['draft'], false), 'reply_comment_id' => $result['reply_comment_id']]);
    }

    public function discard(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $result = $this->publisher->discard((int) $request['id'], get_current_user_id(), (string) $request->get_param('reason'));

        return $result instanceof \WP_Error ? $result : new \WP_REST_Response(self::shape($result, false));
    }

    public function regenerate(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $result = $this->publisher->regenerate((int) $request['id'], get_current_user_id(), (string) $request->get_param('note'), (bool) $request->get_param('anyway'));

        return $result instanceof \WP_Error ? $result : new \WP_REST_Response(self::shape($result, false));
    }

    /** @return array<string, mixed> */
    public static function shape(Draft $d, bool $withThread): array
    {
        $out = [
            'id' => $d->id(),
            'comment_id' => $d->commentId(),
            'post_id' => $d->postId(),
            'status' => $d->status(),
            'reply' => $d->reply(),
            'edited' => $d->edited(),
            'language' => $d->language(),
            'flags' => $d->flags(),
            'reason' => $d->reason(),
            'last_reason' => $d->lastReason(),
            'model' => $d->model(),
            'prompt_id' => $d->promptId(),
            'generation' => $d->generation(),
            'regenerations' => $d->regenerations(),
            'created_at' => gmdate('c', $d->createdAt()),
            'generated_at' => $d->generatedAt() !== null ? gmdate('c', $d->generatedAt()) : null,
            'decided_at' => $d->decidedAt() !== null ? gmdate('c', $d->decidedAt()) : null,
            'decided_by' => $d->decidedBy(),
            'reply_comment_id' => $d->replyCommentId(),
        ];
        if ($withThread) {
            $comment = get_comment($d->commentId());
            if ($comment instanceof \WP_Comment) {
                $out['comment'] = ['text' => Text::plain((string) $comment->comment_content), 'author' => (string) $comment->comment_author, 'status' => (string) $comment->comment_approved];
                $out['ancestors'] = array_map(static fn (\WP_Comment $a): array => ['id' => (int) $a->comment_ID, 'text' => Text::plain((string) $a->comment_content)], array_slice(Comments::ancestors($comment), 0, 3));
            }
        }

        return $out;
    }
}
