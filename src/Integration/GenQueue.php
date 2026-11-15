<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Integration;

use IndianOpinion\CommentDraftReplies\Draft;
use IndianOpinion\CommentDraftReplies\Generation\CallException;

/**
 * Soft integration with the gen-queue plugin (case 22, section 5.8). Every
 * name gen-queue owns is a constant of this class, so a change in its
 * public interface is a one-file change here.
 */
final class GenQueue
{
    public const JOB_TYPE = 'comment-draft-replies.generate';
    public const VERSION_CONSTANT = 'GEN_QUEUE_VERSION';
    public const ENQUEUE_FUNCTION = 'gen_queue_enqueue';
    public const ENQUEUE_FILTER = 'gen_queue_enqueue';
    public const REGISTER_ACTION = 'gen_queue_register_types';
    public const RETRYABLE_CLASS = 'IndianOpinion\\GenQueue\\RetryableException';
    public const PERMANENT_CLASS = 'IndianOpinion\\GenQueue\\PermanentException';
    public const ACCOUNT_CLASS = 'IndianOpinion\\GenQueue\\AccountException';

    public static function active(): bool
    {
        return defined(self::VERSION_CONSTANT);
    }

    public static function key(Draft $draft): string
    {
        return 'draft:' . $draft->id() . ':' . $draft->generation();
    }

    /** Enqueue a generation job. False when the queue did not take it. */
    public static function enqueue(Draft $draft): bool
    {
        $payload = ['draft_id' => $draft->id(), 'generation' => $draft->generation()];
        $args = ['idempotency_key' => self::key($draft), 'caller' => 'comment-draft-replies'];
        $enqueue = self::callable(self::ENQUEUE_FUNCTION);
        if ($enqueue !== null) {
            $result = $enqueue(self::JOB_TYPE, $payload, $args);
        } else {
            $result = apply_filters(self::ENQUEUE_FILTER, null, self::JOB_TYPE, $payload, $args);
        }

        return $result !== null && $result !== false && !$result instanceof \WP_Error;
    }

    /** Register the job handler on the queue's register-types action. */
    public static function register(callable $handler): void
    {
        add_action(self::REGISTER_ACTION, static function ($registry) use ($handler): void {
            if (is_object($registry) && method_exists($registry, 'register')) {
                $registry->register(self::JOB_TYPE, $handler);
            }
        });
    }

    /** Map a call failure to the queue's exception types, when it has them. */
    public static function toQueueException(CallException $e): \Throwable
    {
        $class = match ($e->kind()) {
            CallException::RETRYABLE => self::RETRYABLE_CLASS,
            CallException::ACCOUNT => self::ACCOUNT_CLASS,
            default => self::PERMANENT_CLASS,
        };
        return self::instantiate($class, $e) ?? $e;
    }

    private static function instantiate(string $class, CallException $e): ?\Throwable
    {
        if (!class_exists($class) || !is_subclass_of($class, \Throwable::class)) {
            return null;
        }
        return new $class($e->getMessage(), 0, $e);
    }

    /** A function by name, when it exists. */
    public static function callable(string $name): ?callable
    {
        return is_callable($name) ? $name : null;
    }

    /** Report cost to the job context with metered true (case 26). */
    public static function reportCost(mixed $context, ?int $in, ?int $out, bool $metered): void
    {
        if (is_object($context) && method_exists($context, 'reportCost')) {
            $context->reportCost(['tokens_in' => $in, 'tokens_out' => $out, 'metered' => $metered]);
        }
    }
}
