<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Generation;

use IndianOpinion\CommentDraftReplies\Clock;
use IndianOpinion\CommentDraftReplies\Comments;
use IndianOpinion\CommentDraftReplies\DailyCap;
use IndianOpinion\CommentDraftReplies\Draft;
use IndianOpinion\CommentDraftReplies\DraftRepository;
use IndianOpinion\CommentDraftReplies\Hold;
use IndianOpinion\CommentDraftReplies\Integration\GenQueue;
use IndianOpinion\CommentDraftReplies\Integration\SpendMeter;
use IndianOpinion\CommentDraftReplies\Logger;
use IndianOpinion\CommentDraftReplies\Settings;
use IndianOpinion\CommentDraftReplies\Text;

/**
 * Generation (section 4.3). Runs in WP-Cron, WP-CLI or gen-queue; never in
 * a reader's request.
 */
final class Runner
{
    public const CRON_HOOK = 'cdreplies_run';
    public const SAFETY_HOOK = 'cdreplies_safety_run';
    public const SCHEDULE = 'cdreplies_five_minutes';

    /** Outcomes that stop a run. */
    public const STOPS = ['disabled', 'cap', 'hold', 'unavailable', 'meter'];

    /** Test seam: force the library to look present or absent. */
    public static ?bool $libraryOverride = null;

    public function __construct(private readonly DraftRepository $drafts, private ?ModelCaller $caller = null)
    {
    }

    /** Wake the runner for a queued draft (case 22). */
    public static function wake(Draft $draft): void
    {
        if (GenQueue::active() && GenQueue::enqueue($draft)) {
            return;
        }
        $next = wp_next_scheduled(self::CRON_HOOK);
        if ($next === false) {
            wp_schedule_single_event(Clock::now() + 30, self::CRON_HOOK);
        }
    }

    public static function libraryAvailable(): bool
    {
        return self::$libraryOverride ?? LlmJsonCaller::libraryAvailable();
    }

    /**
     * Null when generation can run, else the reason it cannot (cases 67, 68).
     *
     * @param array<string, mixed>|null $settings
     */
    public static function unavailableReason(?array $settings = null): ?string
    {
        if (has_filter('cdreplies_model_caller')) {
            return null;
        }
        $s = $settings ?? Settings::all();
        if (!self::libraryAvailable()) {
            return 'no_library';
        }
        if (Settings::providerKey($s) === '') {
            return 'no_key';
        }
        if (trim((string) $s['model']) === '') {
            return 'no_model';
        }

        return null;
    }

    /** @param array<string, mixed> $s */
    private function caller(array $s): ModelCaller
    {
        if ($this->caller !== null) {
            return $this->caller;
        }
        $library = null;
        if (self::libraryAvailable() && Settings::providerKey($s) !== '' && trim((string) $s['model']) !== '') {
            $library = new LlmJsonCaller((string) $s['base_url'], Settings::providerKey($s), (string) $s['model'], (string) $s['fallback_model']);
        }

        return new FilterCaller($library, (string) $s['model']);
    }

    /**
     * Process due drafts. Returns counts and why the run stopped, if it did.
     *
     * @return array{processed:int,outcomes:array<string,int>,stopped:?string}
     */
    public function run(?int $max = null, ?int $seconds = null): array
    {
        $max = $max ?? max(1, (int) apply_filters('cdreplies_batch_size', 10));
        $seconds = $seconds ?? max(1, (int) apply_filters('cdreplies_run_seconds', 20));
        $started = microtime(true);
        $report = ['processed' => 0, 'outcomes' => [], 'stopped' => null];

        $s = Settings::fresh();
        $pre = $this->preflight($s);
        if ($pre !== null) {
            $report['stopped'] = $pre;

            return $report;
        }
        $this->drafts->recoverAbandoned();

        foreach ($this->drafts->dueQueued($max) as $id) {
            if (microtime(true) - $started >= $seconds) {
                break;
            }
            $outcome = $this->process($id);
            $report['outcomes'][$outcome] = ($report['outcomes'][$outcome] ?? 0) + 1;
            if ($outcome !== 'skipped') {
                $report['processed']++;
            }
            if (in_array($outcome, self::STOPS, true)) {
                $report['stopped'] = $outcome;
                break;
            }
        }

        return $report;
    }

