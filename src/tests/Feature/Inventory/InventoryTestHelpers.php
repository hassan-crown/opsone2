<?php

use App\Inventory\Contracts\StockReceiver;
use App\Inventory\Quantity;
use App\Models\Catalog\CatalogMaterial;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockLocation;
use App\Models\Inventory\StockMovement;
use App\Models\Order;
use App\Models\User;
use App\Support\LockingTransaction;
use Illuminate\Support\Str;

require_once __DIR__.'/../Orders/OrderTestHelpers.php';

/**
 * Inventory fixture on top of the orders fixture: the default facility-wide location MAIN (from the migration), a
 * location in branch A and one in branch C, a quantity-tracked material counted in metres, and an inventory
 * officer of branch A.
 *
 * @return object{f: object, main: StockLocation, la: StockLocation, la2: StockLocation, lc: StockLocation, material: CatalogMaterial, keeper: User}
 */
function stockFixture(): object
{
    $f = ordersFixture();
    $main = StockLocation::query()->where('code', 'MAIN')->firstOrFail();
    $la = StockLocation::query()->create(['code' => 'A1', 'name' => ['ar' => 'مستودع أ', 'en' => 'Store A'], 'branch_id' => $f->org->a->id]);
    $la2 = StockLocation::query()->create(['code' => 'A2', 'name' => ['ar' => 'معرض أ', 'en' => 'Showroom A'], 'branch_id' => $f->org->a->id]);
    $lc = StockLocation::query()->create(['code' => 'C1', 'name' => ['ar' => 'مستودع ج', 'en' => 'Store C'], 'branch_id' => $f->org->c->id]);
    $material = makeMaterial($f->org->owner, ['unit' => 'متر', 'track_quantity' => true, 'name' => ['ar' => 'قماش ستائر', 'en' => 'Curtain fabric']]);

    return (object) [
        'f' => $f, 'main' => $main, 'la' => $la, 'la2' => $la2, 'lc' => $lc, 'material' => $material,
        'keeper' => $f->org->member('inventory', [$f->org->a]),
    ];
}

/**
 * Receives stock through the procurement seam (as P7b will).
 */
function stockReceive(StockLocation $location, string $type, int $id, string $quantity, ?string $key = null): void
{
    LockingTransaction::run(fn () => app(StockReceiver::class)->receive(
        $location->id, $type, $id, Quantity::parse($quantity), 'purchase_receipt', 1, null, $key ?? (string) Str::uuid(),
    ));
}

/**
 * @return array{on_hand: string, reserved: string, available: string}
 */
function stockBalance(StockLocation $location, string $type, int $id): array
{
    $item = StockItem::query()->where('item_type', $type)->where('item_id', $id)->first();
    $b = $item === null ? null : StockBalance::query()->where('location_id', $location->id)->where('stock_item_id', $item->id)->first();

    return [
        'on_hand' => Quantity::format($b?->on_hand_milli ?? 0),
        'reserved' => Quantity::format($b?->reserved_milli ?? 0),
        'available' => Quantity::format($b === null ? 0 : $b->availableMilli()),
    ];
}

/**
 * The balance projection always equals the sum of the journal (append-only, derived balances).
 */
function stockJournalMatches(): void
{
    foreach (StockBalance::query()->get() as $b) {
        $onHand = (int) StockMovement::query()->where('location_id', $b->location_id)->where('stock_item_id', $b->stock_item_id)->sum('on_hand_delta');
        $reserved = (int) StockMovement::query()->where('location_id', $b->location_id)->where('stock_item_id', $b->stock_item_id)->sum('reserved_delta');
        expect($b->on_hand_milli)->toBe($onHand)
            ->and($b->reserved_milli)->toBe($reserved)
            ->and($b->on_hand_milli)->toBeGreaterThanOrEqual($b->reserved_milli)
            ->and($b->reserved_milli)->toBeGreaterThanOrEqual(0);
    }
}

function readyOrder(object $s, int $quantity = 2, ?User $actor = null): Order
{
    test()->actingAs($actor ?? $s->f->org->owner)->postJson('/orders', readyPayload($s->f->inA, $s->f->variant, $quantity))->assertCreated();

    return lastOrder();
}
