<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies;

/**
 * Pure text rules shared by intake, output validation and the edit warnings.
 *
 * Every method works on Unicode characters, never bytes, and has no
 * WordPress dependency except where noted, so it is easy to test.
 */
final class Text
{
    /** Top-level domains used for bare-domain detection (case 11). */
    public const DEFAULT_TLDS = [
        'com', 'org', 'net', 'edu', 'gov', 'mil', 'int', 'info', 'biz', 'io', 'co', 'ai', 'app', 'dev',
        'me', 'tv', 'ly', 'gl', 'gg', 'xyz', 'top', 'site', 'online', 'shop', 'store', 'club', 'live',
        'news', 'blog', 'link', 'click', 'win', 'bid', 'vip', 'pro', 'tech', 'space', 'website', 'fun',
        'in', 'uk', 'us', 'ca', 'au', 'nz', 'de', 'fr', 'es', 'it', 'nl', 'be', 'ch', 'at', 'se', 'no',
        'dk', 'fi', 'pl', 'cz', 'ru', 'ua', 'cn', 'jp', 'kr', 'hk', 'tw', 'sg', 'my', 'id', 'ph', 'th',
        'vn', 'pk', 'bd', 'lk', 'np', 'ae', 'sa', 'za', 'ng', 'ke', 'br', 'ar', 'mx', 'cl', 'pe', 'ie',
        'pt', 'gr', 'tr', 'il', 'eu', 'asia', 'cc', 'ws', 'su', 'to', 'fm', 'am', 'sh', 'la', 'nu',
    ];

