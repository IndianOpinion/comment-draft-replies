<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Integration;

/**
 * Soft integration with the spend-meter plugin (case 26), contract 1 as
 * published in its README: detect `SPEND_METER_API`, ask the
 * `spend_meter_pre_spend` filter with an intent array, then fire exactly
 * one `spend_meter_record` (the call happened) or `spend_meter_release`
 * (no call) for each reservation. All contact lives in this class; an
 * unknown contract version, or a null decision, means "meter absent".
 */
final class SpendMeter
{
    public const CONTRACT_CONSTANT = 'SPEND_METER_API';
    public const CONTRACT = 1;
    public const PRE_SPEND_FILTER = 'spend_meter_pre_spend';
    public const RECORD_ACTION = 'spend_meter_record';
    public const RELEASE_ACTION = 'spend_meter_release';
    public const PURPOSE = 'comment-draft-replies.reply';
    public const CALLER = 'comment-draft-replies';

    public static function active(): bool
    {
        return defined(self::CONTRACT_CONSTANT) && (int) constant(self::CONTRACT_CONSTANT) === self::CONTRACT;
    }

    /**
     * Provider slug for the meter: the endpoint host, lower-cased, made to
     * fit the meter's slug pattern.
     */
    public static function providerSlug(string $baseUrl): string
    {
        $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        $slug = (string) preg_replace('/[^a-z0-9_.-]+/', '-', $host);
        $slug = ltrim($slug, '_.-');

        return $slug !== '' ? substr($slug, 0, 64) : 'custom';
    }

    /**
     * Ask before spending. Returns [allowed, reservation id, reason code].
     * A null decision means no meter is present: allowed, no reservation.
     *
     * @return array{0:bool,1:string,2:string}
     */
    public static function preSpend(string $provider, string $model, int $inputChars, int $outputCap): array
    {
        $answer = apply_filters(self::PRE_SPEND_FILTER, null, [
            'purpose' => self::PURPOSE,
            'caller' => self::CALLER,
            'provider' => $provider,
            'model' => $model,
            'est_input_tokens' => (int) ceil($inputChars / 3),
            'max_output_tokens' => $outputCap,
        ]);
        if ($answer === null) {
            return [true, '', ''];
        }
        if (!is_array($answer)) {
            return [false, '', 'meter_bad_answer'];
        }
        $reason = isset($answer['reason']) && is_string($answer['reason']) && preg_match('/^[a-z0-9_]{1,48}$/', $answer['reason']) === 1
            ? 'meter_' . $answer['reason']
            : 'meter_denied';
        $reservation = isset($answer['reservation_id']) && is_string($answer['reservation_id']) ? $answer['reservation_id'] : '';
        if (($answer['allowed'] ?? null) !== true) {
            return [false, '', $reason];
        }

        return [true, $reservation, $reason];
    }

    /**
     * Record one attempt. Token counts are left out when the response had
     * none, so the meter falls back to its reservation estimate.
     */
    public static function record(string $reservationId, string $callId, int $attempt, string $provider, string $model, ?int $in, ?int $out, string $status): void
    {
        $call = [
            'call_id' => $callId,
            'purpose' => self::PURPOSE,
            'caller' => self::CALLER,
            'provider' => $provider,
            'model' => $model,
            'status' => $status,
            'attempt' => $attempt,
        ];
        if ($reservationId !== '') {
            $call['reservation_id'] = $reservationId;
        }
        if ($in !== null) {
            $call['input_tokens'] = max(0, $in);
        }
        if ($out !== null) {
            $call['output_tokens'] = max(0, $out);
        }
        do_action(self::RECORD_ACTION, $call);
    }

    public static function release(string $reservationId): void
    {
        if ($reservationId !== '') {
            do_action(self::RELEASE_ACTION, $reservationId);
        }
    }
}
