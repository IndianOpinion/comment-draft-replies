<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies;

/**
 * The per-day generation call counter (case 55).
 *
 * Stored as one option holding JSON: the day, the timezone that day began
 * in, the call count and whether the cap line was logged. Increments are a
 * compare-and-swap on the whole value, so two runners cannot both take the
 * last slot, with or without an object cache. A timezone change takes
 * effect at the next day boundary because the current day is judged in the
 * timezone it began in (case 69).
 */
final class DailyCap
{
    public const OPTION = 'cdreplies_calls';
    private const TRIES = 8;

    /**
     * Decode a raw stored value into the state for "now".
     *
     * @return array{day:string,tz:string,calls:int,logged:bool}
     */
    public static function stateFromRaw(?string $raw): array
    {
        $now = Clock::now();
        $stored = $raw !== null ? json_decode($raw, true) : null;
        if (is_array($stored) && isset($stored['day'], $stored['tz']) && is_string($stored['day']) && is_string($stored['tz'])) {
            try {
                $tz = new \DateTimeZone($stored['tz']);
            } catch (\Exception $e) {
                $tz = null;
            }
            if ($tz !== null && Clock::day($tz, $now) === $stored['day']) {
                return [
                    'day' => $stored['day'],
                    'tz' => $stored['tz'],
                    'calls' => max(0, (int) ($stored['calls'] ?? 0)),
                    'logged' => !empty($stored['logged']),
                ];
            }
        }
        $tz = Clock::siteTimezone();

        return ['day' => Clock::day($tz, $now), 'tz' => $tz->getName(), 'calls' => 0, 'logged' => false];
    }

    /** Calls counted today, read fresh. */
    public static function callsToday(): int
    {
        $raw = OptionStore::readFresh([self::OPTION]);

        return self::stateFromRaw($raw[self::OPTION] ?? null)['calls'];
    }

    public static function remaining(int $cap): int
    {
        return max(0, $cap - self::callsToday());
    }

    /**
     * Take one call slot. False when the cap is reached; fires the
     * once-per-day cap action and log line the first time.
     */
    public static function take(int $cap): bool
    {
        for ($i = 0; $i < self::TRIES; $i++) {
            $rawAll = OptionStore::readFresh([self::OPTION]);
            $raw = $rawAll[self::OPTION] ?? null;
            $state = self::stateFromRaw($raw);
            if ($state['calls'] >= $cap) {
                if (!$state['logged']) {
                    $next = $state;
                    $next['logged'] = true;
                    if (OptionStore::swap(self::OPTION, $raw, self::encode($next))) {
                        Logger::log('warning', 'cap', ['codes' => ['daily_cap'], 'calls' => $state['calls'], 'cap' => $cap]);
                        do_action('cdreplies_cap_reached', $state['calls'], $cap, $state['day']);
                    }
                }

                return false;
            }
            $next = $state;
            $next['calls']++;
            if (OptionStore::swap(self::OPTION, $raw, self::encode($next))) {
                return true;
            }
        }

        // Heavy contention: fail closed for spending.
        return false;
    }

    /** @param array{day:string,tz:string,calls:int,logged:bool} $state */
    private static function encode(array $state): string
    {
        return (string) wp_json_encode($state);
    }
}
