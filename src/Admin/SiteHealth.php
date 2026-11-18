<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Admin;

use IndianOpinion\CommentDraftReplies\Generation\Runner;
use IndianOpinion\CommentDraftReplies\Schema;
use IndianOpinion\CommentDraftReplies\Settings;

/**
 * Site Health test (section 11).
 */
final class SiteHealth
{
    public const LAST_RUN_OPTION = 'cdreplies_last_run';

    public function register(): void
    {
        add_filter('site_status_tests', [$this, 'tests']);
    }

    /**
     * @param array<string, mixed> $tests
     * @return array<string, mixed>
     */
    public function tests(array $tests): array
    {
        $tests['direct']['cdreplies'] = ['label' => __('Comment reply drafts', 'comment-draft-replies'), 'test' => [$this, 'result']];

        return $tests;
    }

    /** @return array<string, mixed> */
    public function result(): array
    {
        $problems = [];
        $missing = Schema::missingColumns();
        if ($missing !== []) {
            $problems[] = __('The drafts table is missing or incomplete.', 'comment-draft-replies');
        }
        $why = Runner::unavailableReason();
        if ($why !== null) {
            $problems[] = sprintf(__('Generation is off (%s).', 'comment-draft-replies'), $why);
        }
        $last = (int) get_option(self::LAST_RUN_OPTION, 0);
        $s = Settings::all();
        $lines = [
            sprintf(__('Kill switch: %s.', 'comment-draft-replies'), Settings::isDisabled($s) ? __('on', 'comment-draft-replies') : __('off', 'comment-draft-replies')),
            sprintf(__('Last generation run: %s.', 'comment-draft-replies'), $last > 0 ? gmdate('Y-m-d H:i', $last) . ' UTC' : __('never', 'comment-draft-replies')),
        ];

        return [
            'label' => $problems === [] ? __('Comment reply drafts is set up', 'comment-draft-replies') : __('Comment reply drafts needs attention', 'comment-draft-replies'),
            'status' => $problems === [] ? 'good' : 'recommended',
            'badge' => ['label' => __('Comments', 'comment-draft-replies'), 'color' => 'blue'],
            'description' => '<p>' . esc_html(implode(' ', array_merge($problems, $lines))) . '</p>',
            'actions' => '',
            'test' => 'cdreplies',
        ];
    }
}
