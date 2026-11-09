<?php

use App\Ai\AiCall;
use App\Ai\AiFeature;
use App\Ai\AiGateway;
use App\Ai\AiOutcome;
use App\Ai\AiSettings;
use App\Ai\AiTask;
use App\Ai\CircuitBreaker;
use App\Ai\Contracts\AiProvider;
use App\Ai\Drivers\AnthropicAiProvider;
use App\Ai\Drivers\LogAiProvider;
use App\Ai\Exceptions\AiProviderTimeout;
use App\Ai\Exceptions\AiProviderUnavailable;
use App\Models\Ai\AiUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Ai;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->org = Org::make();
    Ai::license(credits: 100);
    Ai::enable($this->org->owner, AiFeature::Inbox);
});

describe('outages', function () {
    beforeEach(fn () => $this->fake = Ai::fake());

    it('opens the circuit after five consecutive failures and stops calling the provider', function () {
        $this->fake->fail(new AiProviderUnavailable('down'), times: 4)->fail(new AiProviderTimeout('slow'));

        foreach (range(1, 5) as $_) {
            expect(app(AiGateway::class)->run(Ai::replyCall())->ok())->toBeFalse();
        }
        expect(app(CircuitBreaker::class)->isOpen())->toBeTrue();

        $this->fake->respond(['reply' => 'أهلاً']);
        expect(app(AiGateway::class)->run(Ai::replyCall())->outcome)->toBe(AiOutcome::Unavailable);
        $this->fake->assertSentCount(5);

        // After the cool-down the provider is tried again, and an answer closes the circuit.
        $this->travel(301)->seconds();
        expect(app(AiGateway::class)->run(Ai::replyCall())->ok())->toBeTrue()
            ->and(app(CircuitBreaker::class)->isOpen())->toBeFalse();

        expect(AiUsage::query()->pluck('outcome')->map->value->countBy()->all())
            ->toMatchArray(['unavailable' => 5, 'timeout' => 1, 'ok' => 1])
            ->and(AiUsage::query()->where('outcome', '!=', 'ok')->sum('credits'))->toBe(0);
    });

    it('resets the failure count when the provider answers', function () {
        $this->fake->fail(new AiProviderUnavailable('down'), times: 4)->respond(['reply' => 'x'])->fail(new AiProviderUnavailable('down'));

        foreach (range(1, 6) as $_) {
            app(AiGateway::class)->run(Ai::replyCall());
        }

        expect(app(CircuitBreaker::class)->isOpen())->toBeFalse();
    });

    it('treats a refusal or a truncated answer as a fallback to the manual path', function () {
        $this->fake->respond(['reply' => 'x'], stopReason: 'refusal')->respond('{"reply": "نص مقطو', stopReason: 'max_tokens');

        expect(app(AiGateway::class)->run(Ai::replyCall())->outcome)->toBe(AiOutcome::Refused)
            ->and(app(AiGateway::class)->run(Ai::replyCall())->outcome)->toBe(AiOutcome::Invalid);
    });
});

describe('feature switches and license', function () {
    beforeEach(fn () => $this->fake = Ai::fake());

    it('does nothing when the facility has not turned the feature on', function () {
        $this->fake->respond(['answer' => 'x']);
        $call = new AiCall(AiTask::OrderDraft, 's', 'i', ['type' => 'object']);

        expect(app(AiGateway::class)->run($call)->outcome)->toBe(AiOutcome::FeatureOff);
        $this->fake->assertNothingSent();
        expect(AiUsage::query()->count())->toBe(0);
    });

    it('does nothing when the license lacks the capability', function () {
        Ai::license(credits: 100, capabilities: ['ai_core']); // inbox needs ai_plus
        $this->fake->respond(['reply' => 'x']);

        expect(app(AiGateway::class)->run(Ai::replyCall())->outcome)->toBe(AiOutcome::FeatureOff);
        $this->fake->assertNothingSent();
    });

    it('keeps the help assistant on by default and every other feature off', function () {
        $settings = app(AiSettings::class);

        expect(app(AiGateway::class)->available(AiFeature::Help))->toBeTrue()
            ->and(collect(AiFeature::cases())->filter(fn ($f) => $f !== AiFeature::Help && $f !== AiFeature::Inbox)
                ->map(fn ($f) => $settings->enabled($f))->unique()->values()->all())->toBe([false]);
    });
});

describe('nothing leaves the server', function () {
    it('uses the log driver by default and records metadata only', function () {
        Http::preventStrayRequests();
        Log::spy();
        config(['ai.driver' => null]);
        app()->forgetInstance(AiProvider::class);

        expect(config('ai.driver'))->toBeNull()
            ->and(app(AiProvider::class))->toBeInstanceOf(LogAiProvider::class);

        $result = app(AiGateway::class)->run(Ai::replyCall('رد على 0551234567', facts: ['customer.email' => 'sara@example.com']));

        expect($result->outcome)->toBe(AiOutcome::NotSent)
            ->and($result->output)->toBeNull()
            ->and(AiUsage::query()->sole())->outcome->toBe(AiOutcome::NotSent)->credits->toBe(0)->driver->toBe('log');

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) {
            $dump = json_encode($context, JSON_UNESCAPED_UNICODE);

            return $message === 'AI request not sent (log driver)'
                && ! str_contains($dump, '0551234567') && ! str_contains($dump, 'sara@') && ! str_contains($dump, 'رد على');
        })->once();
    });

    it('ships log as the configured default', function () {
        $config = require config_path('ai.php');

        expect($config['driver'])->toBe('log')
            ->and(file_get_contents(base_path('.env.example')))->toContain('AI_DRIVER=log')->toContain('AI_ANTHROPIC_ENABLED=false');
    });

    it('refuses to send with the anthropic driver unless explicitly enabled with a key', function (bool $enabled, ?string $key) {
        config([
            'ai.driver' => 'anthropic',
            'ai.anthropic.enabled' => $enabled,
            'ai.anthropic.api_key' => $key,
            // Any attempt to send would fail as unreachable, not as disabled.
            'ai.anthropic.base_url' => 'http://127.0.0.1:9',
        ]);
        app()->forgetInstance(AiProvider::class);
        expect(app(AiProvider::class))->toBeInstanceOf(AnthropicAiProvider::class);

        expect(app(AiGateway::class)->run(Ai::replyCall())->outcome)->toBe(AiOutcome::DriverDisabled)
            ->and(AiUsage::query()->sole()->credits)->toBe(0)
            ->and(app(CircuitBreaker::class)->isOpen())->toBeFalse();
    })->with([
        'switch off, key set' => [false, 'sk-test-not-real'],
        'switch on, no key' => [true, null],
        'both missing' => [false, null],
    ]);
});

describe('usage records', function () {
    it('records tokens and cost per model, never the prompt or the answer', function () {
        config(['ai.models.standard' => 'claude-sonnet-5-5']);
        $fake = Ai::fake();
        $fake->respond(['reply' => 'سرّي جداً'], inputTokens: 6000, outputTokens: 600);

        app(AiGateway::class)->run(Ai::replyCall('تعليمات خاصة'));

        $row = AiUsage::query()->sole();
        // 6000 × $2/MTok + 600 × $10/MTok = 18,000 micro-dollars.
        expect($row->cost_micro_usd)->toBe(18000)
            ->and($row->input_tokens)->toBe(6000)
            ->and($row->credits)->toBe(1)
            ->and(json_encode($row->getAttributes(), JSON_UNESCAPED_UNICODE))->not->toContain('سرّي')->not->toContain('تعليمات');
    });
});
