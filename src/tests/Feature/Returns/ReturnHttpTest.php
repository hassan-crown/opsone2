<?php

/**
 * HTTP contract of the Return* screens (docs/p7-inventory-api.md «Returns»): JSON and Inertia answers, idempotent
 * writes, 409 for state / stale version, and amounts present only with P-37 — the keys are omitted, never 0.
 */

use App\Models\Returns\OrderReturn;
use App\Models\Returns\ReturnSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/ReturnTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = returnFixture(10, 5, 3);
});

it('walks request → accept → receive with inspection → settlement through HTTP', function () {
    $f = $this->f;
    $this->actingAs($f->keeper)->getJson("/orders/{$f->order->id}/returns/create")->assertOk()
        ->assertJsonPath('lines.0.eligible', '3')->assertJsonPath('window_days', 14)
        ->assertJsonMissingPath('lines.0.unit_price_minor');

    $store = ['idempotency_key' => 'rt-http-0001', 'reason' => 'لون مختلف', 'lines' => [['order_line_id' => $f->line, 'quantity' => '2']]];
    $created = $this->actingAs($f->keeper)->postJson("/orders/{$f->order->id}/returns", $store)->assertCreated();
    $this->actingAs($f->keeper)->postJson("/orders/{$f->order->id}/returns", $store)->assertCreated();
    expect(OrderReturn::query()->count())->toBe(1);
    $id = $created->json('return.id');
    $created->assertJsonPath('return.status', 'requested')->assertJsonPath('return.can.accept', false)
        ->assertJsonMissingPath('return.settlement_minor');

    $this->actingAs($f->manager)->getJson("/returns/{$id}")->assertOk()->assertJsonPath('return.can.accept', true);
    $this->actingAs($f->manager)->postJson("/returns/{$id}/accept", ['idempotency_key' => 'rt-http-0002', 'reason' => 'مقبول', 'lock_version' => 9])
        ->assertStatus(409)->assertJsonPath('code', 'stale_version');
    $accepted = $this->actingAs($f->manager)->postJson("/returns/{$id}/accept", ['idempotency_key' => 'rt-http-0003', 'reason' => 'مقبول', 'lock_version' => 1])->assertOk()
        ->assertJsonPath('return.status', 'accepted');
    expect($accepted->json('return.settlement.settlement_minor'))->toBeInt()->toBeGreaterThan(0);
    $this->actingAs($f->manager)->postJson("/returns/{$id}/reject", ['idempotency_key' => 'rt-http-0004', 'reason' => 'رفض متأخر'])
        ->assertStatus(409)->assertJsonPath('condition', 'not_from_accepted');

    $show = $this->actingAs($f->keeper)->getJson("/returns/{$id}")->assertOk()->assertJsonPath('return.can.receive', true);
    expect(array_column($show->json('return.locations'), 'id'))->toContain($f->location->id);
    $this->actingAs($f->keeper)->postJson("/returns/{$id}/receive", [
        'idempotency_key' => 'rt-http-0005', 'lock_version' => $show->json('return.lock_version'), 'location_id' => $f->location->id, 'inspect' => true,
        'lines' => [['order_line_id' => $f->line, 'quantity' => '2', 'good' => '1', 'damaged' => '1', 'rejected' => '0']],
    ])->assertOk()->assertJsonPath('return.status', 'inspected')->assertJsonPath('return.lines.0.good', '1')
        ->assertJsonPath('return.lines.0.damaged', '1')->assertJsonPath('return.parts.0.inspected', true);
});

it('hides every amount without P-37 and shows them with it', function () {
    $f = $this->f;
    $return = rtRequest($f->keeper, $f->order, $f->line, '1');
    rtAccept($f->manager, $return);

    $hidden = $this->actingAs($f->keeper)->getJson("/returns/{$return->id}")->assertOk();
    expect($hidden->json('return.settlement'))->toBeNull()
        ->and($hidden->json('return'))->not->toHaveKey('settlement_minor')
        ->and($hidden->json('return.lines.0'))->not->toHaveKeys(['value_minor', 'unit_price_minor']);
    $this->actingAs($f->keeper)->getJson('/returns')->assertOk()->assertJsonMissingPath('returns.data.0.settlement_minor');

    $shown = $this->actingAs($f->manager)->getJson("/returns/{$return->id}")->assertOk();
    expect($shown->json('return.settlement.value_minor'))->toBeInt()
        ->and($shown->json('return.lines.0'))->toHaveKeys(['value_minor', 'unit_price_minor']);
    $this->actingAs($f->manager)->getJson('/returns')->assertOk()->assertJsonPath('returns.data.0.settlement_minor', $return->refresh()->settlement_minor);
});

it('renders the Return* screens and keeps the window setting for P-02 only', function () {
    $f = $this->f;
    $return = rtRequest($f->keeper, $f->order, $f->line, '1');

    $this->actingAs($f->keeper)->get('/returns')->assertOk()->assertInertia(fn (Assert $page) => $page->component('returns/index')
        ->has('eligible', 1)->has('returns.data', 1)->where('settings.can_edit', false));
    $this->actingAs($f->keeper)->get("/orders/{$f->order->id}/returns/create")->assertOk()->assertInertia(fn (Assert $page) => $page->component('returns/form'));
    $this->actingAs($f->manager)->get("/returns/{$return->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('returns/show')
        ->where('return.number', $return->number)->where('return.can.accept', true));

    $this->actingAs($f->keeper)->putJson('/returns/settings', ['window_days' => 30])->assertForbidden();
    $this->actingAs($f->owner)->putJson('/returns/settings', ['window_days' => 30])->assertOk()->assertJsonPath('window_days', 30);
    expect(ReturnSettings::current()->window_days)->toBe(30)
        // The window in force at the request stays with it.
        ->and($return->refresh()->window_days)->toBe(14);
});

it('flags a request past the window as an indicator only', function () {
    $f = $this->f;
    $this->travel(20)->days();
    $return = rtRequest($f->keeper, $f->order, $f->line, '1');
    $this->actingAs($f->manager)->getJson("/returns/{$return->id}")->assertOk()->assertJsonPath('return.overdue', true)->assertJsonPath('return.can.accept', true);
    expect(rtAccept($f->manager, $return)->status->value)->toBe('accepted');
});