    /** @param array<string, mixed> $s */
    private function preflight(array $s): ?string
    {
        if (Settings::isDisabled($s)) {
            return 'disabled';
        }
        if (Hold::active() !== null) {
            return 'hold';
        }
        if (self::unavailableReason($s) !== null && $this->caller === null) {
            Logger::throttled('unavailable', HOUR_IN_SECONDS, 'warning', 'unavailable', ['codes' => [(string) self::unavailableReason($s)]]);

            return 'unavailable';
        }
        if (DailyCap::remaining(Settings::dailyCap($s)) === 0) {
            DailyCap::take(Settings::dailyCap($s));

            return 'cap';
        }

        return null;
    }

    /**
     * gen-queue job handler. Errors are thrown as the queue's types.
     *
     * @param array<mixed> $payload
     */
    public function handleJob(array $payload, mixed $context = null): void
    {
        $id = (int) ($payload['draft_id'] ?? 0);
        $outcome = $this->process($id, $context, true);
        if (in_array($outcome, ['disabled', 'cap', 'hold', 'meter', 'unavailable'], true)) {
            throw GenQueue::toQueueException(new CallException(CallException::RETRYABLE, 'Generation deferred: ' . $outcome));
        }
    }

    /**
     * Claim and generate one draft. Returns an outcome code.
     */
    public function process(int $id, mixed $context = null, bool $queueMode = false): string
    {
        $token = $this->drafts->claim($id);
        if ($token === null) {
            return 'skipped';
        }
        $draft = $this->drafts->find($id);
        if (!$draft instanceof Draft) {
            return 'skipped';
        }
        Logger::log('info', 'claim', ['draft' => $id, 'comment' => $draft->commentId()]);

        // Case 23: re-check before calling.
        $comment = get_comment($draft->commentId());
        $post = $comment instanceof \WP_Comment ? get_post((int) $comment->comment_post_ID) : null;
        $stale = null;
        if (!$comment instanceof \WP_Comment) {
            $stale = 'comment_gone';
        } elseif ((string) $comment->comment_approved === 'spam') {
            $stale = 'comment_spam';
        } elseif ((string) $comment->comment_approved === 'trash') {
            $stale = 'comment_trash';
        } elseif (!$post instanceof \WP_Post || !Comments::postEligible($post)) {
            $stale = 'post_state';
        }
        if ($stale !== null) {
            $set = ['status' => 'stale', 'previous_status' => 'queued', 'last_reason' => $stale, 'claim_token' => null, 'claimed_at' => null];
            if ($stale === 'comment_gone') {
                $set += ['reply' => null, 'original_reply' => null, 'editor_note' => null, 'reason' => null];
            }
            if ($this->drafts->transition($id, 'generating', $set, ['claim_token' => $token])) {
                $this->fire('stale', $id, $stale);
            }

            return 'stale';
        }
        if (!$comment instanceof \WP_Comment || !$post instanceof \WP_Post) {
            $this->release($id, $token);

            return 'skipped';
        }
        $hash = Comments::textHash($comment);
        if ($hash !== $draft->commentHash()) {
            $this->drafts->update($id, ['comment_hash' => $hash]);
        }

        $s = Settings::fresh();
        if (Settings::isDisabled($s)) {
            $this->release($id, $token);

            return 'disabled';
        }
        if (Hold::active() !== null) {
            $this->release($id, $token);

            return 'hold';
        }
        if ($this->caller === null && self::unavailableReason($s) !== null) {
            $this->release($id, $token);

            return 'unavailable';
        }

        $values = Prompt::values($draft, $comment, $post, $s);
        $prompt = Prompt::render($values);
        $request = [
            'purpose' => SpendMeter::PURPOSE,
            'system' => $prompt['system'],
            'user' => $prompt['user'],
            'schema' => Prompt::schema(),
            'max_output_tokens' => max(50, (int) apply_filters('cdreplies_max_output_tokens', 600)),
            'deadline' => 60,
        ];
        $caller = $this->caller($s);
        $providerHost = SpendMeter::providerSlug((string) $s['base_url']);
        $model = (string) $s['model'];
        $tlds = array_values(array_map('strval', (array) apply_filters('cdreplies_link_tlds', Text::DEFAULT_TLDS)));
        $hosts = array_values(array_map('strval', (array) apply_filters('cdreplies_allowed_reply_hosts', [(string) parse_url(home_url('/'), PHP_URL_HOST)])));

        $lastReject = 'schema';
        for ($try = 1; $try <= 2; $try++) {
            $reservation = '';
            if (SpendMeter::active()) {
                [$allowed, $reservation, $why] = SpendMeter::preSpend($providerHost, $model, Text::length($request['system'] . $request['user']), $request['max_output_tokens']);
                if (!$allowed) {
                    $this->release($id, $token, $why);

                    return 'meter';
                }
            }
            if (!DailyCap::take(Settings::dailyCap($s))) {
                SpendMeter::release($reservation);
                $this->release($id, $token);

                return 'cap';
            }
            $callId = wp_generate_uuid4();
            Logger::log('info', 'call', ['draft' => $id, 'comment' => $draft->commentId(), 'attempt' => $try, 'generation' => $draft->generation()]);

            try {
                try {
                    $answer = $caller->call($request);
                } catch (CallException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    // A broken caller must not leave the draft claimed and
                    // looking untried, or recovery would pay for it again.
                    Logger::log('error', 'caller_error', ['draft' => $id, 'codes' => [get_class($e)]]);
                    throw new CallException(CallException::PERMANENT, 'The model caller failed.', true, 'provider');
                }
            } catch (CallException $e) {
                if (SpendMeter::active()) {
                    SpendMeter::record($reservation, $callId, $try, $providerHost, $model, null, null, $e->billed() ? 'error_billed' : 'error_unbilled');
                }
                if ($e->kind() === CallException::OUTPUT) {
                    $lastReject = $e->reasonCode();
                    continue;
                }

                return $this->callFailed($draft, $token, $e, $queueMode);
            }
            if (SpendMeter::active()) {
                SpendMeter::record($reservation, $callId, $try, $providerHost, $answer->model, $answer->tokensIn, $answer->tokensOut, 'ok');
            }
            if ($queueMode) {
                GenQueue::reportCost($context, $answer->tokensIn, $answer->tokensOut, SpendMeter::active());
            }

            try {
                $valid = Validator::check($answer->data, (string) $comment->comment_content, (int) $s['reply_max'], $hosts, $tlds);
            } catch (CallException $e) {
                $lastReject = $e->reasonCode();
                continue;
            }

            return $this->succeed($draft, $token, $valid, $answer, $prompt, $comment);
        }

        $this->drafts->transition($id, 'generating', ['status' => 'failed', 'last_reason' => $lastReject, 'claim_token' => null, 'claimed_at' => null], ['claim_token' => $token]);
        $this->fire('failed', $id, $lastReject);

        return 'failed';
    }

