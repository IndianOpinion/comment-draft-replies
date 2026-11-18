<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies;

/**
 * The global provider hold (cases 34 and 57): after an authentication
 * failure or an out-of-credit answer, no call is made until it ends.
 */
final class Hold
{
    public const OPTION = 'cdreplies_hold';

    /** @return array{until:int,reason:string}|null the active hold */
    public static function active(): ?array
    {
        $raw = OptionStore::readFresh([self::OPTION]);
        if (!isset($raw[self::OPTION])) {
            return null;
        }
        $value = maybe_unserialize($raw[self::OPTION]);
        if (!is_array($value) || !isset($value['until']) || (int) $value['until'] <= Clock::now()) {
            return null;
        }

        return ['until' => (int) $value['until'], 'reason' => (string) ($value['reason'] ?? '')];
    }

    public static function start(string $reason): int
    {
        $seconds = max(60, (int) apply_filters('cdreplies_hold_seconds', 3600));
        $until = Clock::now() + $seconds;
        update_option(self::OPTION, ['until' => $until, 'reason' => $reason], false);
        OptionStore::forget(self::OPTION);
        Logger::log('error', 'hold', ['codes' => [$reason], 'until' => $until]);
        do_action('cdreplies_provider_hold', $until, $reason);

        return $until;
    }

    public static function clear(): void
    {
        delete_option(self::OPTION);
        OptionStore::forget(self::OPTION);
    }
}
