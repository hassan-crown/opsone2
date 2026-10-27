<?php

use App\Jobs\DeliverOutboxMessage;
use App\Models\AuditEvent;
use App\Models\OutboxMessage;
use App\Models\SupportRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
    $this->valid = ['category' => 'problem', 'subject' => 'Cannot add a branch', 'message' => 'The add button does nothing for me.'];
});

it('lets any staff member open the form', function () {
    $sales = $this->org->member('sales', [$this->org->a]);

    $this->actingAs($sales)->get(route('help.support'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('help/support-request')->where('canGrantAccess', false));
});

it('tells the owner they can grant temporary access', function () {
    $this->actingAs($this->org->owner)->get(route('help.support'))
        ->assertInertia(fn (Assert $page) => $page->where('canGrantAccess', true));
});

it('stores a request without sending any mail', function () {
    Mail::fake();
    $sales = $this->org->member('sales', [$this->org->a]);

    $this->actingAs($sales)->post(route('help.support.store'), [...$this->valid, 'page_path' => '/org/branches'])
        ->assertRedirect(route('help.support'));

    $request = SupportRequest::query()->sole();
    expect($request->user_id)->toBe($sales->id)
        ->and($request->status)->toBe('pending')
        ->and($request->page_path)->toBe('/org/branches');
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

it('sends the request to OpsOne support through the outbox and audits it', function () {
    config(['opsone.support.email' => 'support@opsone.test']);
    Queue::fake();
    $sales = $this->org->member('sales', [$this->org->a]);

    $this->actingAs($sales)->post(route('help.support.store'), $this->valid)->assertRedirect();

    $request = SupportRequest::query()->sole();
    $message = OutboxMessage::query()->sole();
    expect($request->status)->toBe('queued')
        ->and($message->recipient)->toBe('support@opsone.test')
        ->and($message->idempotency_key)->toBe("support-request:{$request->id}:mail")
        ->and($message->payload['title'])->toContain($request->reference())
        ->and(AuditEvent::query()->where('action', 'support.request_submitted')->where('actor_user_id', $sales->id)->exists())->toBeTrue();
    Queue::assertPushed(DeliverOutboxMessage::class, fn ($job) => $job->outboxId === $message->id);
});

it('validates the fields', function (array $override, string $field) {
    $this->actingAs($this->org->owner)->post(route('help.support.store'), [...$this->valid, ...$override])
        ->assertSessionHasErrors($field);
    expect(SupportRequest::query()->count())->toBe(0);
})->with([
    'bad category' => [['category' => 'nope'], 'category'],
    'short subject' => [['subject' => 'Hi'], 'subject'],
    'missing message' => [['message' => ''], 'message'],
    'short message' => [['message' => 'too short'], 'message'],
    'long message' => [['message' => str_repeat('x', 5001)], 'message'],
]);

it('drops an external page path instead of storing it', function () {
    $this->actingAs($this->org->owner)->post(route('help.support.store'), [...$this->valid, 'page_path' => 'https://evil.example/x']);

    expect(SupportRequest::query()->sole()->page_path)->toBeNull();
});

it('denies an account without a staff membership', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->get(route('help.support'))->assertForbidden();
    $this->actingAs($outsider)->post(route('help.support.store'), $this->valid)->assertForbidden();
    expect(SupportRequest::query()->count())->toBe(0);
});

it('denies a disabled member', function () {
    $sales = $this->org->member('sales', [$this->org->a]);
    Org::membershipOf($sales)->forceFill(['status' => 'disabled'])->save();

    // The access middleware ends a disabled member's session before the request reaches the form.
    $this->actingAs($sales)->post(route('help.support.store'), $this->valid)->assertRedirect();
    expect(SupportRequest::query()->count())->toBe(0);
});

it('requires sign-in', function () {
    $this->post(route('help.support.store'), $this->valid)->assertRedirect(route('login'));
});
