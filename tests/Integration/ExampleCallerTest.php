<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Tests\Integration;

use IndianOpinion\CommentDraftReplies\Generation\Runner;
use IndianOpinion\CommentDraftReplies\Hold;
use IndianOpinion\CommentDraftReplies\Tests\Support\TestCase;

/** Ruling 7: the example caller in examples/ works end to end. */
final class ExampleCallerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        remove_all_filters('cdreplies_model_caller');
        Runner::$libraryOverride = false;
        require_once dirname(__DIR__, 2) . '/examples/http-api-caller.php';
        add_filter('cdreplies_model_caller', 'cdreplies_example_http_caller', 10, 2);
    }

    private function respond(int $code, string $content): void
    {
        \WPStub::$httpResponse = ['response' => ['code' => $code], 'body' => (string) json_encode(['choices' => [['message' => ['content' => $content]]]])];
    }

    public function testSuccessThroughTheHttpApi(): void
    {
        $this->respond(200, (string) json_encode(['action' => 'reply', 'reply' => 'Yes, season tickets too.', 'reason' => 'r', 'language' => 'en', 'flags' => []]));
        $id = $this->postComment();
        self::assertSame([], \WPStub::$http, 'no HTTP in the reader request');
        $this->plugin->runner->run();
        self::assertSame('ready', $this->draftFor($id)->status());
        self::assertCount(1, \WPStub::$http);
        self::assertSame('https://openrouter.ai/api/v1/chat/completions', \WPStub::$http[0][0]);
        self::assertSame(0, \WPStub::$http[0][1]['redirection']);
    }

    public function testAccountErrorStartsAHoldAndGarbageIsRejected(): void
    {
        \WPStub::$httpResponse = ['response' => ['code' => 402], 'body' => '{}'];
        $id = $this->postComment();
        $this->plugin->runner->run();
        self::assertNotNull(Hold::active());
        self::assertSame('queued', $this->draftFor($id)->status());
        Hold::clear();

        $this->respond(200, 'not json at all');
        $this->plugin->runner->run();
        self::assertSame('failed', $this->draftFor($id)->status());
        self::assertSame('schema', $this->draftFor($id)->lastReason());
    }
}
