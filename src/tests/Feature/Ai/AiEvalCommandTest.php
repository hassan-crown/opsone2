<?php

use App\Ai\Contracts\AiProvider;
use App\Models\Ai\AiUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Ai;

uses(RefreshDatabase::class);

it('checks every bundled example without sending anything on the log driver', function () {
    $this->artisan('ai:eval')->assertSuccessful()->expectsOutputToContain('driver log');

    expect(AiUsage::query()->where('outcome', '!=', 'not_sent')->count())->toBe(0)
        ->and(AiUsage::query()->sum('credits'))->toBe(0);
});

it('grades answers against the expectations with the fake driver', function () {
    Ai::fake()->respond(['intent' => 'order_status', 'confidence' => 0.9])->respond(['intent' => 'other', 'confidence' => 0.4]);

    $this->artisan('ai:eval', ['axis' => 'intent', '--limit' => 2])
        ->expectsOutputToContain('1 failure(s)')
        ->assertFailed();
});

it('refuses a paid driver without --confirm-cost', function () {
    config(['ai.driver' => 'anthropic']);
    app()->forgetInstance(AiProvider::class);

    $this->artisan('ai:eval')->expectsOutputToContain('--confirm-cost')->assertFailed();
    expect(AiUsage::query()->count())->toBe(0);
});
