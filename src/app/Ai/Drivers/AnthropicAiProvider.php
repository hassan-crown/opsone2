<?php

namespace App\Ai\Drivers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Messages\OutputConfig\Effort;
use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\Contracts\AiProvider;
use App\Ai\Exceptions\AiDriverDisabled;
use App\Ai\Exceptions\AiProviderTimeout;
use App\Ai\Exceptions\AiProviderUnavailable;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\Log;

/**
 * Claude API through the official PHP SDK (`anthropic-ai/sdk`, ADR-0050, Q-89).
 *
 * Refuses to send unless config `ai.anthropic.enabled` is true AND a key is set (Q-94: no real use before the PDPL
 * review). One structured-output request per call: the stable system prompt is a cached prefix, the JSON schema is
 * enforced by the API (`output_config.format`), effort comes from the task. Thinking is left at the model default
 * (adaptive). The timeout is enforced by the HTTP transport, the SDK retries connection errors, 408/409/429 and 5xx.
 * Errors are logged by class and status only — never the request or the provider's body.
 */
final class AnthropicAiProvider implements AiProvider
{
    public function name(): string
    {
        return 'anthropic';
    }

    public function complete(AiRequest $request): AiResponse
    {
        $key = (string) config('ai.anthropic.api_key');
        if (config('ai.anthropic.enabled') !== true || $key === '') {
            throw new AiDriverDisabled('The anthropic driver is not enabled for this deployment.');
        }

        $client = new Client(
            apiKey: $key,
            baseUrl: (string) config('ai.anthropic.base_url'),
            openTelemetry: false,
        );

        try {
            $message = $client->messages->create(
                maxTokens: $request->maxTokens,
                messages: [['role' => 'user', 'content' => $request->content]],
                model: $request->model,
                outputConfig: [
                    'effort' => Effort::tryFrom($request->effort) ?? Effort::MEDIUM,
                    'format' => ['type' => 'json_schema', 'schema' => $request->schema],
                ],
                system: [['type' => 'text', 'text' => $request->system, 'cacheControl' => ['type' => 'ephemeral']]],
                requestOptions: [
                    'maxRetries' => (int) config('ai.anthropic.retries', 2),
                    'transporter' => new Guzzle([
                        'timeout' => $request->timeoutSeconds,
                        'connect_timeout' => (int) config('ai.anthropic.connect_timeout', 5),
                    ]),
                ],
            );
        } catch (APIConnectionException $e) {
            if ($e instanceof APITimeoutException || self::timedOut($e)) {
                throw new AiProviderTimeout('The AI provider timed out.', 0, $e);
            }
            self::logFailure($request, $e);

            throw new AiProviderUnavailable('The AI provider could not be reached.', 0, $e);
        } catch (APIException $e) {
            self::logFailure($request, $e);

            throw new AiProviderUnavailable('The AI provider returned an error.', 0, $e);
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text' && property_exists($block, 'text')) {
                $text .= (string) $block->text;
            }
        }

        return new AiResponse(
            text: $text,
            stopReason: (string) ($message->stopReason ?? AiResponse::STOP_END),
            model: $message->model,
            inputTokens: $message->usage->inputTokens,
            outputTokens: $message->usage->outputTokens,
            cacheReadTokens: (int) ($message->usage->cacheReadInputTokens ?? 0),
            cacheWriteTokens: (int) ($message->usage->cacheCreationInputTokens ?? 0),
        );
    }

    private static function timedOut(APIConnectionException $e): bool
    {
        $previous = $e->getPrevious();

        // cURL error 28 = operation timed out (Guzzle puts the cURL error in the message).
        return $previous instanceof ConnectException && str_contains($previous->getMessage(), 'cURL error 28');
    }

    private static function logFailure(AiRequest $request, APIException $e): void
    {
        Log::warning('AI provider call failed', [
            'task' => $request->task->value,
            'model' => $request->model,
            'exception' => $e::class,
            'status' => $e instanceof APIStatusException ? $e->status : null,
        ]);
    }
}
