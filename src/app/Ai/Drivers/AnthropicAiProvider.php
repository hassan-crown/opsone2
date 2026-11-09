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
use App\Ai\Exceptions\AiProviderRejected;
use App\Ai\Exceptions\AiProviderTimeout;
use App\Ai\Exceptions\AiProviderUnavailable;
use Closure;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\Log;
use Psr\Http\Client\ClientInterface;

/**
 * Claude API through the official PHP SDK (`anthropic-ai/sdk`, ADR-0050, Q-89).
 *
 * Refuses to send unless config `ai.anthropic.enabled` is true AND a key is set (Q-94: no real use before the PDPL
 * review). One structured-output request per call: the stable system prompt is a cached prefix, the JSON schema is
 * enforced by the API (`output_config.format`), effort comes from the task. Thinking is left at the model default
 * (adaptive). The timeout is enforced by the HTTP transport, the SDK retries connection errors, 408/409/429 and 5xx;
 * for interactive tasks the configured timeout is a total ceiling (see complete()).
 * Errors are logged by class and status only — never the request or the provider's body.
 */
final class AnthropicAiProvider implements AiProvider
{
    /**
     * @param  (Closure(int $timeout, int $connectTimeout): ClientInterface)|null  $transport  PSR-18 client per call;
     *                                                                                         Guzzle by default, a mock in tests
     */
    public function __construct(private readonly ?Closure $transport = null) {}

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

        // The task timeout is the TOTAL budget. Interactive tasks (someone is waiting) get at most one retry, and each
        // attempt gets an equal share, so the call ends within the budget plus the SDK's short backoff (≤ 1 s).
        // Background tasks keep the configured retries, each attempt with the full timeout.
        $retries = max(0, (int) config('ai.anthropic.retries', 2));
        if ($request->task->interactive()) {
            $retries = min($retries, 1);
            $attemptTimeout = max(1, intdiv($request->timeoutSeconds, $retries + 1));
        } else {
            $attemptTimeout = $request->timeoutSeconds;
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
                    'maxRetries' => $retries,
                    'transporter' => $this->transporter($attemptTimeout, min($attemptTimeout, (int) config('ai.anthropic.connect_timeout', 5))),
                ],
            );
        } catch (APIConnectionException $e) {
            if ($e instanceof APITimeoutException || self::timedOut($e)) {
                throw new AiProviderTimeout('The AI provider timed out.', 0, $e);
            }
            self::logFailure($request, $e);

            throw new AiProviderUnavailable('The AI provider could not be reached.', 0, $e);
        } catch (APIStatusException $e) {
            self::logFailure($request, $e);
            $status = (int) $e->status;
            if ($status >= 400 && $status < 500 && ! in_array($status, [408, 409, 429], true)) {
                throw new AiProviderRejected('The AI provider rejected the request.', 0, $e);
            }

            throw new AiProviderUnavailable('The AI provider returned an error.', 0, $e);
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

    private function transporter(int $timeout, int $connectTimeout): ClientInterface
    {
        return $this->transport !== null
            ? ($this->transport)($timeout, $connectTimeout)
            : new Guzzle(['timeout' => $timeout, 'connect_timeout' => $connectTimeout]);
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
