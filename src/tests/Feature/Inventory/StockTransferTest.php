<?php

/**
 * Transfers (FUL-04, M-25, P-50): linked exit and arrival; in transit the quantity is available at neither end; the
 * total across locations is unchanged to the thousandth; withdrawal before exit writes nothing.
 */

use App\Enums\StateMachine;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\StockTransfer;
use App\Models\StateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/InventoryTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->s = stockFixture();
    stockReceive($this->s->la, 'material', $this->s->material->id, '10.255');
    stockReceive($this->s->la, 'ready_variant', $this->s->f->variant->id, '4');
});

function transferPayload(object $s, string $qty = '3.125', bool $instant = false): array
{
    return [
        'from_location_id' => $s->la->id, 'to_location_id' => $s->la2->id, 'instant' => $instant,
        'lines' => [
            ['item_type' => 'material', 'item_id' => $s->material->id, 'quantity' => $qty],
            ['item_type' => 'ready_variant', 'item_id' => $s->f->variant->id, 'quantity' => '1'],
        ],
    ];
}

it('keeps in-transit stock unavailable at both ends, then sums exactly after arrival', function () {
    $year = now()->year;
    $transfer = $this->actingAs($this->s->keeper)->postJson('/stock/transfers', transferPayload($this->s))
        ->assertCreated()->assertJsonPath('transfer.status', 'draft')->assertJsonPath('transfer.number', "ST-{$year}-000001")->json('transfer');

    $this->actingAs($this->s->keeper)->postJson("/stock/transfers/{$transfer['id']}/dispatch", ['lock_version' => $transfer['lock_version']])
        ->assertOk()->assertJsonPath('transfer.status', 'in_transit');
    expect(stockBalance($this->s->la, 'material', $this->s->material->id)['on_hand'])->toBe('7.13')
        ->and(stockBalance($this->s->la2, 'material', $this->s->material->id)['available'])->toBe('0');

    $balances = $this->actingAs($this->s->keeper)->getJson("/stock?location_id={$this->s->la2->id}")->assertOk()->json('balances');
    expect($balances)->toBe([]); // nothing on hand at the destination yet; the incoming column shows on rows that exist

    $this->actingAs($this->s->keeper)->postJson("/stock/transfers/{$transfer['id']}/receive")->assertOk()->assertJsonPath('transfer.status', 'received');
    $this->actingAs($this->s->keeper)->postJson("/stock/transfers/{$transfer['id']}/receive")->assertStatus(409);

    expect(stockBalance($this->s->la2, 'material', $this->s->material->id)['on_hand'])->toBe('3.125')
        ->and(stockBalance($this->s->la, 'material', $this->s->material->id)['on_hand'])->toBe('7.13')
        ->and(StockMovement::query()->where('transfer_id', $transfer['id'])->sum('on_hand_delta'))->toBe(0)
        ->and(StateTransition::query()->where('machine', StateMachine::M25->value)->pluck('transition')->all())->toBe(['T00', 'T01', 'T02']);
    stockJournalMatches();
});

it('shows quantity in transit as incoming at the destination', function () {
    stockReceive($this->s->la2, 'material', $this->s->material->id, '1');
    $transfer = $this->actingAs($this->s->keeper)->postJson('/stock/transfers', transferPayload($this->s, '2'))->assertCreated()->json('transfer');
    $this->actingAs($this->s->keeper)->postJson("/stock/transfers/{$transfer['id']}/dispatch")->assertOk();

    $row = collect($this->actingAs($this->s->keeper)->getJson("/stock?location_id={$this->s->la2->id}")->json('balances'))->firstWhere('item.type', 'material');
    expect($row['on_hand'])->toBe('1')->and($row['incoming'])->toBe('2')->and($row['available'])->toBe('1');
});

it('moves instantly in one transaction (M-25-T04)', function () {
    $this->actingAs($this->s->keeper)->postJson('/stock/transfers', transferPayload($this->s, '10.255', true))
        ->assertCreated()->assertJsonPath('transfer.status', 'received');

    expect(stockBalance($this->s->la, 'material', $this->s->material->id)['on_hand'])->toBe('0')
        ->and(stockBalance($this->s->la2, 'material', $this->s->material->id)['on_hand'])->toBe('10.255');
    stockJournalMatches();
});

it('refuses to send more than is available and writes nothing', function () {
    $transfer = $this->actingAs($this->s->keeper)->postJson('/stock/transfers', transferPayload($this->s, '10.256'))->assertCreated()->json('transfer');
    $this->actingAs($this->s->keeper)->postJson("/stock/transfers/{$transfer['id']}/dispatch")->assertUnprocessable();

    expect(StockTransfer::query()->findOrFail($transfer['id'])->status->value)->toBe('draft')
        ->and(StockMovement::query()->where('transfer_id', $transfer['id'])->count())->toBe(0);
});

it('withdraws a draft without any movement (M-25-T03)', function () {
    $transfer = $this->actingAs($this->s->keeper)->postJson('/stock/transfers', transferPayload($this->s))->assertCreated()->json('transfer');
    $this->actingAs($this->s->keeper)->postJson("/stock/transfers/{$transfer['id']}/cancel")->assertOk()->assertJsonPath('transfer.status', 'cancelled');
    $this->actingAs($this->s->keeper)->postJson("/stock/transfers/{$transfer['id']}/dispatch")->assertStatus(409);

    expect(StockMovement::query()->whereNotNull('transfer_id')->count())->toBe(0);
});

it('needs P-50 over both the source and the destination', function () {
    $payload = ['from_location_id' => $this->s->la->id, 'to_location_id' => $this->s->lc->id,
        'lines' => [['item_type' => 'material', 'item_id' => $this->s->material->id, 'quantity' => '1']]];
    $this->actingAs($this->s->keeper)->postJson('/stock/transfers', $payload)->assertForbidden();
    $this->actingAs($this->s->f->sales)->postJson('/stock/transfers', transferPayload($this->s))->assertForbidden();
    $this->actingAs($this->s->f->org->owner)->postJson('/stock/transfers', $payload)->assertCreated();
});

it('refuses a stale version', function () {
    $transfer = $this->actingAs($this->s->keeper)->postJson('/stock/transfers', transferPayload($this->s))->assertCreated()->json('transfer');
    $this->actingAs($this->s->keeper)->postJson("/stock/transfers/{$transfer['id']}/dispatch", ['lock_version' => $transfer['lock_version'] + 5])->assertStatus(409);
});
