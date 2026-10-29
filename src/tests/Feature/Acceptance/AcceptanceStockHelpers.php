<?php

use App\Inventory\Quantity;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockLocation;
use App\Models\Inventory\StockMovement;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../Cancellation/CancellationTestHelpers.php';
require_once __DIR__.'/../Delivery/DeliveryTestHelpers.php';

/**
 * Acceptance fixture for the stock scenarios (4, 6, 7, 8). Setup only: the stock fixture (branch A store A1, ready
 * variant at 45.00), `stock` units received through the procurement seam, then — over HTTP as the owner — a ready
 * order of `quantity` created and confirmed at A1 (reservation SR-…). Actors: owner, inventory officer of A (P-52,
 * P-53), manager of A (P-18 in scope, P-102, P-42, P-37) and finance officer of A (P-39, P-43).
 *
 * @return object{s: object, order: Order, line: int, owner: User, keeper: User, manager: User, finance: User, location: StockLocation, variant: int}
 */
function accSReadyFixture(int $stock = 10, int $quantity = 5): object
{
    $s = stockFixture();
    stockReceive($s->la, 'ready_variant', $s->f->variant->id, (string) $stock);
    $order = readyOrder($s, $quantity);
    test()->actingAs($s->f->org->owner)->postJson("/orders/{$order->id}/confirm", ['location_id' => $s->la->id, 'lock_version' => $order->refresh()->lock_version])
        ->assertOk()->assertJsonPath('order.status', 'confirmed');
    $org = $s->f->org;

    return (object) [
        's' => $s, 'org' => $org, 'order' => $order->refresh(), 'line' => deliveryLineId($order),
        'owner' => $org->owner, 'keeper' => $org->member('inventory', [$org->a]), 'manager' => $org->member('manager', [$org->a]),
        'finance' => $org->member('finance', [$org->a]), 'location' => $s->la, 'variant' => $s->f->variant->id,
    ];
}

function accSKey(): string
{
    return 'acc-'.Str::uuid()->toString();
}

function accSProof(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\n%receipt ".Str::random(8)."\n%%EOF");
}

/**
 * Drafts a delivery note over HTTP (P-52) and returns its JSON `note`.
 *
 * @return array<string, mixed>
 */
function accSDraft(User $actor, Order $order, int $line, string $quantity): array
{
    return test()->actingAs($actor)->postJson("/orders/{$order->id}/deliveries", [
        'idempotency_key' => accSKey(), 'lines' => [['order_line_id' => $line, 'quantity' => $quantity]],
    ])->assertCreated()->json('note');
}

/**
 * Records (confirms) a drafted note over HTTP with receiver and proof.
 *
 * @param  array<string, mixed>  $draft
 */
function accSConfirmNote(User $actor, array $draft, ?string $key = null): TestResponse
{
    return test()->actingAs($actor)->post("/deliveries/{$draft['id']}/confirm", [
        'idempotency_key' => $key ?? accSKey(), 'lock_version' => $draft['lock_version'], 'delivered_on' => now()->toDateString(),
        'receiver_name' => 'خالد العتيبي', 'proofs' => [accSProof()],
    ], ['Accept' => 'application/json']);
}

/**
 * Draft + record in one go; asserts success and returns the recorded note.
 *
 * @return array<string, mixed>
 */
function accSDeliver(User $actor, Order $order, int $line, string $quantity): array
{
    return accSConfirmNote($actor, accSDraft($actor, $order, $line, $quantity))->assertOk()->assertJsonPath('note.status', 'confirmed')->json('note');
}

/**
 * The cancellation review over HTTP (P-18): `{impact, hash, lock_version}`.
 *
 * @param  array<string, mixed>  $scope
 * @return array<string, mixed>
 */
function accSPreview(User $actor, Order $order, array $scope = ['scope' => 'full']): array
{
    return test()->actingAs($actor)->postJson("/orders/{$order->id}/cancellation/preview", $scope)->assertOk()->json();
}

/**
 * Records and accepts a cancellation over HTTP (M-34-T03) with the reviewed fingerprint and the version read with it.
 *
 * @param  array<string, mixed>  $scope
 */
