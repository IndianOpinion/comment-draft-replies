<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Integration;

/**
 * Soft integration with the spend-meter plugin (case 26). All contact lives
 * in this class; an unknown contract version means "meter absent".
 */
final class SpendMeter
{
    public const CONTRACT_CONSTANT = 'SPEND_METER_CONTRACT';
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
     * Ask before spending. Returns [allowed, reservation id, reason code].
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
            'input_tokens' => (int) ceil($inputChars / 3),
            'output_tokens' => $outputCap,
        ]);
        if (!is_array($answer)) {
            return [false, '', 'meter_no_answer'];
        }
        $reason = isset($answer['reason']) && is_string($answer['reason']) && preg_match('/^[a-z0-9_]{1,48}$/', $answer['reason']) === 1
            ? 'meter_' . $answer['reason']
            : 'meter_denied';

        return [($answer['allowed'] ?? null) === true, (string) ($answer['reservation_id'] ?? ''), $reason];
    }

    public static function record(string $reservationId, string $callId, string $provider, string $model, ?int $in, ?int $out, string $status): void
    {
        do_action(self::RECORD_ACTION, [
            'reservation_id' => $reservationId,
            'call_id' => $callId,
            'purpose' => self::PURPOSE,
            'caller' => self::CALLER,
            'provider' => $provider,
            'model' => $model,
            'input_tokens' => $in,
            'output_tokens' => $out,
            'status' => $status,
        ]);
    }

    public static function release(string $reservationId): void
    {
        if ($reservationId !== '') {
            do_action(self::RELEASE_ACTION, $reservationId);
        }
    }
}
