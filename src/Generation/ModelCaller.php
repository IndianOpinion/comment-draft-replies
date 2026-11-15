<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Generation;

/**
 * One generation call. Implementations throw CallException on failure.
 */
interface ModelCaller
{
    /**
     * @param array{purpose:string,system:string,user:string,schema:array<string,mixed>,max_output_tokens:int,deadline:int} $request
     */
    public function call(array $request): ModelAnswer;
}
