<?php

use App\Actions\StateMachines\M34Cancellation\T01RequestCancellation;
use App\Actions\StateMachines\M34Cancellation\T02AcceptCancellation;
use App\Actions\StateMachines\M34Cancellation\T03RecordAndAcceptCancellation;
use App\Cancellation\CancellationImpact;
use App\Cancellation\CancellationInput;
use App\Models\Cancellation\OrderCancellation;
use App\Models\Order;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseOrderLine;
use App\Models\Procurement\Supplier;
use App\Models\Settlement\SettlementFollowUp;
use App\Models\Settlement\SettlementItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Settlement/SettlementTestHelpers.php';

/**
 * Request fields of an external request recorded by management (SRS-COM-14: source, date, reason).
 *
 * @return array<string, mixed>
 */
function cnRequest(array $extra = []): array
{
    return ['source' => 'external', 'channel' => 'phone', 'requested_on' => now()->toDateString(), 'reason' => 'تأخر البناء وسنؤجل المطبخ سنة تقريبًا.', ...$extra];
}

/**
 * The impact review as the screen gets it (unmasked), for the pending request when there is one.
 *
 * @param  array<string, mixed>  $scope
 * @return array<string, mixed>
 */
function cnPreview(Order $order, array $scope): array
{
    $order->refresh();

    return CancellationImpact::compute($order, CancellationInput::fromInput($scope), OrderCancellation::query()->where('active_key', $order->id)->first());
}

/**
 * Records and accepts at once (M-34-T03) after reviewing the impact.
 *
 * @param  array<string, mixed>  $scope  scope, lines?, retained?
 */
function cnCancel(User $actor, Order $order, array $scope, ?string $key = null, ?string $hash = null): OrderCancellation
{
    $hash ??= cnPreview($order, $scope)['hash'];
    $order->refresh();

    return app(T03RecordAndAcceptCancellation::class)->handle($actor, $order, [...cnRequest(), ...$scope], $hash, $order->lock_version, $key ?? (string) Str::uuid());
}

/**
 * @param  array<string, mixed>  $scope
 */
function cnRequestOnly(User $actor, Order $order, array $scope = ['scope' => 'full']): OrderCancellation
{
    $order->refresh();

    return app(T01RequestCancellation::class)->handle($actor, $order, [...cnRequest(), ...$scope], $order->lock_version, (string) Str::uuid());
}

/**
 * @param  array<string, mixed>  $scope
 */
function cnAccept(User $actor, OrderCancellation $cancellation, array $scope, ?string $hash = null): OrderCancellation
{
    $order = Order::query()->findOrFail($cancellation->order_id);
    $hash ??= cnPreview($order, $scope)['hash'];

    return app(T02AcceptCancellation::class)->handle($actor, $cancellation->refresh(), $scope, $hash, $order->refresh()->lock_version, (string) Str::uuid());
}

function cnFollowUp(Order $order): ?SettlementFollowUp
{
    return SettlementFollowUp::query()->where('order_id', $order->id)->first();
}

/**
 * @return array<string, string> kind → status
 */
function cnItems(Order $order): array
{
    return SettlementItem::query()->where('order_id', $order->id)->orderBy('id')->get()
        ->mapWithKeys(fn (SettlementItem $i) => [$i->kind.($i->ref_id === null ? '' : ':'.$i->ref_id) => $i->status])->all();
}

/**
 * An ordered supplier purchase with one line serving the order (procurement data as P7b leaves it).
 */
function cnPurchaseFor(Order $order, string $status = 'ordered'): PurchaseOrder
{
    $supplier = Supplier::query()->create(['name' => ['ar' => 'مورد الألواح', 'en' => 'Boards supplier']]);
    $po = PurchaseOrder::query()->create([
        'number' => 'PO-'.now()->year.'-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT), 'supplier_id' => $supplier->id,
        'branch_id' => $order->branch_id, 'status' => $status, 'vat_rate_bp' => 1500, 'prices_include_vat' => true,
        'net_minor' => 100000, 'vat_minor' => 15000, 'gross_minor' => 115000,
    ]);
    PurchaseOrderLine::query()->create([
        'purchase_order_id' => $po->id, 'line_no' => 1, 'item_type' => 'material', 'item_id' => 1, 'item_name' => ['ar' => 'خشب MDF', 'en' => 'MDF board'],
        'unit' => 'piece', 'quantity_milli' => 10_000, 'unit_cost_minor' => 11500, 'net_minor' => 100000, 'vat_minor' => 15000, 'gross_minor' => 115000,
        'order_id' => $order->id,
    ]);

    return $po;
}

/**
 * Every row a refused cancellation must leave untouched.
 *
 * @return array<string, mixed>
 */
function cnSnapshot(): array
{
    return [
        ...setSnapshot(),
        'orders' => DB::table('orders')->get()->map(fn ($o) => [$o->id, $o->status, $o->lock_version, $o->total_minor])->all(),
        'cancellations' => DB::table('order_cancellations')->get()->map(fn ($c) => [$c->id, $c->status, $c->lock_version])->all(),
        'cancelled_lines' => DB::table('order_cancellation_lines')->count(),
        'agreements' => DB::table('agreements')->get()->map(fn ($a) => [$a->id, $a->status])->all(),
        'tasks' => DB::table('tasks')->get()->map(fn ($t) => [$t->id, $t->status])->all(),
        'reservations' => DB::table('stock_reservations')->get()->map(fn ($r) => [$r->id, $r->status, $r->released_milli, $r->issued_milli])->all(),
        'items' => DB::table('settlement_items')->get()->map(fn ($i) => [$i->id, $i->status])->all(),
        'transitions' => DB::table('state_transitions')->count(),
    ];
}

function cnRefused(Closure $attempt, string $exception, ?string $condition = null): void
{
    $before = cnSnapshot();
    try {
        $attempt();
        test()->fail('The cancellation was not refused.');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($exception);
        if ($condition !== null) {
            expect($e->condition ?? null)->toBe($condition);
        }
    }
    expect(cnSnapshot())->toBe($before);
}
