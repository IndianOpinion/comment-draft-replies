<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Generation;

/**
 * A failed generation call. The kind decides what happens next (case 34):
 * retryable (backoff), account (global hold) or permanent (failed).
 */
class CallException extends \RuntimeException
{
    public const RETRYABLE = 'retryable';
    public const ACCOUNT = 'account';
    public const PERMANENT = 'permanent';
    public const OUTPUT = 'output';

    public function __construct(
        private readonly string $kind,
        string $message,
        private readonly bool $billed = false,
        private readonly string $code_ = 'provider'
    ) {
        parent::__construct($message);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function billed(): bool
    {
        return $this->billed;
    }

    /** Reason code stored on the draft. */
    public function reasonCode(): string
    {
        return $this->code_;
    }
}
