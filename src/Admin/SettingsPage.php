<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Admin;

use IndianOpinion\CommentDraftReplies\Settings;

/**
 * Settings, Comment reply drafts (section 7). Every field is validated on
 * save; an invalid value is refused with a message and the old value kept.
 */
final class SettingsPage
{
    public const SLUG = 'cdreplies-settings';
    public const GROUP = 'cdreplies';

    public static function capability(): string
    {
        return (string) apply_filters('cdreplies_settings_capability', 'manage_options');
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'registerSetting']);
    }

    public function menu(): void
    {
        add_options_page(__('Comment reply drafts', 'comment-draft-replies'), __('Comment reply drafts', 'comment-draft-replies'), self::capability(), self::SLUG, [$this, 'render']);
    }

    public function registerSetting(): void
    {
        register_setting(self::GROUP, Settings::OPTION, ['type' => 'array', 'sanitize_callback' => [$this, 'sanitize'], 'show_in_rest' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    public function sanitize(mixed $input): array
    {
        $old = get_option(Settings::OPTION, []);
        $old = is_array($old) ? $old : [];
        if (!current_user_can(self::capability())) {
            return Settings::merge($old);
        }
        [$new, $errors] = Settings::validate(is_array($input) ? $input : [], $old);
        foreach ($errors as $i => $msg) {
            add_settings_error(Settings::OPTION, 'cdreplies_' . $i, $msg, 'error');
        }
        if (Settings::constantDisabled()) {
            $new['kill'] = !empty(Settings::merge($old)['kill']);
        }

        return $new;
    }

    public function render(): void
    {
        if (!current_user_can(self::capability())) {
            wp_die(esc_html__('Sorry, you cannot change these settings.', 'comment-draft-replies'), 403);
        }
        $s = Settings::all();
        $locked = Settings::constantDisabled();
        $keyFromConstant = defined('CDREPLIES_PROVIDER_KEY');
        $capFromConstant = defined('CDREPLIES_DAILY_CAP');
        $name = Settings::OPTION;
        $f = static fn (string $k): string => esc_attr($name . '[' . $k . ']');

        echo '<div class="wrap"><h1>' . esc_html__('Comment reply drafts', 'comment-draft-replies') . '</h1>';
        echo '<p>' . esc_html__('Comment text, the article title and opening, and up to three earlier comments in the thread are sent to the provider below to draft replies. Nothing is published until a moderator clicks.', 'comment-draft-replies') . '</p>';
        settings_errors($name);
        echo '<form method="post" action="options.php">';
        settings_fields(self::GROUP);
        echo '<table class="form-table" role="presentation">';

        $row = static function (string $label, string $field): void {
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $field . '</td></tr>'; // $field is built from escaped parts below.
        };
        $check = static fn (string $k, bool $on, bool $disabled = false): string => '<input type="checkbox" name="' . $f($k) . '" value="1"' . ($on ? ' checked' : '') . ($disabled ? ' disabled' : '') . '>';
        $num = static fn (string $k, int $v, bool $disabled = false): string => '<input type="number" min="0" class="small-text" name="' . $f($k) . '" value="' . esc_attr((string) $v) . '"' . ($disabled ? ' disabled' : '') . '>';
        $text = static fn (string $k, string $v): string => '<input type="text" class="regular-text" name="' . $f($k) . '" value="' . esc_attr($v) . '">';

        $row(__('Kill switch', 'comment-draft-replies'), $check('kill', Settings::isDisabled($s), $locked) . ($locked ? ' <em>' . esc_html__('Locked on by CDREPLIES_DISABLED.', 'comment-draft-replies') . '</em>' : ''));
        if ($locked && !empty($s['kill'])) {
            echo '<input type="hidden" name="' . $f('kill') . '" value="1">';
        }
        $row(__('Provider base URL', 'comment-draft-replies'), $text('base_url', (string) $s['base_url']));
        $keyField = $keyFromConstant
            ? '<em>' . esc_html__('Set by CDREPLIES_PROVIDER_KEY.', 'comment-draft-replies') . '</em>'
            : '<input type="password" autocomplete="off" class="regular-text" name="' . $f('api_key') . '" value="" placeholder="' . esc_attr(Settings::maskedKey((string) $s['api_key'])) . '"> <label><input type="checkbox" name="' . $f('api_key_clear') . '" value="1"> ' . esc_html__('Clear', 'comment-draft-replies') . '</label>';
        $row(__('Provider key', 'comment-draft-replies'), $keyField);
        $row(__('Model', 'comment-draft-replies'), $text('model', (string) $s['model']));
        $row(__('Fallback model', 'comment-draft-replies'), $text('fallback_model', (string) $s['fallback_model']));
        $row(__('Voice notes', 'comment-draft-replies'), '<textarea class="large-text" rows="4" maxlength="2000" name="' . $f('voice') . '">' . esc_textarea((string) $s['voice']) . '</textarea>');
        $row(__('Send commenter names', 'comment-draft-replies'), $check('send_names', !empty($s['send_names'])));
        $row(__('Daily cap (calls)', 'comment-draft-replies'), $num('daily_cap', Settings::dailyCap($s), $capFromConstant) . ($capFromConstant ? ' <em>' . esc_html__('Set by CDREPLIES_DAILY_CAP.', 'comment-draft-replies') . '</em><input type="hidden" name="' . $f('daily_cap') . '" value="' . esc_attr((string) $s['daily_cap']) . '">' : ''));
        foreach (['thread' => __('Per thread limit', 'comment-draft-replies'), 'post' => __('Per post limit', 'comment-draft-replies'), 'author' => __('Per author limit', 'comment-draft-replies')] as $which => $label) {
            [$c, $w] = Settings::limit($s, $which);
            $row($label, $num('limit_' . $which . '_count', $c) . ' ' . esc_html__('drafts per', 'comment-draft-replies') . ' ' . $num('limit_' . $which . '_window', $w) . ' ' . esc_html__('seconds (0 drafts turns it off)', 'comment-draft-replies'));
        }
        $row(__('Minimum comment length', 'comment-draft-replies'), $num('min_length', (int) $s['min_length']));
        $row(__('Maximum comment length', 'comment-draft-replies'), $num('max_length', (int) $s['max_length']));
        $row(__('Skip comments with links', 'comment-draft-replies'), $check('skip_links', !empty($s['skip_links'])));
        $row(__('Skip comments with contact details', 'comment-draft-replies'), $check('skip_contact', !empty($s['skip_contact'])));
        $row(__('Blocked words', 'comment-draft-replies'), '<textarea class="large-text" rows="4" name="' . $f('blocklist') . '">' . esc_textarea((string) $s['blocklist']) . '</textarea>');
        $row(__('Reply maximum length', 'comment-draft-replies'), $num('reply_max', (int) $s['reply_max']));
        $row(__('Queue age limit (hours)', 'comment-draft-replies'), $num('queue_age', intdiv((int) $s['queue_age'], 3600)));
        $row(__('Draft age limit (days)', 'comment-draft-replies'), $num('draft_age', intdiv((int) $s['draft_age'], 86400)));
        $row(__('Reply author', 'comment-draft-replies'), wp_dropdown_users(['name' => $name . '[reply_author]', 'selected' => (int) $s['reply_author'], 'show_option_none' => __('The moderator who clicks', 'comment-draft-replies'), 'option_none_value' => '0', 'echo' => false]));
        $row(__('Approve comment and publish reply button', 'comment-draft-replies'), $check('approve_and_publish', !empty($s['approve_and_publish'])));
        $row(__('Log file', 'comment-draft-replies'), $text('log_file', (string) $s['log_file']));
        echo '</table>';
        submit_button();
        echo '</form></div>';
    }
}
