<?php

use App\Ai\AiFeature;
use App\Ai\AiSettings;
use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Ai;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->org = Org::make();
    Ai::license(credits: 300, capabilities: ['ai_core']);
});

it('shows the switches, credits and privacy state to a P-02 holder without any secret', function () {
    config(['ai.anthropic.api_key' => 'sk-should-never-show']);

    $this->actingAs($this->org->owner)->get(route('ai-settings.edit'))
        ->assertOk()
        ->assertDontSee('sk-should-never-show')
        ->assertInertia(fn (Assert $page) => $page->component('account/settings-ai')
            ->where('driver', 'log')
            ->where('sendsExternally', false)
            ->where('credits.limit', 300)
            ->where('credits.used', 0)
            ->has('features', count(AiFeature::cases()))
            ->where('features.0', ['key' => 'help', 'enabled' => true, 'capability' => 'ai_core', 'licensed' => true])
            ->where('features.4.key', 'inbox')
            ->where('features.4.enabled', false)
            ->where('features.4.licensed', false));
});

it('saves the switches and audits only what changed', function () {
    $this->actingAs($this->org->owner)
        ->put(route('ai-settings.update'), ['features' => ['digest' => true, 'help' => false]])
        ->assertRedirect();

    $settings = app(AiSettings::class);
    expect($settings->enabled(AiFeature::Digest))->toBeTrue()
        ->and($settings->enabled(AiFeature::Help))->toBeFalse()
        ->and($settings->enabled(AiFeature::Inbox))->toBeFalse();

    $event = AuditEvent::query()->where('action', 'ai.settings_changed')->sole();
    expect($event->after)->toBe(['features' => ['help' => false, 'digest' => true]]);
});

it('refuses members without P-02 and unknown features', function () {
    $sales = $this->org->member('sales', [$this->org->a]);

    $this->actingAs($sales)->get(route('ai-settings.edit'))->assertForbidden();
    $this->actingAs($sales)->put(route('ai-settings.update'), ['features' => ['help' => false]])->assertForbidden();

    $this->actingAs($this->org->owner)->put(route('ai-settings.update'), ['features' => ['shell' => true]])
        ->assertSessionHasErrors('features');
});
