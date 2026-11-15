<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Generation;

use IndianOpinion\CommentDraftReplies\Text;

/**
 * Checks the decoded answer (cases 30 and 31). Model output is untrusted
 * until a person publishes it (lesson 18).
 */
final class Validator
{
    /**
     * Normalise and validate an answer.
     *
     * @param array<mixed> $data
     * @param list<string> $allowedHosts
     * @param list<string> $tlds
     * @return array{action:string,reply:string,reason:string,language:?string,flags:list<string>}
     * @throws CallException kind OUTPUT with the rejection reason code
     */
    public static function check(array $data, string $commentText, int $replyMax, array $allowedHosts, array $tlds): array
    {
        $action = $data['action'] ?? null;
        if (
            !in_array($action, ['reply', 'no_reply'], true)
            || (isset($data['reply']) && !is_string($data['reply']))
            || (isset($data['reason']) && !is_string($data['reason']))
            || (isset($data['language']) && !is_string($data['language']))
            || (isset($data['flags']) && !is_array($data['flags']))
        ) {
            throw self::reject('schema');
        }
        $flags = [];
        foreach ((array) ($data['flags'] ?? []) as $f) {
            if (is_string($f) && in_array($f, Prompt::FLAGS, true) && !in_array($f, $flags, true)) {
                $flags[] = $f;
            }
        }
        $language = isset($data['language']) && preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{1,8}){0,4}$/', (string) $data['language']) === 1
            ? substr((string) $data['language'], 0, 35)
            : null;
        $reason = Text::cut(trim(Text::plain((string) ($data['reason'] ?? ''))), 200);
        $reason = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}\x00-\x1F\x7F-\x9F]/u', '', $reason);

        if ($action === 'no_reply') {
            return ['action' => 'no_reply', 'reply' => '', 'reason' => $reason, 'language' => $language, 'flags' => $flags];
        }

        $reply = trim(Text::plain((string) ($data['reply'] ?? '')));
        $code = self::rejection($reply, $commentText, $replyMax, $allowedHosts, $tlds);
        if ($code !== null) {
            throw self::reject($code);
        }

        return ['action' => 'reply', 'reply' => $reply, 'reason' => $reason, 'language' => $language, 'flags' => $flags];
    }

    /**
     * The rejection code for a plain-text reply, or null when it passes.
     *
     * @param list<string> $allowedHosts
     * @param list<string> $tlds
     */
    public static function rejection(string $reply, string $commentText, int $replyMax, array $allowedHosts, array $tlds): ?string
    {
        if ($reply === '') {
            return 'empty';
        }
        if (Text::length($reply) > $replyMax) {
            return 'too_long';
        }
        if (Text::hasInvisible($reply)) {
            return 'invisible';
        }
        if (str_contains($reply, Prompt::MARKER_OPEN) || str_contains($reply, Prompt::MARKER_CLOSE) || str_contains($reply, '[[READER_DATA')) {
            return 'marker';
        }
        if (self::foreignHosts($reply, $allowedHosts, $tlds) !== []) {
            return 'link';
        }
        if (preg_match('#<a[\s>]#i', $reply) === 1) {
            return 'link';
        }
        if (Text::hasContact($reply)) {
            return 'contact';
        }
        if (Text::normalise($reply) === Text::normalise(Text::plain($commentText))) {
            return 'echo';
        }

        return null;
    }

    /**
     * Hosts in the text that are not allowed.
     *
     * @param list<string> $allowedHosts
     * @param list<string> $tlds
     * @return list<string>
     */
    public static function foreignHosts(string $text, array $allowedHosts, array $tlds): array
    {
        $allowed = array_map(static fn (string $h): string => strtolower(trim($h)), $allowedHosts);
        $out = [];
        foreach (Text::hosts($text, $tlds) as $host) {
            if (!in_array($host, $allowed, true)) {
                $out[] = $host;
            }
        }

        return $out;
    }

    private static function reject(string $code): CallException
    {
        return new CallException(CallException::OUTPUT, 'Model answer rejected: ' . $code, true, $code);
    }
}
