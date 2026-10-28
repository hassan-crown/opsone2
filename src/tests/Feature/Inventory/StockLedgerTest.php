<?php

/**
 * The stock journal (FUL-01, FUL-03, FUL-04, Q-43 a): balances derived from append-only movements, quantities in
 * thousandths, idempotent receipts, never negative, adjustments with a reason and a document number.
 */

use App\Enums\StateMachine;
use App\Inventory\Quantity;
use App\Inventory\StockItems;
use App\Models\AuditEvent;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockMovement;
use App\Models\StateTransition;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/InventoryTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->s = stockFixture();
});

it('parses and formats quantities in thousandths without floats', function () {
    expect(Quantity::parse('2'))->toBe(2000)
        ->and(Quantity::parse('1.5'))->toBe(1500)
        ->and(Quantity::parse('0.125'))->toBe(125)
        ->and(Quantity::parse('2.000'))->toBe(2000)
        ->and(Quantity::format(1500))->toBe('1.5')
        ->and(Quantity::format(2000))->toBe('2')
        ->and(Quantity::format(-250))->toBe('-0.25')
        ->and(Quantity::isValid('1.2345'))->toBeFalse()
        ->and(Quantity::isValid('-1'))->toBeFalse()
        ->and(Quantity::isValid('0'))->toBeFalse();
});

it('receives once per idempotency key (FUL-03: a repeated notice adds nothing)', function () {
    stockReceive($this->s->la, 'material', $this->s->material->id, '12.5', 'po-1:line-1');
    stockReceive($this->s->la, 'material', $this->s->material->id, '12.5', 'po-1:line-1');
    stockReceive($this->s->la, 'material', $this->s->material->id, '0.25', 'po-1:line-2');

    expect(stockBalance($this->s->la, 'material', $this->s->material->id)['on_hand'])->toBe('12.75')
        ->and(StockMovement::query()->where('kind', 'receipt')->count())->toBe(2)
        ->and(StockItem::query()->where('item_type', 'material')->firstOrFail()->unit->value)->toBe('meter');
    stockJournalMatches();
});

it('refuses a descriptive material and a material whose unit is not a fixed unit (FUL-04, Q-43 a)', function () {
    $descriptive = makeMaterial($this->s->f->org->owner, ['track_quantity' => false]);
    $odd = makeMaterial($this->s->f->org->owner, ['track_quantity' => true, 'unit' => 'حزمة']);

    expect(fn () => stockReceive($this->s->la, 'material', $descriptive->id, '1'))->toThrow(ValidationException::class)
        ->and(fn () => stockReceive($this->s->la, 'material', $odd->id, '1'))->toThrow(ValidationException::class)
        ->and(StockItems::unitFor('material', $odd->id))->toBeNull()
        ->and(StockMovement::query()->count())->toBe(0);
});

it('shows available = on hand − reserved and never lets a balance go negative (FUL-01)', function () {
    stockReceive($this->s->la, 'ready_variant', $this->s->f->variant->id, '5');
    $order = readyOrder($this->s, 2);
    $this->actingAs($this->s->f->org->owner)->postJson("/orders/{$order->id}/confirm", ['location_id' => $this->s->la->id])->assertOk();

    expect(stockBalance($this->s->la, 'ready_variant', $this->s->f->variant->id))->toBe(['on_hand' => '5', 'reserved' => '2', 'available' => '3']);

    // An adjustment out cannot take reserved stock.
    $this->actingAs($this->s->f->org->owner)->postJson('/stock/adjustments', ['idempotency_key' => (string) Str::uuid(),
        'location_id' => $this->s->la->id, 'item_type' => 'ready_variant', 'item_id' => $this->s->f->variant->id,
        'direction' => 'out', 'quantity' => '4', 'reason' => 'تلف',
    ])->assertUnprocessable()->assertJsonValidationErrors('quantity');
    stockJournalMatches();
});

