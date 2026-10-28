<?php

use App\Actions\StateMachines\M13PurchaseOrder\T00CreatePurchaseOrder;
use App\Actions\StateMachines\M13PurchaseOrder\T01ConfirmPurchaseOrder;
use App\Actions\StateMachines\M13PurchaseOrder\T02ReceivePurchase;
use App\Inventory\Contracts\StockReceiver;
use App\Models\Catalog\CatalogMaterial;
use App\Models\Catalog\CatalogReadyVariant;
use App\Models\MemberGrant;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseReceipt;
use App\Models\Procurement\Supplier;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\Support\Org;
use Tests\Support\RecordingStockReceiver;
use App\Models\Inventory\StockLocation;

require_once __DIR__.'/../Catalog/CatalogTestHelpers.php';

/**
 * Procurement fixture: a test organization, an inventory officer (P-45..P-47, P-82) and a manager scoped to branch A,
 * one supplier, a ready variant and a tracked and an untracked material, and a recording fake in place of the stock
 * domain's receiver.
 */
function procFixture(): object
{
    $f = new stdClass;
    $f->org = Org::make();
    $f->stock = new RecordingStockReceiver;
    app()->instance(StockReceiver::class, $f->stock);

    $f->owner = $f->org->owner;
    $f->inventory = $f->org->member('inventory', [$f->org->a]);
    $f->inventoryB = $f->org->member('inventory', [$f->org->b]);
    $f->manager = $f->org->member('manager', [$f->org->a]);
    $f->sales = $f->org->member('sales', [$f->org->a]);
    $f->supplier = procSupplier();

    $f->variant = makeVariant($f->owner, makeReady($f->owner), ['code' => 'HND-1']);
    $f->tracked = makeMaterial($f->owner, ['unit' => 'm', 'track_quantity' => true]);
    $f->plain = makeMaterial($f->owner, ['name' => ['ar' => 'خشب', 'en' => 'Wood'], 'track_quantity' => false]);
    // A real store of branch A: the receipt location is checked on the server (active, branch, P-47).
    $f->location = StockLocation::query()->create(['code' => 'PA1', 'name' => ['ar' => 'مستودع المشتريات', 'en' => 'Purchases store'], 'branch_id' => $f->org->a->id])->id;

    return $f;
}

function procKey(): string
{
    return 'k-'.Str::uuid();
}

function procSupplier(array $overrides = []): Supplier
{
    return Supplier::query()->create(['name' => ['ar' => 'مورد الألواح', 'en' => 'Boards supplier'], ...$overrides]);
}

/**
 * Removes a permission from a member (a per-member deny).
 */
function procDeny(User $user, string $permission): void
{
    MemberGrant::query()->create(['membership_id' => Org::membershipOf($user)->id, 'permission' => $permission, 'effect' => 'deny']);
}

/**
 * @param  list<array<string, mixed>>  $lines
 * @param  array<string, mixed>  $extra
 */
function procDraft(object $f, User $actor, array $lines, array $extra = [], ?string $key = null): PurchaseOrder
{
    return app(T00CreatePurchaseOrder::class)->handle($actor, [
        'supplier_id' => $f->supplier->id, 'branch_id' => $f->org->a->id, 'location_id' => $f->location,
        'expected_on' => now()->addWeek()->toDateString(), 'responsible_id' => $actor->id, 'notes' => null,
        'lines' => $lines, ...$extra,
    ], $key ?? procKey());
}

/**
 * A line: quantity in thousandths, cost in halalas.
 *
 * @return array<string, mixed>
 */
function procLine(string $type, int $id, int $milli, ?int $cost = null): array
{
    return ['id' => null, 'item_type' => $type, 'item_id' => $id, 'quantity_milli' => $milli, 'unit_cost_minor' => $cost, 'order_id' => null];
}

/**
 * A confirmed purchase order of 10 pieces of the ready variant.
 *
 * @param  list<array<string, mixed>>|null  $lines
 */
function procOrdered(object $f, ?array $lines = null, ?User $actor = null): PurchaseOrder
{
    $actor ??= $f->inventory;
    $po = procDraft($f, $actor, $lines ?? [procLine('ready_variant', $f->variant->id, 10_000, 1150)]);

    return app(T01ConfirmPurchaseOrder::class)->handle($actor, $po, $po->lock_version, procKey());
}

/**
 * A receipt: per order line number [accepted, rejected, reason?] in thousandths.
 *
 * @param  array<int, array{0: int, 1?: int, 2?: string}>  $byLine
 * @param  array<string, mixed>  $extra
 */
function procReceive(object $f, PurchaseOrder $po, array $byLine, array $extra = [], ?string $key = null, ?User $actor = null, array $proofs = []): PurchaseReceipt
{
    $version = $extra['lock_version'] ?? $po->refresh()->lock_version;
    unset($extra['lock_version']);
    $po->refresh()->load('lines');
    $lines = [];
    foreach ($byLine as $no => $q) {
        $lines[] = ['line_id' => $po->lines->firstWhere('line_no', $no)->id, 'accepted_milli' => $q[0], 'rejected_milli' => $q[1] ?? 0, 'reject_reason' => $q[2] ?? null];
    }

    return app(T02ReceivePurchase::class)->handle($actor ?? $f->inventory, $po, [
        'location_id' => $f->location, 'received_on' => now()->toDateString(), 'reference' => 'DN-77', 'note' => null, 'lines' => $lines, ...$extra,
    ], $proofs, $version, $key ?? procKey());
}

function procPdf(string $name = 'delivery.pdf', string $body = 'delivery'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%".$body."\n%%EOF");
}

function procVariantLine(object $f, int $milli = 10_000, ?int $cost = 1150): array
{
    return procLine('ready_variant', $f->variant->id, $milli, $cost);
}

/**
 * @return array<string, mixed>
 */
function procLineOf(PurchaseOrder $po, int $no): array
{
    $line = $po->refresh()->lines()->where('line_no', $no)->firstOrFail();

    return ['quantity' => $line->quantity_milli, 'received' => $line->received_milli, 'accepted' => $line->accepted_milli, 'rejected' => $line->rejected_milli, 'remaining' => $line->remainingMilli()];
}

/** The material used by tests that need a stock-tracked line. */
function procTracked(object $f): CatalogMaterial
{
    return $f->tracked;
}

function procVariant(object $f): CatalogReadyVariant
{
    return $f->variant;
}
