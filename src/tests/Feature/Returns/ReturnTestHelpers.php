<?php

use App\Actions\StateMachines\M15Return\T00RequestReturn;
use App\Actions\StateMachines\M15Return\T01ReceiveReturn;
use App\Actions\StateMachines\M15Return\T02InspectReturn;
use App\Actions\StateMachines\M15Return\T05AcceptReturn;
use App\Inventory\Quantity;
use App\Models\Inventory\StockLocation;
use App\Models\Order;
use App\Models\Returns\OrderReturn;
use App\Models\Returns\OrderReturnPart;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Delivery/DeliveryTestHelpers.php';

/**
 * Returns fixture on the delivery fixture: `stock` units of the ready variant at store A1, a ready order of `quantity`
 * with `delivered` of them handed over (DN-…), the owner, the inventory officer of branch A (P-53, P-49), a manager of
 * branch A (P-102, P-42, P-37) and a sales member of branch A (P-42, P-37 own scope).
 *
 * @return object{d: object, s: object, org: object, order: Order, line: int, owner: User, keeper: User, manager: User, location: StockLocation}
 */
function returnFixture(int $stock = 10, int $quantity = 5, int $delivered = 5): object
{
    $d = deliveryFixture($stock, $quantity);
    $line = deliveryLineId($d->order);
    deliveryConfirm($d->keeper, deliveryDraft($d->keeper, $d->order, [$line => (string) $delivered]));
    $org = $d->s->f->org;

    return (object) [
        'd' => $d, 's' => $d->s, 'org' => $org, 'order' => $d->order->refresh(), 'line' => $line,
        'owner' => $org->owner, 'keeper' => $d->keeper, 'manager' => $org->member('manager', [$org->a]),
        'location' => $d->s->la,
    ];
}

function rtKey(): string
{
    return 'rt-'.Str::uuid()->toString();
}

function rtRequest(User $actor, Order $order, int $line, string $quantity, ?string $key = null): OrderReturn
{
    return app(T00RequestReturn::class)->handle($actor, $order->refresh(), [['order_line_id' => $line, 'quantity_milli' => Quantity::parse($quantity)]], 'المقاس غير مناسب', $key ?? rtKey());
}

function rtAccept(User $actor, OrderReturn $return, ?string $quantity = null, ?int $settlementMinor = null, ?string $key = null): OrderReturn
{
    $return->refresh();
    $lines = $quantity === null ? null : [['order_line_id' => (int) $return->lines()->value('order_line_id'), 'quantity_milli' => Quantity::parse($quantity)]];

    return app(T05AcceptReturn::class)->handle($actor, $return, $lines, 'ضمن سياسة الإرجاع', $settlementMinor, $return->lock_version, $key ?? rtKey());
}

/**
 * @param  array{0: string, 1: string, 2: string}|null  $inspection  good, damaged, rejected recorded in the same step
 */
function rtReceive(User $actor, OrderReturn $return, StockLocation $location, string $quantity, ?array $inspection = null, ?string $key = null, ?int $lockVersion = null): OrderReturn
{
    $return->refresh();
    $line = ['order_line_id' => (int) $return->lines()->value('order_line_id'), 'quantity_milli' => Quantity::parse($quantity)];
    if ($inspection !== null) {
        $line += ['good_milli' => Quantity::parse($inspection[0]), 'damaged_milli' => Quantity::parse($inspection[1]), 'rejected_milli' => Quantity::parse($inspection[2])];
    }

    return app(T01ReceiveReturn::class)->handle($actor, $return, $location->id, [$line], $inspection !== null, null, $lockVersion ?? $return->lock_version, $key ?? rtKey());
}

function rtInspect(User $actor, OrderReturn $return, string $good, string $damaged = '0', string $rejected = '0', ?OrderReturnPart $part = null, ?string $key = null): OrderReturn
{
    $return->refresh();
    $part ??= OrderReturnPart::query()->where('order_return_id', $return->id)->whereNull('inspected_at')->orderBy('id')->firstOrFail();

    return app(T02InspectReturn::class)->handle($actor, $return, [[
        'part_id' => $part->id, 'good_milli' => Quantity::parse($good), 'damaged_milli' => Quantity::parse($damaged), 'rejected_milli' => Quantity::parse($rejected),
    ]], 'فُحصت', $return->lock_version, $key ?? rtKey());
}

/**
 * Every row a refused return write must leave untouched.
 *
 * @return array<string, mixed>
 */
function rtSnapshot(): array
{
    return [
        'returns' => DB::table('order_returns')->get()->map(fn ($r) => [$r->id, $r->number, $r->status, $r->lock_version, $r->settlement_minor, $r->refund_id])->all(),
        'lines' => DB::table('order_return_lines')->get()->map(fn ($l) => [$l->id, $l->requested_milli, $l->accepted_milli, $l->received_milli])->all(),
        'parts' => DB::table('order_return_parts')->get()->map(fn ($p) => [$p->id, $p->quantity_milli, $p->good_milli, $p->damaged_milli, $p->rejected_milli])->all(),
        'movements' => DB::table('stock_movements')->count(),
        'balances' => DB::table('stock_balances')->get()->map(fn ($b) => [$b->location_id, $b->stock_item_id, $b->on_hand_milli, $b->reserved_milli])->all(),
        'refunds' => DB::table('refunds')->count(),
        'commission' => DB::table('commission_entries')->count(),
        'counters' => DB::table('sequence_counters')->get()->map(fn ($c) => [$c->name, $c->last_value])->all(),
        'transitions' => DB::table('state_transitions')->count(),
        'audit' => DB::table('audit_events')->count(),
        'idempotency' => DB::table('idempotency_keys')->count(),
        'notifications' => DB::table('user_notifications')->count(),
        'outbox' => DB::table('outbox_messages')->count(),
    ];
}

function rtRefused(Closure $attempt, string $exception, ?string $condition = null): void
{
    $before = rtSnapshot();
    try {
        $attempt();
        test()->fail('The return write was not refused.');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($exception);
        if ($condition !== null) {
            expect($e->condition ?? null)->toBe($condition);
        }
    }
    expect(rtSnapshot())->toBe($before);
}
