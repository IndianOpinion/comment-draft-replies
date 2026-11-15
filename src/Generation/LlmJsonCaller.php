<?php

declare(strict_types=1);

namespace IndianOpinion\CommentDraftReplies\Generation;

use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\Exception\ChainExhaustedException;
use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\LlmJson\Exception\CreditExhaustedException;
use IndianOpinion\LlmJson\Exception\LlmJsonException;
use IndianOpinion\LlmJson\Options;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Request;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Schema;
use IndianOpinion\LlmJson\Store\WordPressTransientStore;
use IndianOpinion\LlmJson\Transport\WordPressTransport;

/**
 * The default caller: llm-json-php over the WordPress HTTP API. All contact
 * with the library lives here.
 */
final class LlmJsonCaller implements ModelCaller
{
    public const CLIENT_CLASS = Client::class;
    public const STORE_PREFIX = 'cdreplies_llmjson';

    public function __construct(
        private readonly string $baseUrl,
        #[\SensitiveParameter] private readonly string $key,
        private readonly string $model,
        private readonly string $fallbackModel
    ) {
    }

    public static function libraryAvailable(): bool
    {
        return class_exists(self::CLIENT_CLASS);
    }

    private function client(): Client
    {
        $supplied = apply_filters('cdreplies_client', null);
        if ($supplied instanceof Client) {
            return $supplied;
        }
        $host = (string) parse_url($this->baseUrl, PHP_URL_HOST);
        if ($host === 'openrouter.ai') {
            $provider = Provider::openRouter($this->key, home_url('/'), (string) get_bloginfo('name'));
        } else {
            $provider = Provider::generic('custom', $this->baseUrl, $this->key)->withStructuredOutputs(false, true);
        }
        $routes = [Route::to($provider, $this->model)];
        if ($this->fallbackModel !== '') {
            $routes[] = Route::to($provider, $this->fallbackModel);
        }

        return Client::create(Chain::of(...$routes), new WordPressTransport())
            ->withStore(new WordPressTransientStore())
            ->withOptions(Options::defaults()->withStoreKeyPrefix(self::STORE_PREFIX));
    }

    public function call(array $request): ModelAnswer
    {
        try {
            $result = $this->client()->complete(
                Request::for($request['purpose'], Schema::fromArray('comment_reply', $request['schema']))
                    ->withSystem($request['system'])
                    ->withInput($request['user'])
                    ->withTokenCap($request['max_output_tokens'])
                    ->withDeadline((float) $request['deadline'])
            );
        } catch (CreditExhaustedException $e) {
            throw new CallException(CallException::ACCOUNT, 'Provider is out of credit.', false, 'credit');
        } catch (ChainExhaustedException $e) {
            throw $this->fromChain($e);
        } catch (ConfigurationException $e) {
            throw new CallException(CallException::PERMANENT, 'Provider configuration is invalid.', false, 'config');
        } catch (LlmJsonException $e) {
            throw new CallException(CallException::RETRYABLE, 'Model library error.');
        }
        $usage = $result->totalUsage();

        return new ModelAnswer($result->data(), $result->model(), $usage->promptTokens, $usage->completionTokens);
    }

    private function fromChain(ChainExhaustedException $e): CallException
    {
        $billed = false;
        $output = false;
        $retryable = $e->isDeadline();
        foreach ($e->attempts() as $attempt) {
            $outcome = $attempt->outcome;
            if ($outcome->isAccountLevel()) {
                return new CallException(CallException::ACCOUNT, 'Provider refused the account.', false, 'auth');
            }
            if (in_array($outcome->value, ['malformed', 'schema_violation', 'guard_rejected', 'truncated'], true)) {
                $output = true;
                $billed = true;
            } elseif ($outcome->isRetryable()) {
                $retryable = true;
            }
        }
        if ($output && !$retryable) {
            return new CallException(CallException::OUTPUT, 'Model output failed the schema or guard.', $billed, 'schema');
        }
        if ($retryable) {
            return new CallException(CallException::RETRYABLE, 'Provider error; will retry.', $billed);
        }

        return new CallException(CallException::PERMANENT, 'Provider refused the request.', $billed);
    }
}
