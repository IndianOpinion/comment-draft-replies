<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies;

/**
 * Personal data exporter and eraser, and the privacy guide paragraph
 * (case 64).
 */
final class Privacy
{
    private const PER_PAGE = 100;

    public function __construct(private readonly DraftRepository $drafts)
    {
    }

    public function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [$this, 'exporters']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'erasers']);
        add_action('admin_init', [self::class, 'policyText']);
    }

    /**
     * @param array<string, mixed> $exporters
     * @return array<string, mixed>
     */
    public function exporters(array $exporters): array
    {
        $exporters['comment-draft-replies'] = ['exporter_friendly_name' => 'Comment reply drafts', 'callback' => [$this, 'export']];

        return $exporters;
    }

    /**
     * @param array<string, mixed> $erasers
     * @return array<string, mixed>
     */
    public function erasers(array $erasers): array
    {
        $erasers['comment-draft-replies'] = ['eraser_friendly_name' => 'Comment reply drafts', 'callback' => [$this, 'erase']];

        return $erasers;
    }

    /** @return array{data:list<array<string,mixed>>,done:bool} */
    public function export(string $email, int $page = 1): array
    {
        $items = [];
        $rows = $this->drafts->forEmail($email, $page, self::PER_PAGE);
        foreach ($rows as $d) {
            $items[] = [
                'group_id' => 'comment-reply-drafts',
                'group_label' => 'Comment reply drafts',
                'item_id' => 'cdreplies-draft-' . $d->id(),
                'data' => [
                    ['name' => 'Comment', 'value' => (string) $d->commentId()],
                    ['name' => 'Draft text', 'value' => (string) ($d->reply() ?? '')],
                    ['name' => 'Status', 'value' => $d->status()],
                    ['name' => 'Created', 'value' => gmdate('c', $d->createdAt())],
                    ['name' => 'Generated', 'value' => $d->generatedAt() !== null ? gmdate('c', $d->generatedAt()) : ''],
                    ['name' => 'Decided', 'value' => $d->decidedAt() !== null ? gmdate('c', $d->decidedAt()) : ''],
                ],
            ];
        }

        return ['data' => $items, 'done' => count($rows) < self::PER_PAGE];
    }

    /** @return array{items_removed:int,items_retained:bool,messages:list<string>,done:bool} */
    public function erase(string $email, int $page = 1): array
    {
        // Always page 1: erased rows still match, but their text is gone.
        $rows = $this->drafts->forEmail($email, $page, self::PER_PAGE);
        $removed = 0;
        foreach ($rows as $d) {
            if ($d->reply() === null && $d->originalReply() === null && $d->editorNote() === null && $d->reason() === null && $d->lastReason() === 'erased') {
                continue;
            }
            $this->drafts->update($d->id(), ['reply' => null, 'original_reply' => null, 'editor_note' => null, 'reason' => null, 'last_reason' => 'erased']);
            $removed++;
        }

        return ['items_removed' => $removed, 'items_retained' => false, 'messages' => [], 'done' => count($rows) < self::PER_PAGE];
    }

    public static function policyText(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        wp_add_privacy_policy_content(
            'Comment reply drafts',
            '<p>' . esc_html__('When you leave a comment, its text, the title and opening of the article, and up to three earlier comments in the same thread may be sent to a language model provider to draft a reply for our editors. Your email address, IP address and browser details are not sent. A draft is published only if an editor approves it.', 'comment-draft-replies') . '</p>'
        );
    }
}
