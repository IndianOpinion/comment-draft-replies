<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies;

/**
 * Time source. Every stored time is UTC; every "day" is a calendar day in
 * the site timezone (case 69). Tests freeze it.
 */
final class Clock
{
    private static ?int $frozen = null;

    public static function now(): int
    {
        return self::$frozen ?? time();
    }

    /** Freeze the clock at a Unix time, or unfreeze with null. */
    public static function freeze(?int $timestamp): void
    {
        self::$frozen = $timestamp;
    }

    /** UTC MySQL datetime for a Unix time (default now). */
    public static function mysql(?int $timestamp = null): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp ?? self::now());
    }

    /** Unix time of a UTC MySQL datetime, or null. */
    public static function fromMysql(?string $value): ?int
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));

        return $dt === false ? null : $dt->getTimestamp();
    }

    public static function siteTimezone(): \DateTimeZone
    {
        return wp_timezone();
    }

    /** Calendar day (Y-m-d) of a Unix time in the given timezone. */
    public static function day(\DateTimeZone $tz, ?int $timestamp = null): string
    {
        return (new \DateTimeImmutable('@' . ($timestamp ?? self::now())))->setTimezone($tz)->format('Y-m-d');
    }
}
