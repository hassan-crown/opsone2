<?php

namespace App\Ai\Drivers;

use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\Contracts\AiProvider;
use Closure;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * Test driver (CI never calls a provider). Queue answers with respond()/fail(); every request is recorded as the
 * driver received it (already redacted) so tests can assert what would have left the server.
 */
final class FakeAiProvider implements AiProvider
{
    /** @var list<AiRequest> */
    private array $sent = [];

    /** @var list<AiResponse|Throwable|Closure(AiRequest): AiResponse> */
    private array $queue = [];

    public function name(): string
    {
        return 'fake';
    }

    /**
     * Queue a successful answer. Arrays are encoded as the model's JSON text.
     *
     * @param  array<mixed>|string  $output
     */
    public function respond(array|string $output, string $stopReason = AiResponse::STOP_END, int $inputTokens = 1000, int $outputTokens = 100): self
    {
        $text = is_array($output) ? (string) json_encode($output, JSON_UNESCAPED_UNICODE) : $output;
        $this->queue[] = new AiResponse($text, $stopReason, 'fake-model', $inputTokens, $outputTokens);

        return $this;
    }

    /**
     * @param  Closure(AiRequest): AiResponse  $answer
     */
    public function respondWith(Closure $answer): self
    {
        $this->queue[] = $answer;

        return $this;
    }

    public function fail(Throwable $error, int $times = 1): self
    {
        for ($i = 0; $i < $times; $i++) {
            $this->queue[] = $error;
        }

        return $this;
    }

    public function complete(AiRequest $request): AiResponse
    {
        $this->sent[] = $request;
        $next = array_shift($this->queue) ?? new AiResponse('{}', AiResponse::STOP_END, $request->model);

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next instanceof Closure ? $next($request) : new AiResponse(
            $next->text, $next->stopReason, $request->model, $next->inputTokens, $next->outputTokens, $next->cacheReadTokens, $next->cacheWriteTokens,
        );
    }

    /**
     * @return list<AiRequest>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    public function assertSentCount(int $count): void
    {
        Assert::assertCount($count, $this->sent, 'Unexpected number of AI requests.');
    }

    public function assertNothingSent(): void
    {
        $this->assertSentCount(0);
    }
}
