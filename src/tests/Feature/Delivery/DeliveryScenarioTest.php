<?php

/**
 * «توريد وتسليم جزئيان» end to end across P7a/P7b/P7c: a purchase order of 10 is received in two parts through the real
 * stock receiver (4 accepted + 1 rejected, then 6), a ready order is served from it in two partial deliveries, and
 * every document number (PO, SR, DN) is traceable in the journal.
 */

use App\Actions\StateMachines\M05ReadyOrder\T01ConfirmReadyOrder;
use App\Inventory\Contracts\StockReceiver;
use App\Inventory\StockLedger;
use App\Models\Inventory\StockMovement;
use App\Models\Procurement\PurchaseOrder;
use App\Support\LockingTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/DeliveryTestHelpers.php';
require_once __DIR__.'/../Procurement/ProcurementTestHelpers.php';

uses(RefreshDatabase::class);

it('serves a ready order from a partially received purchase in two partial deliveries', function () {
    Storage::fake('attachments');
    $s = stockFixture();
    app()->instance(StockReceiver::class, app(StockLedger::class));
    $f = (object) ['org' => $s->f->org, 'supplier' => procSupplier(), 'variant' => $s->f->variant, 'location' => $s->la->id, 'inventory' => $s->keeper];
    $year = now()->year;

    // 10 ordered; 4 accepted + 1 rejected arrive → 6 still owed by the supplier, 4 in stock.
    $po = procOrdered($f, [procLine('ready_variant', $s->f->variant->id, 10_000, 1150)], $s->keeper);
    procReceive($f, $po, [1 => [4_000, 1_000, 'damaged']], actor: $s->keeper);
    expect($po->refresh()->number)->toBe("PO-{$year}-000001")->and($po->status->value)->toBe('partially_received')
        ->and(stockBalance($s->la, 'ready_variant', $s->f->variant->id)['on_hand'])->toBe('4');

    // The customer orders 4 and the order is confirmed on the received stock (reservation SR-…).
    $order = readyOrder($s, 4);
    LockingTransaction::run(fn () => app(T01ConfirmReadyOrder::class)->handle($s->f->org->owner, $order, $s->la->id));
    $line = deliveryLineId($order);

    $first = deliveryConfirm($s->keeper, deliveryDraft($s->keeper, $order->refresh(), [$line => '3']));
    expect($first->number)->toBe("DN-{$year}-000001")->and($order->refresh()->status)->toBe('partially_delivered')
        ->and(stockBalance($s->la, 'ready_variant', $s->f->variant->id))->toBe(['on_hand' => '1', 'reserved' => '1', 'available' => '0']);

    // The rest of the purchase arrives while 1 is still owed to the customer.
    procReceive($f, $po, [1 => [6_000]], actor: $s->keeper);
    expect($po->refresh()->status->value)->toBe('received')
        ->and(stockBalance($s->la, 'ready_variant', $s->f->variant->id)['on_hand'])->toBe('7');

    $second = deliveryConfirm($s->keeper, deliveryDraft($s->keeper, $order->refresh(), [$line => '1']));
    expect($second->number)->toBe("DN-{$year}-000002")->and($order->refresh()->status)->toBe('delivered')
        ->and(stockBalance($s->la, 'ready_variant', $s->f->variant->id))->toBe(['on_hand' => '6', 'reserved' => '0', 'available' => '6']);

    // Traceability: the reservation's document number carries reserve → issue rows, each issue names its delivery note.
    $issues = StockMovement::query()->where('kind', 'issue_reserved')->orderBy('id')->get();
    expect($issues)->toHaveCount(2)
        ->and($issues->pluck('document_number')->unique()->all())->toBe(["SR-{$year}-000001"])
        ->and($issues->pluck('source_id')->all())->toBe([$first->id, $second->id])
        ->and(PurchaseOrder::query()->count())->toBe(1);
    stockJournalMatches();
});
