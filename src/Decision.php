<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies;

/**
 * The outcome of the intake rules for one comment (section 5.2).
 */
final class Decision
{
    /**
     * @param list<string> $reasons
     * @param array<string, mixed> $facts internal facts for queueing
     */
    public function __construct(
        private readonly bool $eligible,
        private readonly array $reasons,
        private readonly string $lane,
        private readonly bool $exists = false,
        private readonly array $facts = []
    ) {
    }

    public function eligible(): bool
    {
        return $this->eligible;
    }

    /** @return list<string> */
    public function reasons(): array
    {
        return $this->reasons;
    }

    public function lane(): string
    {
        return $this->lane;
    }

    /** True when the comment already has a draft row (case 18). */
    public function exists(): bool
    {
        return $this->exists;
    }

    /** @return array<string, mixed> */
    public function facts(): array
    {
        return $this->facts;
    }
}
