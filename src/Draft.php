<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies;

/**
 * One draft row, read-only (section 5.2).
 */
final class Draft
{
    public const STATUSES = ['queued', 'generating', 'ready', 'failed', 'declined', 'published', 'discarded', 'stale', 'expired'];
    public const TERMINAL = ['published', 'discarded', 'expired'];

    /** @param array<string, mixed> $row */
    private function __construct(private readonly array $row)
    {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }

    private function int(string $k): int
    {
        return (int) ($this->row[$k] ?? 0);
    }

    private function nullableInt(string $k): ?int
    {
        return isset($this->row[$k]) ? (int) $this->row[$k] : null;
    }

    private function nullableString(string $k): ?string
    {
        return isset($this->row[$k]) ? (string) $this->row[$k] : null;
    }

    public function id(): int
    {
        return $this->int('id');
    }

    public function commentId(): int
    {
        return $this->int('comment_id');
    }

    public function postId(): int
    {
        return $this->int('post_id');
    }

    public function threadRootId(): int
    {
        return $this->int('thread_root_id');
    }

    public function authorKey(): string
    {
        return (string) ($this->row['author_key'] ?? '');
    }

    public function status(): string
    {
        return (string) ($this->row['status'] ?? '');
    }

    public function previousStatus(): ?string
    {
        return $this->nullableString('previous_status');
    }

    public function reply(): ?string
    {
        return $this->nullableString('reply');
    }

    public function originalReply(): ?string
    {
        return $this->nullableString('original_reply');
    }

    public function edited(): bool
    {
        return $this->int('edited') === 1;
    }

    public function language(): ?string
    {
        return $this->nullableString('language');
    }

    /** @return list<string> */
    public function flags(): array
    {
        $raw = (string) ($this->row['flags'] ?? '');

        return $raw === '' ? [] : array_values(array_filter(explode(',', $raw), static fn (string $f): bool => $f !== ''));
    }

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->flags(), true);
    }

    public function reason(): ?string
    {
        return $this->nullableString('reason');
    }

    public function lastReason(): ?string
    {
        return $this->nullableString('last_reason');
    }

    public function editorNote(): ?string
    {
        return $this->nullableString('editor_note');
    }

    public function commentHash(): string
    {
        return (string) ($this->row['comment_hash'] ?? '');
    }

    public function model(): ?string
    {
        return $this->nullableString('model');
    }

    public function promptId(): ?string
    {
        return $this->nullableString('prompt_id');
    }

    public function renderHash(): ?string
    {
        return $this->nullableString('render_hash');
    }

    public function tokensIn(): ?int
    {
        return $this->nullableInt('tokens_in');
    }

    public function tokensOut(): ?int
    {
        return $this->nullableInt('tokens_out');
    }

    public function generation(): int
    {
        return $this->int('generation');
    }

    public function regenerations(): int
    {
        return $this->int('regenerations');
    }

    public function attempts(): int
    {
        return $this->int('attempts');
    }

    public function claimToken(): ?string
    {
        return $this->nullableString('claim_token');
    }

    public function runAfter(): ?int
    {
        return Clock::fromMysql($this->nullableString('run_after'));
    }

    public function createdAt(): int
    {
        return Clock::fromMysql($this->nullableString('created_at')) ?? 0;
    }

    public function generatedAt(): ?int
    {
        return Clock::fromMysql($this->nullableString('generated_at'));
    }

    public function decidedAt(): ?int
    {
        return Clock::fromMysql($this->nullableString('decided_at'));
    }

    public function decidedBy(): ?int
    {
        return $this->nullableInt('decided_by');
    }

    public function replyCommentId(): ?int
    {
        return $this->nullableInt('reply_comment_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status(), self::TERMINAL, true);
    }

    /** @return array<string, mixed> the raw row */
    public function toArray(): array
    {
        return $this->row;
    }
}