it('records an adjustment with a reason and a gapless SA number, audited', function () {
    stockReceive($this->s->la, 'material', $this->s->material->id, '10');
    $year = now()->year;
    $first = $this->actingAs($this->s->keeper)->postJson('/stock/adjustments', ['idempotency_key' => (string) Str::uuid(),
        'location_id' => $this->s->la->id, 'item_type' => 'material', 'item_id' => $this->s->material->id,
        'direction' => 'out', 'quantity' => '1.5', 'reason' => 'قص تالف',
    ])->assertCreated()->json('movement');
    $second = $this->actingAs($this->s->keeper)->postJson('/stock/adjustments', ['idempotency_key' => (string) Str::uuid(),
        'location_id' => $this->s->la->id, 'item_type' => 'material', 'item_id' => $this->s->material->id,
        'direction' => 'in', 'quantity' => '0.5', 'reason' => 'وجدنا لفة',
    ])->assertCreated()->json('movement');

    expect($first['document_number'])->toBe("SA-{$year}-000001")
        ->and($second['document_number'])->toBe("SA-{$year}-000002")
        ->and(stockBalance($this->s->la, 'material', $this->s->material->id)['on_hand'])->toBe('9')
        ->and(AuditEvent::query()->where('action', 'stock.adjusted')->count())->toBe(2);

    $this->actingAs($this->s->keeper)->postJson('/stock/adjustments', ['idempotency_key' => (string) Str::uuid(),
        'location_id' => $this->s->la->id, 'item_type' => 'material', 'item_id' => $this->s->material->id,
        'direction' => 'out', 'quantity' => '1', 'reason' => '',
    ])->assertUnprocessable();
    stockJournalMatches();
});

it('never updates or deletes a movement', function () {
    stockReceive($this->s->la, 'material', $this->s->material->id, '1');
    $movement = StockMovement::query()->firstOrFail();

    expect(fn () => $movement->update(['quantity_milli' => 5]))->toThrow(LogicException::class)
        ->and(fn () => $movement->delete())->toThrow(LogicException::class);
});

it('keeps the unit fixed once an item has movements (no conversion)', function () {
    stockReceive($this->s->la, 'material', $this->s->material->id, '1');

    $this->actingAs($this->s->f->org->owner)->putJson('/stock/items', [
        'item_type' => 'material', 'item_id' => $this->s->material->id, 'unit' => 'sheet', 'reorder_level' => '3',
    ])->assertUnprocessable()->assertJsonValidationErrors('unit');

    $this->actingAs($this->s->f->org->owner)->putJson('/stock/items', [
        'item_type' => 'material', 'item_id' => $this->s->material->id, 'reorder_level' => '3',
    ])->assertOk()->assertJsonPath('item.reorder_level', '3');
});

it('alerts P-49 holders once when available falls below the reorder level', function () {
    stockReceive($this->s->la, 'material', $this->s->material->id, '5');
    $this->actingAs($this->s->f->org->owner)->putJson('/stock/items', [
        'item_type' => 'material', 'item_id' => $this->s->material->id, 'reorder_level' => '4',
    ])->assertOk();

    foreach (['1.5', '0.5'] as $q) {
        $this->actingAs($this->s->keeper)->postJson('/stock/adjustments', ['idempotency_key' => (string) Str::uuid(),
            'location_id' => $this->s->la->id, 'item_type' => 'material', 'item_id' => $this->s->material->id,
            'direction' => 'out', 'quantity' => $q, 'reason' => 'استهلاك',
        ])->assertCreated();
    }

    $notified = UserNotification::query()->where('type', 'stock.low')->pluck('user_id')->all();
    expect($notified)->toContain($this->s->keeper->id)
        ->and($notified)->toContain($this->s->f->org->owner->id)
        ->and($notified)->not->toContain($this->s->f->sales->id)
        ->and(count($notified))->toBe(count(array_unique($notified)));
});

it('records M-24 transitions on reservations', function () {
    stockReceive($this->s->la, 'ready_variant', $this->s->f->variant->id, '3');
    $order = readyOrder($this->s, 1);
    $this->actingAs($this->s->f->org->owner)->postJson("/orders/{$order->id}/confirm", ['location_id' => $this->s->la->id])->assertOk();

    expect(StateTransition::query()->where('machine', StateMachine::M24->value)->where('transition', 'T00')->count())->toBe(1)
        ->and(StateTransition::query()->where('machine', StateMachine::M05->value)->where('transition', 'T01')->count())->toBe(1);
});
