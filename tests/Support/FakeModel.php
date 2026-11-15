<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Tests\Support;

/**
 * A scripted model behind the `cdreplies_model_caller` filter. Records every
 * request it receives.
 */
final class FakeModel
{
    /** @var list<array<mixed>|\WP_Error|\Throwable> */
    private array $script = [];
    /** @var list<array<string, mixed>> */
    public array $requests = [];

    /** @param array<mixed>|\WP_Error|\Throwable $answer */
    public function push(array|\WP_Error|\Throwable $answer): self
    {
        $this->script[] = $answer;

        return $this;
    }

    /** @return array<string, mixed> */
    public static function reply(string $text, array $flags = [], string $language = 'en'): array
    {
        return ['action' => 'reply', 'reply' => $text, 'reason' => 'Answers the question.', 'language' => $language, 'flags' => $flags];
    }

    /**
     * @param array<string, mixed> $request
     * @return array<mixed>|\WP_Error|null
     */
    public function answer(mixed $value, array $request): array|\WP_Error|null
    {
        $this->requests[] = $request;
        $next = array_shift($this->script);
        if ($next === null) {
            return self::reply('A default scripted reply about the fares.');
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function calls(): int
    {
        return count($this->requests);
    }
}
