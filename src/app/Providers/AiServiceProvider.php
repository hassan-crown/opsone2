<?php

namespace App\Providers;

use App\Access\Permission;
use App\Ai\AiCredits;
use App\Ai\Contracts\AiProvider;
use App\Ai\Drivers\AnthropicAiProvider;
use App\Ai\Drivers\FakeAiProvider;
use App\Ai\Drivers\LogAiProvider;
use App\Ai\Insights\DailyDigest;
use App\Notifications\NotificationCatalogue;
use Illuminate\Support\ServiceProvider;

/**
 * AI assistant (ADR-0050). The driver comes from AI_DRIVER: log (default — nothing leaves the server) | fake (tests)
 * | anthropic (refuses unless AI_ANTHROPIC_ENABLED=true and a key is set).
 */
class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiProvider::class, fn () => match (config('ai.driver')) {
            'anthropic' => new AnthropicAiProvider,
            'fake' => new FakeAiProvider,
            default => new LogAiProvider,
        });
    }

    public function boot(): void
    {
        NotificationCatalogue::define(AiCredits::NOTICE, [
            'mandatory' => false,
            'permission' => Permission::P02,
            'title' => 'ai.notify.credits_low.title',
            'body' => 'ai.notify.credits_low.body',
            'route' => 'ai-settings.edit',
        ]);

        // AI-8: the morning digest. Opening it re-checks P-60; the page is the executive dashboard.
        NotificationCatalogue::define(DailyDigest::NOTICE, [
            'mandatory' => false,
            'permission' => Permission::P60,
            'title' => 'ai.notify.digest.title',
            'body' => 'ai.notify.digest.body',
            'route' => 'executive',
        ]);
    }
}
