<?php

use App\Ai\AiRequest;
use App\Ai\AiTask;
use App\Ai\Drivers\AnthropicAiProvider;
use App\Ai\Exceptions\AiProviderRejected;
use App\Ai\Exceptions\AiProviderUnavailable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * The real driver against a mocked HTTP transport: nothing leaves the test.
 */
beforeEach(function () {
    config(['ai.anthropic.enabled' => true, 'ai.anthropic.api_key' => 'sk-test-not-real', 'ai.anthropic.retries' => 2]);
    $this->history = [];
    $this->timeouts = [];
    $this->driver = function (array $responses): AnthropicAiProvider {
        return new AnthropicAiProvider(function (int $timeout, int $connect) use ($responses) {
            $this->timeouts[] = [$timeout, $connect];
            $stack = HandlerStack::create(new MockHandler($responses));
            $stack->push(Middleware::history($this->history));

            return new Client(['handler' => $stack]);
        });
    };
    $this->request = fn (AiTask $task) => new AiRequest($task, 'claude-sonnet-5-5', 'low', 512, $task->timeoutSeconds(), 'sys', 'content', ['type' => 'object']);
});

it('sends a structured-output request and reads the answer, stop reason and usage', function () {
    $body = ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
        'content' => [['type' => 'text', 'text' => '{"reply":"أهلاً"}']], 'stop_reason' => 'end_turn', 'stop_sequence' => null,
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'cache_read_input_tokens' => 3, 'cache_creation_input_tokens' => 2]];

    $response = ($this->driver)([new Response(200, ['Content-Type' => 'application/json'], json_encode($body))])
        ->complete(($this->request)(AiTask::ReplySuggest));

    $sent = json_decode((string) $this->history[0]['request']->getBody(), true);
    expect($response->text)->toBe('{"reply":"أهلاً"}')
        ->and($response->stopReason)->toBe('end_turn')
        ->and([$response->inputTokens, $response->outputTokens, $response->cacheReadTokens, $response->cacheWriteTokens])->toBe([10, 5, 3, 2])
        ->and($sent['output_config'])->toBe(['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => ['type' => 'object']]])
        ->and($sent['system'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($this->history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer sk-test-not-real');
});

it('keeps an interactive task within its total timeout: one retry, half the budget each', function () {
    $driver = ($this->driver)([new Response(503), new Response(503), new Response(503)]);

    expect(fn () => $driver->complete(($this->request)(AiTask::ReplySuggest)))->toThrow(AiProviderUnavailable::class);
    expect(count($this->history))->toBe(2)
        ->and($this->timeouts[0])->toBe([10, 5]);
});

it('gives background tasks the configured retries with the full timeout per attempt', function () {
    $driver = ($this->driver)([new Response(500), new Response(500), new Response(500)]);

    expect(fn () => $driver->complete(($this->request)(AiTask::DigestDaily)))->toThrow(AiProviderUnavailable::class);
    expect(count($this->history))->toBe(3)
        ->and($this->timeouts[0])->toBe([120, 5]);
});

it('reports key and request errors as rejected, without retrying', function (int $status) {
    $driver = ($this->driver)([new Response($status, ['Content-Type' => 'application/json'], '{"type":"error","error":{"type":"x","message":"m"}}')]);

    expect(fn () => $driver->complete(($this->request)(AiTask::ReplySuggest)))->toThrow(AiProviderRejected::class);
    expect(count($this->history))->toBe(1);
})->with([400, 401, 403, 422]);