    /**
     * @param array{action:string,reply:string,reason:string,language:?string,flags:list<string>} $valid
     * @param array{system:string,user:string,prompt_id:string,render_hash:?string} $prompt
     */
    private function succeed(Draft $draft, string $token, array $valid, ModelAnswer $answer, array $prompt, \WP_Comment $comment): string
    {
        $flags = $valid['flags'];
        if ($valid['action'] === 'reply' && Text::scriptMismatch(Text::plain((string) $comment->comment_content), $valid['reply'])) {
            $flags[] = 'script_mismatch';
        }
        $status = $valid['action'] === 'reply' ? 'ready' : 'declined';
        $set = [
            'status' => $status,
            'reply' => $valid['action'] === 'reply' ? $valid['reply'] : null,
            'original_reply' => null,
            'edited' => 0,
            'language' => $valid['language'],
            'flags' => implode(',', $flags),
            'reason' => $valid['reason'],
            'last_reason' => null,
            'model' => substr($answer->model, 0, 128),
            'prompt_id' => substr($prompt['prompt_id'], 0, 191),
            'render_hash' => $prompt['render_hash'],
            'tokens_in' => $answer->tokensIn,
            'tokens_out' => $answer->tokensOut,
            'generated_at' => Clock::mysql(),
            'comment_hash' => Comments::textHash($comment),
            'attempts' => 0,
            'run_after' => null,
            'claim_token' => null,
            'claimed_at' => null,
        ];
        if (!$this->drafts->transition($draft->id(), 'generating', $set, ['claim_token' => $token])) {
            return 'skipped';
        }
        $fresh = $this->drafts->find($draft->id());
        if ($fresh instanceof Draft) {
            if ($status === 'ready') {
                Logger::log('info', 'ready', ['draft' => $fresh->id(), 'comment' => $fresh->commentId(), 'post' => $fresh->postId()]);
                do_action('cdreplies_generated', $fresh);
            } else {
                Logger::log('info', 'decline', ['draft' => $fresh->id(), 'comment' => $fresh->commentId(), 'post' => $fresh->postId()]);
                do_action('cdreplies_declined', $fresh);
            }
        }

        return $status;
    }