    /** Invisible and control characters refused in model output (case 31). */
    private const INVISIBLE = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}\x{0000}-\x{0009}\x{000B}-\x{001F}\x{007F}-\x{009F}]/u';

    /** Scripts known to the built-in writing-system check (ruling 3). */
    private const SCRIPTS = [
        'Latin', 'Cyrillic', 'Greek', 'Arabic', 'Hebrew', 'Devanagari', 'Bengali', 'Gurmukhi', 'Gujarati',
        'Oriya', 'Tamil', 'Telugu', 'Kannada', 'Malayalam', 'Sinhala', 'Thai', 'Han', 'Hiragana',
        'Katakana', 'Hangul', 'Georgian', 'Armenian', 'Ethiopic', 'Khmer', 'Lao', 'Myanmar', 'Tibetan',
    ];

    /**
     * Plain text: tags stripped, entities decoded, whitespace trimmed.
     */
    public static function plain(string $html): string
    {
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($text);
    }

    /** Length in Unicode characters. Invalid UTF-8 counts bytes. */
    public static function length(string $text): int
    {
        $count = preg_match_all('/./su', $text);

        return $count === false ? strlen($text) : $count;
    }

    /** First $max Unicode characters. */
    public static function cut(string $text, int $max): string
    {
        if ($max <= 0) {
            return '';
        }
        if (self::length($text) <= $max) {
            return $text;
        }
        preg_match('/^.{0,' . $max . '}/su', $text, $m);

        return $m[0] ?? '';
    }

    /** True when the text holds at least one letter in any script (case 19). */
    public static function hasLetter(string $text): bool
    {
        return preg_match('/\p{L}/u', $text) === 1;
    }

    /** The email-address pattern used by the contact and link rules. */
    private static function emailPattern(): string
    {
        return '/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}\-]+(?:\.[\p{L}\p{N}\-]+)+/u';
    }

    public static function removeEmails(string $text): string
    {
        return preg_replace(self::emailPattern(), ' ', $text) ?? $text;
    }

    public static function hasEmail(string $text): bool
    {
        return preg_match(self::emailPattern(), $text) === 1;
    }

    /**
     * Phone number: eight or more digits in one run, single spaces, dots or
     * hyphens allowed between digits, one optional leading plus (case 12).
     */
    public static function hasPhone(string $text): bool
    {
        if (preg_match_all('/\+?\d(?:[ .\-]?\d)*/u', $text, $m) === false) {
            return false;
        }
        foreach ($m[0] as $run) {
            if (strlen((string) preg_replace('/\D/', '', $run)) >= 8) {
                return true;
            }
        }

        return false;
    }

    public static function hasContact(string $text): bool
    {
        return self::hasEmail($text) || self::hasPhone($text);
    }

    /**
     * Link test of case 11. Email addresses are removed first.
     *
     * @param list<string> $tlds
     */
    public static function hasLink(string $raw, array $tlds): bool
    {
        $text = self::removeEmails($raw);
        if (preg_match('#https?://|www\.|<a[\s>]#i', $text) === 1) {
            return true;
        }

        return self::bareDomains($text, $tlds) !== [];
    }

    /**
     * Every host found in the text: URL hosts, www. hosts and bare domains.
     * Email addresses are removed first.
     *
     * @param list<string> $tlds
     * @return list<string> lower-cased hosts
     */
    public static function hosts(string $text, array $tlds): array
    {
        $text = self::removeEmails($text);
        $hosts = [];
        if (preg_match_all('#(?:https?://|//)([^\s/:?\#<>"\']+)#iu', $text, $m) > 0) {
            foreach ($m[1] as $h) {
                $hosts[] = strtolower(rtrim($h, '.,;:!)'));
            }
            $text = (string) preg_replace('#(?:https?://|//)[^\s<>"\']+#iu', ' ', $text);
        }
        if (preg_match_all('#\bwww\.[^\s/:?\#<>"\']+#iu', $text, $m) > 0) {
            foreach ($m[0] as $h) {
                $hosts[] = strtolower(rtrim($h, '.,;:!)'));
            }
            $text = (string) preg_replace('#\bwww\.[^\s<>"\']+#iu', ' ', $text);
        }
        foreach (self::bareDomains($text, $tlds) as $h) {
            $hosts[] = $h;
        }

        return array_values(array_unique($hosts));
    }

    /**
     * Bare domains: labels of letters, digits or hyphens joined by dots,
     * ending in a known TLD, followed by a slash or a word boundary.
     *
     * @param list<string> $tlds
     * @return list<string>
     */
    public static function bareDomains(string $text, array $tlds): array
    {
        $tlds = array_values(array_filter(array_map(
            static fn ($t): string => strtolower(trim((string) $t, ". \t")),
            $tlds
        ), static fn (string $t): bool => $t !== '' && preg_match('/^[a-z0-9\-]+$/', $t) === 1));
        if ($tlds === []) {
            return [];
        }
        usort($tlds, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $alt = implode('|', array_map(static fn (string $t): string => preg_quote($t, '/'), $tlds));
        $pattern = '/(?<![\p{L}\p{N}\-.@])((?:[\p{L}\p{N}][\p{L}\p{N}\-]*\.)+(?:' . $alt . '))(?=\/|[^\p{L}\p{N}\-]|$)/iu';
        if (preg_match_all($pattern, $text, $m) === false) {
            return [];
        }
        $out = [];
        foreach ($m[1] as $host) {
            $labels = explode('.', $host);
            $first = $labels[0];
            // A label of two or more characters before the TLD.
            if (self::length($first) < 2 && count($labels) === 2) {
                continue;
            }
            $out[] = strtolower($host);
        }

        return $out;
    }

    /**
     * Whole-word, case-insensitive match against a list of words or
     * phrases. Word edges are "not a letter or digit in any script", and a
     * multi-word phrase matches across any run of whitespace (case 13).
     *
     * @param list<string> $terms
     */
    public static function matchesBlocklist(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            $term = trim($term);
            if ($term === '') {
                continue;
            }
            $words = preg_split('/\s+/u', $term) ?: [$term];
            $body = implode('\s+', array_map(static fn (string $w): string => preg_quote($w, '/'), $words));
            if (preg_match('/(?<![\p{L}\p{N}\p{M}])' . $body . '(?![\p{L}\p{N}\p{M}])/iu', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lines of a textarea value, trimmed, empty lines dropped.
     *
     * @return list<string>
     */
    public static function lines(string $value): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $value) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    public static function hasInvisible(string $text): bool
    {
        return preg_match(self::INVISIBLE, $text) === 1;
    }

    /** Case-fold and collapse whitespace, for the echo test. */
    public static function normalise(string $text): string
    {
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * The script holding most letters, or null when there are fewer than
     * $minLetters letters in known scripts (ruling 3).
     */
    public static function dominantScript(string $text, int $minLetters = 5): ?string
    {
        $best = null;
        $bestCount = 0;
        $total = 0;
        foreach (self::SCRIPTS as $script) {
            $n = preg_match_all('/\p{' . $script . '}/u', $text);
            $n = $n === false ? 0 : $n;
            $total += $n;
            if ($n > $bestCount) {
                $best = $script;
                $bestCount = $n;
            }
        }
        if ($total < $minLetters) {
            return null;
        }
        // Kana and Han are one writing system for this check.
        if ($best === 'Hiragana' || $best === 'Katakana') {
            return 'Japanese';
        }

        return $best;
    }

    /**
     * True when both texts have a known dominant script and they differ.
     * Han with kana counts as Japanese; Han alone matches Japanese too.
     */
    public static function scriptMismatch(string $comment, string $reply): bool
    {
        $a = self::dominantScript($comment);
        $b = self::dominantScript($reply);
        if ($a === null || $b === null || $a === $b) {
            return false;
        }
        $cjk = ['Han', 'Japanese'];

        return !(in_array($a, $cjk, true) && in_array($b, $cjk, true));
    }
}
