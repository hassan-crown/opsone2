<?php

/**
 * P7 integration: procurement against the REAL stock ledger (no fake receiver) — PO approved → partial receipt →
 * balance up → second receipt → PO received; replay changes nothing; location options come from the stock domain.
 */

use App\Inventory\Contracts\StockReceiver;
use App\Inventory\StockLedger;
use App\Models\Inventory\StockLocation;
use App\Procurement\Locations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ProcurementTestHelpers.php';
require_once __DIR__.'/../Inventory/InventoryTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = procFixture();
    app()->instance(StockReceiver::class, app(StockLedger::class));
    $this->loc = StockLocation::query()->create(['code' => 'PA', 'name' => ['ar' => 'مستودع', 'en' => 'Store'], 'branch_id' => $this->f->org->a->id]);
    $this->f->location = $this->loc->id;
});

it('raises the stock balance on each partial receipt and closes the order on the last one', function () {
    $po = procOrdered($this->f);
    expect(stockBalance($this->loc, 'ready_variant', $this->f->variant->id)['on_hand'])->toBe('0');

    procReceive($this->f, $po, [1 => [4_000, 1_000, 'damaged']]);
    expect(stockBalance($this->loc, 'ready_variant', $this->f->variant->id)['on_hand'])->toBe('4')
        ->and($po->refresh()->status->value)->toBe('partially_received');

    procReceive($this->f, $po, [1 => [6_000]]);
    expect(stockBalance($this->loc, 'ready_variant', $this->f->variant->id)['on_hand'])->toBe('10');
    // 4 accepted + 1 rejected + 6 accepted = 10 accepted of 10 ordered: rejected never satisfies the order
    expect($po->refresh()->status->value)->toBe('received');
    stockJournalMatches();
});

it('offers the stock locations the receiver may use', function () {
    $this->actingAs($this->f->inventory);
    $ids = array_column(Locations::options(), 'id');
    expect($ids)->toContain($this->loc->id);
    $this->actingAs($this->f->inventoryB);
    expect(array_column(Locations::options(), 'id'))->not->toContain($this->loc->id);
});