function accSCancel(User $actor, Order $order, array $scope, string $hash, int $lockVersion, ?string $key = null): TestResponse
{
    return test()->actingAs($actor)->postJson("/orders/{$order->id}/cancellation", [
        ...cnRequest(), ...$scope, 'hash' => $hash, 'lock_version' => $lockVersion, 'idempotency_key' => $key ?? accSKey(),
    ]);
}

/**
 * Balance of an item at a location, in thousandths.
 *
 * @return array{on_hand: int, reserved: int, available: int}
 */
function accSBalance(StockLocation $location, string $type, int $id): array
{
    $item = StockItem::query()->where('item_type', $type)->where('item_id', $id)->first();
    $b = $item === null ? null : StockBalance::query()->where('location_id', $location->id)->where('stock_item_id', $item->id)->first();

    return ['on_hand' => (int) ($b?->on_hand_milli ?? 0), 'reserved' => (int) ($b?->reserved_milli ?? 0), 'available' => $b === null ? 0 : (int) $b->availableMilli()];
}

/**
 * The whole stock journal in order: [kind, quantity_milli, on_hand_delta, reserved_delta].
 *
 * @return list<array{0: string, 1: int, 2: int, 3: int}>
 */
function accSJournal(): array
{
    return StockMovement::query()->orderBy('id')->get()
        ->map(fn (StockMovement $m) => [$m->kind->value, (int) $m->quantity_milli, (int) $m->on_hand_delta, (int) $m->reserved_delta])->values()->all();
}

/**
 * @return array{quantity: int, issued: int, released: int, status: string}
 */
function accSReservation(Order $order): array
{
    $r = DB::table('stock_reservations')->where('order_id', $order->id)->sole();

    return ['quantity' => (int) $r->quantity_milli, 'issued' => (int) $r->issued_milli, 'released' => (int) $r->released_milli, 'status' => (string) $r->status];
}

function accSAudits(string $action): int
{
    return DB::table('audit_events')->where('action', $action)->count();
}

/**
 * Return eligibility per the ReturnNew screen (P-53/P-42): delivered / held / eligible of the first line, in text.
 *
 * @return array{delivered: string, held: string, eligible: string}
 */
function accSEligibility(User $actor, Order $order): array
{
    $line = test()->actingAs($actor)->getJson("/orders/{$order->id}/returns/create")->assertOk()->json('lines.0');

    return ['delivered' => $line['delivered'], 'held' => $line['held'], 'eligible' => $line['eligible']];
}

/**
 * @return array<string, mixed> the JSON `return`
 */
function accSReturnRequest(User $actor, Order $order, int $line, string $quantity): array
{
    return test()->actingAs($actor)->postJson("/orders/{$order->id}/returns", [
        'idempotency_key' => accSKey(), 'reason' => 'المقاس غير مناسب', 'lines' => [['order_line_id' => $line, 'quantity' => $quantity]],
    ])->assertCreated()->json('return');
}

function accSReturnVersion(User $actor, int $returnId): int
{
    return (int) test()->actingAs($actor)->getJson("/returns/{$returnId}")->assertOk()->json('return.lock_version');
}

/**
 * @param  array<string, mixed>  $extra  settlement?, lines?
 */
function accSReturnAccept(User $actor, int $returnId, array $extra = []): TestResponse
{
    return test()->actingAs($actor)->postJson("/returns/{$returnId}/accept", [
        'idempotency_key' => accSKey(), 'reason' => 'ضمن سياسة الإرجاع', 'lock_version' => accSReturnVersion($actor, $returnId), ...$extra,
    ]);
}

/**
 * @param  array{0: string, 1: string, 2: string}|null  $inspection  good, damaged, rejected recorded in the same step
 */
function accSReturnReceive(User $actor, int $returnId, int $line, StockLocation $location, string $quantity, ?array $inspection = null): TestResponse
{
    $row = ['order_line_id' => $line, 'quantity' => $quantity];
    if ($inspection !== null) {
        $row += ['good' => $inspection[0], 'damaged' => $inspection[1], 'rejected' => $inspection[2]];
    }

    return test()->actingAs($actor)->postJson("/returns/{$returnId}/receive", [
        'idempotency_key' => accSKey(), 'lock_version' => accSReturnVersion($actor, $returnId), 'location_id' => $location->id,
        'inspect' => $inspection !== null, 'lines' => [$row],
    ]);
}

function accSQuantity(string $text): int
{
    return Quantity::parse($text);
}