    private function callFailed(Draft $draft, string $token, CallException $e, bool $queueMode): string
    {
        $id = $draft->id();
        if ($e->kind() === CallException::ACCOUNT) {
            Hold::start($e->reasonCode());
            $this->release($id, $token, $e->reasonCode());
            if ($queueMode) {
                throw GenQueue::toQueueException($e);
            }

            return 'hold';
        }
        if ($e->kind() === CallException::RETRYABLE) {
            if ($queueMode) {
                $this->release($id, $token, 'provider');
                throw GenQueue::toQueueException($e);
            }
            $backoff = array_values(array_map('intval', (array) apply_filters('cdreplies_backoff', [60, 300, 900])));
            $attempts = $draft->attempts() + 1;
            $delay = $backoff[min($attempts - 1, max(0, count($backoff) - 1))] ?? 60;
            $queueAge = (int) Settings::all()['queue_age'];
            $runAfter = Clock::now() + $delay;
            if ($attempts >= 3 || $runAfter > $draft->createdAt() + $queueAge) {
                $this->drafts->transition($id, 'generating', ['status' => 'failed', 'attempts' => $attempts, 'last_reason' => 'provider', 'claim_token' => null, 'claimed_at' => null], ['claim_token' => $token]);
                $this->fire('failed', $id, 'provider');

                return 'failed';
            }
            $this->drafts->transition($id, 'generating', [
                'status' => 'queued',
                'attempts' => $attempts,
                'run_after' => Clock::mysql($runAfter),
                'last_reason' => 'provider',
                'claim_token' => null,
                'claimed_at' => null,
            ], ['claim_token' => $token]);
            if (!GenQueue::active()) {
                wp_schedule_single_event($runAfter + 5, self::CRON_HOOK);
            }

            return 'retry';
        }
        if ($e->reasonCode() === 'unavailable') {
            $this->release($id, $token);

            return 'unavailable';
        }
        $this->drafts->transition($id, 'generating', ['status' => 'failed', 'last_reason' => 'provider', 'claim_token' => null, 'claimed_at' => null], ['claim_token' => $token]);
        $this->fire('failed', $id, 'provider');
        if ($queueMode) {
            throw GenQueue::toQueueException($e);
        }

        return 'failed';
    }

    private function release(int $id, string $token, ?string $reason = null): void
    {
        $set = ['status' => 'queued', 'claim_token' => null, 'claimed_at' => null];
        if ($reason !== null) {
            $set['last_reason'] = substr($reason, 0, 64);
        }
        $this->drafts->transition($id, 'generating', $set, ['claim_token' => $token]);
    }

    private function fire(string $what, int $id, string $reason): void
    {
        $draft = $this->drafts->find($id);
        if (!$draft instanceof Draft) {
            return;
        }
        $ctx = ['draft' => $id, 'comment' => $draft->commentId(), 'post' => $draft->postId(), 'codes' => [$reason]];
        if ($what === 'failed') {
            Logger::log('warning', 'fail', $ctx);
            do_action('cdreplies_failed', $draft, $reason);
        } else {
            Logger::log('info', 'stale', $ctx);
            do_action('cdreplies_stale', $draft, $reason);
        }
    }
}
