<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Generation;

/**
 * The site's own caller through `cdreplies_model_caller` (case 70). Wraps a
 * fallback caller used when the filter returns null.
 */
final class FilterCaller implements ModelCaller
{
    public function __construct(private readonly ?ModelCaller $fallback, private readonly string $model)
    {
    }

    public function call(array $request): ModelAnswer
    {
        $out = apply_filters('cdreplies_model_caller', null, $request);
        if ($out === null) {
            if ($this->fallback === null) {
                throw new CallException(CallException::PERMANENT, 'No model caller is available.', false, 'unavailable');
            }

            return $this->fallback->call($request);
        }
        if ($out instanceof \WP_Error) {
            $code = (string) $out->get_error_code();
            if ($code === 'cdreplies_retryable') {
                throw new CallException(CallException::RETRYABLE, 'Caller reported a transient error.');
            }
            if ($code === 'cdreplies_account') {
                throw new CallException(CallException::ACCOUNT, 'Caller reported an account problem.');
            }
            throw new CallException(CallException::PERMANENT, 'Caller reported a permanent error.');
        }
        if (!is_array($out)) {
            throw new CallException(CallException::OUTPUT, 'Caller returned neither an array nor an error.', true, 'schema');
        }

        return new ModelAnswer($out, $this->model !== '' ? $this->model : 'custom');
    }
}
