<?php

/**
 * Counts (FUL-04): nothing changes before approval; approval by a P-49 holder posts each difference against the
 * on-hand at approval time as an adjustment (SA numbers) and never below what is reserved.
 */

use App\Models\Inventory\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/InventoryTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->s = stockFixture();
    stockReceive($this->s->la, 'material', $this->s->material->id, '10');
    stockReceive($this->s->la, 'ready_variant', $this->s->f->variant->id, '3');
});

it('counts, submits and approves: differences become numbered adjustments', function () {
    $year = now()->year;
    $count = $this->actingAs($this->s->keeper)->postJson('/stock/counts', [
        'location_id' => $this->s->la->id, 'reason' => 'جرد نهاية الشهر',
        'lines' => [
            ['item_type' => 'material', 'item_id' => $this->s->material->id, 'counted' => '9.5'],
            ['item_type' => 'ready_variant', 'item_id' => $this->s->f->variant->id, 'counted' => '4'],
        ],
    ])->assertCreated()->json('count');
    expect($count['number'])->toBe("SC-{$year}-000001")
        ->and(stockBalance($this->s->la, 'material', $this->s->material->id)['on_hand'])->toBe('10');

    $this->actingAs($this->s->keeper)->postJson("/stock/counts/{$count['id']}/approve")->assertStatus(409); // still a draft
    $this->actingAs($this->s->keeper)->postJson("/stock/counts/{$count['id']}/submit")->assertOk()->assertJsonPath('count.status', 'submitted');
    $this->actingAs($this->s->keeper)->postJson("/stock/counts/{$count['id']}/approve")->assertOk()->assertJsonPath('count.status', 'approved');

    expect(stockBalance($this->s->la, 'material', $this->s->material->id)['on_hand'])->toBe('9.5')
        ->and(stockBalance($this->s->la, 'ready_variant', $this->s->f->variant->id)['on_hand'])->toBe('4')
        ->and(StockMovement::query()->where('count_id', $count['id'])->orderBy('document_number')->pluck('document_number')->all())
        ->toBe(["SA-{$year}-000001", "SA-{$year}-000002"]);
    stockJournalMatches();
});

it('needs P-49 on the location to approve; a viewer without history cannot count', function () {
    $count = $this->actingAs($this->s->keeper)->postJson('/stock/counts', [
        'location_id' => $this->s->la->id, 'reason' => 'جرد',
        'lines' => [['item_type' => 'material', 'item_id' => $this->s->material->id, 'counted' => '8']],
    ])->assertCreated()->json('count');
    $this->actingAs($this->s->keeper)->postJson("/stock/counts/{$count['id']}/submit")->assertOk();

    $manager = $this->s->f->org->member('manager', [$this->s->f->org->a]);
    $this->actingAs($manager)->postJson("/stock/counts/{$count['id']}/approve")->assertForbidden();
    $this->actingAs($this->s->f->sales)->postJson('/stock/counts', [
        'location_id' => $this->s->la->id, 'reason' => 'جرد',
        'lines' => [['item_type' => 'material', 'item_id' => $this->s->material->id, 'counted' => '8']],
    ])->assertForbidden();

    $this->actingAs($this->s->keeper)->postJson("/stock/counts/{$count['id']}/reject", ['note' => ''])->assertUnprocessable();
    $this->actingAs($this->s->keeper)->postJson("/stock/counts/{$count['id']}/reject", ['note' => 'أعد العد'])->assertOk()->assertJsonPath('count.status', 'rejected');
    expect(stockBalance($this->s->la, 'material', $this->s->material->id)['on_hand'])->toBe('10');
});

it('refuses an approval that would go below what is reserved', function () {
    $order = readyOrder($this->s, 2);
    $this->actingAs($this->s->f->org->owner)->postJson("/orders/{$order->id}/confirm", ['location_id' => $this->s->la->id])->assertOk();
    $count = $this->actingAs($this->s->keeper)->postJson('/stock/counts', [
        'location_id' => $this->s->la->id, 'reason' => 'جرد',
        'lines' => [['item_type' => 'ready_variant', 'item_id' => $this->s->f->variant->id, 'counted' => '1']],
    ])->assertCreated()->json('count');
    $this->actingAs($this->s->keeper)->postJson("/stock/counts/{$count['id']}/submit")->assertOk();

    $this->actingAs($this->s->keeper)->postJson("/stock/counts/{$count['id']}/approve")->assertUnprocessable();
    expect(stockBalance($this->s->la, 'ready_variant', $this->s->f->variant->id))->toBe(['on_hand' => '3', 'reserved' => '2', 'available' => '1']);
});
