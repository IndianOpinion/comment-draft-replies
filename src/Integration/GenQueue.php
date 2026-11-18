<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Integration;

use IndianOpinion\CommentDraftReplies\Draft;
use IndianOpinion\CommentDraftReplies\Generation\CallException;
use IndianOpinion\CommentDraftReplies\Logger;

/**
 * Soft integration with the gen-queue plugin (case 22, section 5.8), as its
 * README publishes it: detected by `GEN_QUEUE_VERSION`, jobs through the
 * soft enqueue filter `gen_queue_enqueue_job`, the type registered with
 * `gen_queue_register_type()` on `gen_queue_register_types`, and the
 * handler called as `handler(Job $job, JobContext $ctx)`. Every name
 * gen-queue owns is a constant of this class.
 */
final class GenQueue
{
    public const JOB_TYPE = 'comment-draft-replies.generate';
    public const VERSION_CONSTANT = 'GEN_QUEUE_VERSION';
    public const ENQUEUE_FILTER = 'gen_queue_enqueue_job';
    public const REGISTER_ACTION = 'gen_queue_register_types';
    public const REGISTER_FUNCTION = 'gen_queue_register_type';
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

    /**
     * Enqueue a generation job through the soft enqueue filter, which
     * returns a job id, null when gen-queue is absent, or a WP_Error (the
     * queue catches its own exceptions there). False when the queue did not
     * take the job, so the caller falls back to WP-Cron.
     */
    public static function enqueue(Draft $draft): bool
    {
        $payload = ['draft_id' => $draft->id(), 'generation' => $draft->generation()];
        $args = ['idempotency_key' => self::key($draft), 'source' => 'comment-draft-replies'];
        try {
            $result = apply_filters(self::ENQUEUE_FILTER, null, self::JOB_TYPE, $payload, $args);
        } catch (\Throwable $e) {
            $result = null;
        }
        if ($result instanceof \WP_Error) {
            Logger::throttled('genqueue_enqueue', HOUR_IN_SECONDS, 'warning', 'genqueue_enqueue', ['draft' => $draft->id(), 'codes' => [(string) $result->get_error_code()]]);

            return false;
        }

        return is_int($result) || (is_numeric($result) && (int) $result > 0);
    }

    /**
     * Register the job type on the queue's register-types action. The
     * handler receives the job payload array and the job context.
     *
     * @param callable(array<mixed>, mixed): void $handler
     */
    public static function register(callable $handler): void
    {
        add_action(self::REGISTER_ACTION, static function () use ($handler): void {
            $register = self::callable(self::REGISTER_FUNCTION);
            if ($register === null) {
                return;
            }
            $register(self::JOB_TYPE, static function ($job, $ctx = null) use ($handler) {
                $payload = is_object($job) && isset($job->payload) && is_array($job->payload) ? $job->payload : (is_array($job) ? $job : []);
                $handler($payload, $ctx);

                return null;
            });
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
        // RetryableException takes (message, retryAfter, code, previous);
        // the others take the standard (message, code, previous).
        $args = $class === self::RETRYABLE_CLASS ? [$e->getMessage(), null, 0, $e] : [$e->getMessage(), 0, $e];
        $made = (new \ReflectionClass($class))->newInstanceArgs($args);

        return $made instanceof \Throwable ? $made : null;
    }

    /** A function by name, when it exists. */
    public static function callable(string $name): ?callable
    {
        return is_callable($name) ? $name : null;
    }

    /**
     * Report usage to the job context (case 26). The amount is zero because
     * this plugin holds no prices; `metered` is true when spend-meter
     * already recorded the call, so it is not counted twice.
     */
    public static function reportCost(mixed $context, ?int $in, ?int $out, bool $metered): void
    {
        if (!is_object($context) || !method_exists($context, 'addCost')) {
            return;
        }
        try {
            $context->addCost(0, ['input_units' => max(0, (int) $in), 'output_units' => max(0, (int) $out), 'metered' => $metered]);
        } catch (\Throwable $e) {
            Logger::throttled('genqueue_cost', HOUR_IN_SECONDS, 'warning', 'genqueue_cost', ['codes' => [get_class($e)]]);
        }
    }
}
