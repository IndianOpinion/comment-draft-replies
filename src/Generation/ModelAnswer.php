<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Generation;

/**
 * A decoded answer from a model call.
 */
final class ModelAnswer
{
    /** @param array<mixed> $data */
    public function __construct(
        public readonly array $data,
        public readonly string $model,
        public readonly ?int $tokensIn = null,
        public readonly ?int $tokensOut = null
    ) {
    }
}
