<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Integration;

use IndianOpinion\CommentDraftReplies\Generation\Prompt;
use IndianOpinion\CommentDraftReplies\Logger;

/**
 * Soft integration with the prompt-library plugin (case 27).
 */
final class PromptLibrary
{
    public const SLUG = 'comment-draft-replies-reply';
    public const RENDER_FILTER = 'prompt_library_render';
    public const REGISTER_FUNCTION = 'prompt_library_register_default';

    /** Register the default definition on init when the library is present. */
    public static function registerDefault(): void
    {
        $register = GenQueue::callable(self::REGISTER_FUNCTION);
        if ($register === null) {
            return;
        }
        $register(self::SLUG, [
            'title' => 'Comment reply draft',
            'system' => Prompt::builtinSystem(),
            'user' => '{{context}}',
            'variables' => Prompt::ALLOWED_KEYS,
        ]);
    }

    /**
     * Render through the library, or null to use the built-in prompt.
     *
     * @param array<string, mixed> $values
     * @return array{system:string,user:string,prompt_id:string,render_hash:?string}|null
     */
    public static function render(array $values): ?array
    {
        $vars = $values;
        $vars['context'] = Prompt::builtinUser($values);
        try {
            $out = apply_filters(self::RENDER_FILTER, null, self::SLUG, $vars);
        } catch (\Throwable $e) {
            Logger::throttled('prompt_fallback_error', HOUR_IN_SECONDS, 'warning', 'prompt_fallback', ['codes' => ['render_error']]);

            return null;
        }
        if ($out === null) {
            if (has_filter(self::RENDER_FILTER)) {
                Logger::throttled('prompt_fallback_missing', HOUR_IN_SECONDS, 'info', 'prompt_fallback', ['codes' => ['template_missing']]);
            }

            return null;
        }
        if (!is_array($out) || !isset($out['user']) || !is_string($out['user']) || trim($out['user']) === '') {
            Logger::throttled('prompt_fallback_shape', HOUR_IN_SECONDS, 'warning', 'prompt_fallback', ['codes' => ['bad_render']]);

            return null;
        }
        $version = isset($out['version']) && is_scalar($out['version']) ? (string) $out['version'] : '0';
        $hash = isset($out['hash']) && is_string($out['hash']) && preg_match('/^[a-f0-9]{64}$/', $out['hash']) === 1 ? $out['hash'] : null;

        return [
            'system' => isset($out['system']) && is_string($out['system']) && $out['system'] !== '' ? $out['system'] : Prompt::builtinSystem(),
            'user' => $out['user'],
            'prompt_id' => 'library:' . self::SLUG . '@' . $version,
            'render_hash' => $hash,
        ];
    }
}
