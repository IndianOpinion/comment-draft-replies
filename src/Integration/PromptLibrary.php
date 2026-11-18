<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Integration;

use IndianOpinion\CommentDraftReplies\Generation\Prompt;
use IndianOpinion\CommentDraftReplies\Logger;

/**
 * Soft integration with the prompt-library plugin (case 27), as its README
 * publishes it: render through the `promptlib_render` filter (null when the
 * library is absent, a WP_Error on any failure, else an array with
 * `system`, `user`, `version` and `render_hash`), and ship a default
 * definition with `promptlib_register_default()` on `init`.
 */
final class PromptLibrary
{
    public const SLUG = 'comment-draft-replies-reply';
    public const CONSUMER = 'comment-draft-replies';
    public const RENDER_FILTER = 'promptlib_render';
    public const REGISTER_FUNCTION = 'promptlib_register_default';

    /** Register the default definition on init when the library is present. */
    public static function registerDefault(): void
    {
        $register = GenQueue::callable(self::REGISTER_FUNCTION);
        if ($register === null) {
            return;
        }
        try {
            $register(self::CONSUMER, self::definition());
        } catch (\Throwable $e) {
            Logger::throttled('prompt_register', HOUR_IN_SECONDS, 'warning', 'prompt_register', ['codes' => [get_class($e)]]);
        }
    }

    /**
     * The default definition in the library's `prompt-library/1` format.
     * `context` carries the whole built-in user prompt, with reader text
     * already inside its data blocks; the other values are offered as
     * optional variables for editors who write their own wording.
     *
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        $opt = static fn (string $name, string $type, string $escape, string $description): array => [
            'name' => $name,
            'type' => $type,
            'required' => false,
            'escape' => $escape,
            'description' => $description,
        ];

        return [
            'format' => 'prompt-library/1',
            'slug' => self::SLUG,
            'title' => 'Comment reply draft',
            'description' => 'Drafts a reply to one reader comment for a moderator to review.',
            'system' => Prompt::builtinSystem(),
            'user' => '{{ context }}',
            'variables' => [
                ['name' => 'context', 'type' => 'text', 'required' => true, 'escape' => 'plain', 'description' => 'The built-in prompt body, reader text already fenced.'],
                $opt('site_name', 'string', 'line', 'Site name.'),
                $opt('site_language', 'string', 'line', 'Site language tag.'),
                $opt('voice', 'text', 'plain', 'Operator voice notes.'),
                $opt('post_title', 'string', 'line', 'Article title.'),
                $opt('post_context', 'text', 'plain', 'Article excerpt or opening.'),
                $opt('ancestors', 'text', 'fenced', 'Earlier comments, untrusted reader text.'),
                $opt('comment', 'text', 'fenced', 'The comment, untrusted reader text.'),
                $opt('commenter', 'string', 'line', 'Commenter display name or "a reader"; untrusted.'),
                $opt('editor_note', 'text', 'plain', 'Editor note on regeneration.'),
                $opt('want_reply', 'boolean', 'plain', 'True when the editor asked for a reply anyway.'),
            ],
            'output' => ['mode' => 'json', 'schema' => Prompt::schema()],
            'settings' => ['temperature' => null, 'token_cap' => null],
            'note' => 'Default shipped by comment-draft-replies.',
        ];
    }

    /**
     * Library variables: scalars only, ancestors flattened to text, plus
     * `context`.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function variables(array $values): array
    {
        $vars = [];
        foreach ($values as $k => $v) {
            if (is_string($v) || is_bool($v) || is_int($v)) {
                $vars[$k] = $v;
            }
        }
        $lines = [];
        foreach (is_array($values['ancestors'] ?? null) ? $values['ancestors'] : [] as $a) {
            if (is_array($a)) {
                $lines[] = '(' . (($a['role'] ?? '') === 'site' ? 'site' : 'reader') . ') ' . (is_string($a['text'] ?? null) ? $a['text'] : '');
            }
        }
        $vars['ancestors'] = implode("\n\n", $lines);
        $vars['want_reply'] = !empty($values['want_reply']);
        $vars['context'] = Prompt::builtinUser($values);

        return $vars;
    }

    /**
     * Render through the library, or null to use the built-in prompt.
     *
     * @param array<string, mixed> $values
     * @return array{system:string,user:string,prompt_id:string,render_hash:?string}|null
     */
    public static function render(array $values): ?array
    {
        try {
            $out = apply_filters(self::RENDER_FILTER, null, self::SLUG, self::variables($values), 'live', ['consumer' => self::CONSUMER]);
        } catch (\Throwable $e) {
            Logger::throttled('prompt_fallback_error', HOUR_IN_SECONDS, 'warning', 'prompt_fallback', ['codes' => ['render_error']]);

            return null;
        }
        if ($out === null) {
            return null;
        }
        if ($out instanceof \WP_Error) {
            $code = (string) $out->get_error_code();
            Logger::throttled('prompt_fallback_' . $code, HOUR_IN_SECONDS, $code === 'promptlib_not_found' ? 'info' : 'warning', 'prompt_fallback', ['codes' => [$code]]);

            return null;
        }
        if (!is_array($out) || !isset($out['user']) || !is_string($out['user']) || trim($out['user']) === '') {
            Logger::throttled('prompt_fallback_shape', HOUR_IN_SECONDS, 'warning', 'prompt_fallback', ['codes' => ['bad_render']]);

            return null;
        }
        $version = isset($out['version']) && is_scalar($out['version']) ? (string) $out['version'] : '0';
        $hash = isset($out['render_hash']) && is_string($out['render_hash']) && preg_match('/^[a-f0-9]{64}$/', $out['render_hash']) === 1 ? $out['render_hash'] : null;

        return [
            'system' => isset($out['system']) && is_string($out['system']) && $out['system'] !== '' ? $out['system'] : Prompt::builtinSystem(),
            'user' => $out['user'],
            'prompt_id' => 'library:' . self::SLUG . '@' . $version,
            'render_hash' => $hash,
        ];
    }
}
