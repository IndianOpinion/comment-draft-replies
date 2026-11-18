<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Tests\Integration;

use IndianOpinion\CommentDraftReplies\Generation\CallException;
use IndianOpinion\CommentDraftReplies\Generation\LlmJsonCaller;
use IndianOpinion\CommentDraftReplies\Generation\Prompt;
use IndianOpinion\CommentDraftReplies\Tests\Support\TestCase;
use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Store\MemoryStore;
use IndianOpinion\LlmJson\Testing\FrozenClock;
use IndianOpinion\LlmJson\Testing\MockTransport;
use IndianOpinion\LlmJson\Testing\RecordingSleeper;

/**
 * The llm-json-php adapter against the library's own mock transport: a
 * real client, the real wire format, no network.
 */
final class LlmJsonCallerTest extends TestCase
{
    private function useClient(MockTransport $mock): void
    {
        $clock = new FrozenClock();
        $client = Client::create(Chain::of(Route::to(Provider::openRouter('test-key-123456'), 'test/model')), $mock)
            ->withStore(new MemoryStore($clock))
            ->withClock($clock)
            ->withSleeper(new RecordingSleeper($clock));
        add_filter('cdreplies_client', static fn () => $client);
    }

    /** @return array{purpose:string,system:string,user:string,schema:array<string,mixed>,max_output_tokens:int,deadline:int} */
    private static function request(): array
    {
        return [
            'purpose' => 'comment-draft-replies.reply',
            'system' => Prompt::builtinSystem(),
            'user' => 'The comment to reply to: when does the fare rise?',
            'schema' => Prompt::schema(),
            'max_output_tokens' => 600,
            'deadline' => 60,
        ];
    }

    private static function caller(): LlmJsonCaller
    {
        return new LlmJsonCaller('https://openrouter.ai/api/v1', 'test-key-123456', 'test/model', '');
    }

    public function testAnswerDecodesThroughTheLibrary(): void
    {
        $mock = new MockTransport(MockTransport::completion(
            ['action' => 'reply', 'reply' => 'It rises in March.', 'reason' => 'Answers the date.', 'language' => 'en', 'flags' => ['question']],
            'stop',
            ['prompt_tokens' => 321, 'completion_tokens' => 12, 'total_tokens' => 333],
            'test/model'
        ));
        $this->useClient($mock);

        $answer = self::caller()->call(self::request());

        self::assertSame('reply', $answer->data['action']);
        self::assertSame('It rises in March.', $answer->data['reply']);
        self::assertSame('test/model', $answer->model);
        self::assertSame(321, $answer->tokensIn);
        self::assertSame(12, $answer->tokensOut);
        self::assertCount(1, $mock->requests());
        $sent = $mock->lastRequest();
        self::assertNotNull($sent);
        $body = json_decode($sent->body, true);
        self::assertIsArray($body);
        self::assertSame('test/model', $body['model']);
        self::assertStringContainsString('when does the fare rise?', (string) json_encode($body['messages']));
        self::assertSame(0, $mock->remaining());
    }

    public function testAccountRefusalBecomesAHold(): void
    {
        $this->useClient(new MockTransport(MockTransport::status(401, '{"error":{"message":"bad key"}}', ['content-type' => 'application/json'])));
        try {
            self::caller()->call(self::request());
            self::fail('Expected a CallException');
        } catch (CallException $e) {
            self::assertSame(CallException::ACCOUNT, $e->kind());
            self::assertStringNotContainsString('test-key-123456', $e->getMessage());
        }
    }

    public function testServerErrorsBecomeARetry(): void
    {
        $mock = new MockTransport();
        for ($i = 0; $i < 10; $i++) {
            $mock->push(MockTransport::status(503, 'busy'));
        }
        $this->useClient($mock);
        try {
            self::caller()->call(self::request());
            self::fail('Expected a CallException');
        } catch (CallException $e) {
            self::assertSame(CallException::RETRYABLE, $e->kind());
        }
    }

    public function testBrokenJsonBecomesAnOutputRejection(): void
    {
        $mock = new MockTransport();
        for ($i = 0; $i < 10; $i++) {
            $mock->push(MockTransport::completion('{"action": "reply", "reply": '));
        }
        $this->useClient($mock);
        try {
            self::caller()->call(self::request());
            self::fail('Expected a CallException');
        } catch (CallException $e) {
            self::assertSame(CallException::OUTPUT, $e->kind());
            self::assertTrue($e->billed());
        }
    }
}
