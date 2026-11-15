<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Admin;

use IndianOpinion\CommentDraftReplies\Generation\Runner;
use IndianOpinion\CommentDraftReplies\Hold;
use IndianOpinion\CommentDraftReplies\Schema;

/**
 * Admin notices for users who can manage options (cases 34, 66, 67, 68),
 * and the schema repair on the next admin load.
 */
final class Notices
{
    public function register(): void
    {
        add_action('admin_init', [self::class, 'maybeRepairSchema']);
        add_action('admin_notices', [$this, 'render']);
    }

    /** Install or repair on admin load when the version or a column is off. */
    public static function maybeRepairSchema(): void
    {
        $version = get_option(Schema::VERSION_OPTION);
        $check = get_transient('cdreplies_schema_checked') === false;
        if ($version !== Schema::VERSION || Schema::isMarkedBroken() || $check) {
            set_transient('cdreplies_schema_checked', 1, HOUR_IN_SECONDS);
            if ($version !== Schema::VERSION || Schema::isMarkedBroken() || Schema::missingColumns() !== []) {
                \IndianOpinion\CommentDraftReplies\Installer::installSite();
            }
        }
    }

    /** @return list<string> notice messages for the current user */
    public static function messages(): array
    {
        if (!current_user_can(SettingsPage::capability())) {
            return [];
        }
        $out = [];
        $hold = Hold::active();
        if ($hold !== null) {
            $out[] = sprintf(__('Comment reply drafts: generation is on hold until %s UTC after a provider account error (%s).', 'comment-draft-replies'), gmdate('Y-m-d H:i', $hold['until']), $hold['reason']);
        }
        $why = Runner::unavailableReason();
        if ($why !== null) {
            $map = [
                'no_library' => __('the llm-json-php library is not installed and no caller is set through cdreplies_model_caller', 'comment-draft-replies'),
                'no_key' => __('no provider key is set', 'comment-draft-replies'),
                'no_model' => __('no model is set', 'comment-draft-replies'),
            ];
            $out[] = sprintf(__('Comment reply drafts: generation is off because %s. Drafts stay queued.', 'comment-draft-replies'), $map[$why] ?? $why);
        }
        if (Schema::isMarkedBroken()) {
            $out[] = __('Comment reply drafts: the drafts table is missing or incomplete. It is repaired on the next admin page load; if this notice stays, check the database user can alter tables.', 'comment-draft-replies');
        }

        return $out;
    }

    public function render(): void
    {
        foreach (self::messages() as $m) {
            echo '<div class="notice notice-warning"><p>' . esc_html($m) . '</p></div>';
        }
    }
}
