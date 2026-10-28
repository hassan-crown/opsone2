<?php

/**
 * Manual material reserve / issue on a made-to-order order (FUL-03, P-51): no issue above what is available, a
 * descriptive material asks for no quantity, issues are idempotent, release frees only the unissued part.
 */

use App\Models\Inventory\StockMovement;
use App\Models\Inventory\StockReservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/InventoryTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->s = stockFixture();
    stockReceive($this->s->la, 'material', $this->s->material->id, '20');
    $this->actingAs($this->s->f->sales)->postJson('/orders', customPayload($this->s->f->inA))->assertCreated();
    $this->order = lastOrder();
    $this->supervisor = $this->s->f->org->member('supervisor', [$this->s->f->org->a]);
});

function materialBody(object $s, string $qty, array $extra = []): array
{
    return ['location_id' => $s->la->id, 'material_id' => $s->material->id, 'quantity' => $qty, ...$extra];
}

it('reserves, issues from the reservation and releases the rest', function () {
    $year = now()->year;
    $reservation = $this->actingAs($this->supervisor)->postJson("/orders/{$this->order->id}/materials/reserve", materialBody($this->s, '6.5'))
        ->assertCreated()->assertJsonPath('reservation.number', "SR-{$year}-000001")->json('reservation');
    expect(stockBalance($this->s->la, 'material', $this->s->material->id))->toBe(['on_hand' => '20', 'reserved' => '6.5', 'available' => '13.5']);

    $key = (string) Str::uuid();
    foreach ([1, 2] as $attempt) {
        $this->actingAs($this->supervisor)->postJson("/orders/{$this->order->id}/materials/issue", materialBody($this->s, '4', ['reservation_id' => $reservation['id'], 'idempotency_key' => $key]))->assertCreated();
    }
    expect(stockBalance($this->s->la, 'material', $this->s->material->id))->toBe(['on_hand' => '16', 'reserved' => '2.5', 'available' => '13.5']);

    $this->actingAs($this->supervisor)->postJson("/orders/{$this->order->id}/materials/{$reservation['id']}/release", ['reason' => 'تغيير التصميم'])
        ->assertOk()->assertJsonPath('reservation.status', 'settled_mixed');
    expect(stockBalance($this->s->la, 'material', $this->s->material->id))->toBe(['on_hand' => '16', 'reserved' => '0', 'available' => '16']);
    stockJournalMatches();
});

it('issues directly from what is available with an SI number, never more than available', function () {
    $year = now()->year;
    $this->actingAs($this->supervisor)->postJson("/orders/{$this->order->id}/materials/issue", materialBody($this->s, '20.001', ['idempotency_key' => 'a']))
        ->assertUnprocessable()->assertJsonValidationErrors('quantity');
    $this->actingAs($this->supervisor)->postJson("/orders/{$this->order->id}/materials/issue", materialBody($this->s, '3', ['idempotency_key' => 'b']))
        ->assertCreated()->assertJsonPath('movement.document_number', "SI-{$year}-000001");

    expect(StockMovement::query()->where('order_id', $this->order->id)->where('kind', 'issue')->count())->toBe(1);
});

it('asks no quantity for a descriptive material and refuses it', function () {
    $descriptive = makeMaterial($this->s->f->org->owner, ['track_quantity' => false]);
    $this->actingAs($this->supervisor)->postJson("/orders/{$this->order->id}/materials/reserve", ['location_id' => $this->s->la->id, 'material_id' => $descriptive->id, 'quantity' => '1'])
        ->assertUnprocessable()->assertJsonValidationErrors('material_id');

    $this->actingAs($this->supervisor)->get("/orders/{$this->order->id}/materials")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('orders/materials')->has('materials', 1)->where('materials.0.id', $this->s->material->id));
});

it('needs P-51 on both the order and the location; ready orders are refused', function () {
    $this->actingAs($this->s->f->sales)->postJson("/orders/{$this->order->id}/materials/reserve", materialBody($this->s, '1'))->assertForbidden();
    $this->actingAs($this->supervisor)->postJson("/orders/{$this->order->id}/materials/reserve", ['location_id' => $this->s->lc->id, 'material_id' => $this->s->material->id, 'quantity' => '1'])->assertForbidden();

    $ready = readyOrder($this->s, 1);
    $this->actingAs($this->s->f->org->owner)->postJson("/orders/{$ready->id}/materials/reserve", materialBody($this->s, '1'))->assertUnprocessable();
    expect(StockReservation::query()->count())->toBe(0);
});
