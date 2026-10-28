<?php

namespace App\Cancellation;

use App\Access\Exceptions\InvalidTransition;
use App\Agreements\OrderAgreement;
use App\Agreements\QuotePricing;
use App\Commissions\CommissionAccrual;
use App\Commissions\SaleOwnership;
use App\Delivery\DeliveryProgress;
use App\Enums\AppointmentStatus;
use App\Enums\CustomOrderStatus;
use App\Enums\DueBasis;
use App\Enums\PurchaseStatus;
use App\Enums\ReadyOrderStatus;
use App\Enums\RefundStatus;
use App\Enums\StageStatus;
use App\Enums\StateMachine;
use App\Enums\TaskStatus;
use App\Finance\Allocator;
use App\Finance\MoneyLedger;
use App\Finance\OrderSchedule;
use App\Inventory\MovementKind;
use App\Inventory\OrderStock;
use App\Inventory\Quantity;
use App\Inventory\StockItems;
use App\Models\Agreements\Agreement;
use App\Models\Appointment;
use App\Models\Cancellation\OrderCancellation;
use App\Models\Finance\OrderDue;
use App\Models\Finance\Refund;
use App\Models\FinanceSettings;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\StockReservation;
use App\Models\Order;
use App\Models\OrderStage;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseOrderLine;
use App\Models\Task;
use App\Models\User;
use App\Money\Money;
use App\Money\Rounding;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The impact review of a cancellation (FUL-12, WF-13, FIN-14, INT-08), computed from the sources and never stored
 * until it is applied: what stops (open tasks, upcoming appointments, the stages that will not run), what stays
 * (delivered lines, issued materials, completed stages, collected money) and what needs settlement (the refundable
 * amount from collected money against the value kept, stock to release, open supplier orders, the commission
 * difference). The same computation runs under the locks when confirming; the confirm is refused when its
 * fingerprint (`hash`, over the plan only — never over translated names) differs from the reviewed one.
 *
 * Amounts are always present here; CancellationPresenter removes them for a viewer without P-37 (P-82 for costs).
 */
final class CancellationImpact
{
    /**
     * @return array<string, mixed>
     */
    public static function compute(Order $order, CancellationInput $input, ?OrderCancellation $pending = null): array
    {
        self::ensureCancellable($order);
        $statusBefore = $pending === null ? $order->status : $pending->order_status_before;
        $rows = CancellableLines::for($order);
        $cut = self::cut($order, $input, $rows);
        $agreement = $order->isReady() ? null : OrderAgreement::current($order);

        $content = null;
        if ($agreement !== null) {
            $content = self::repriced($agreement, $input, $rows, $cut);
        }

        $release = self::release($order, $input, $cut);
        $statusAfter = self::statusAfter($order, $input, $statusBefore, $rows, $cut, $release);
        $money = self::money($order, $input, $agreement, $content, $rows, $cut);
        $dues = $content === null ? [] : self::dues($order, Money::of((int) $content['totals']['total_minor']));

        $impact = [
            'order' => [
                'id' => $order->id, 'number' => $order->number, 'kind' => $order->kind->value, 'status' => $order->status,
                'status_before' => $statusBefore, 'status_after' => $statusAfter,
            ],
            'scope' => $input->scope,
            'lines' => self::cutLines($rows, $cut, $content),
            'stops' => $input->isFull() && ! $order->isReady() ? self::stops($order) : ['tasks' => [], 'appointments' => [], 'stages' => [], 'change' => self::change($order)],
            'stays' => self::stays($order, $rows, $money['collected_minor']),
            'settlement' => [
                'money' => $money,
                'agreement' => $content === null ? null : [
                    'version_before' => $agreement->version, 'version_after' => $agreement->version + 1,
                    'total_before_minor' => $agreement->total_minor,
                    'total_minor' => (int) $content['totals']['total_minor'], 'net_minor' => (int) $content['totals']['net_minor'],
                    'vat_minor' => (int) $content['totals']['vat_minor'], 'discount_minor' => (int) $content['totals']['discount_minor'],
                    'dues' => $dues,
                ],
                'stock' => $release,
                'purchases' => self::purchases($order),
                'commission' => $content === null ? null : self::commission($order, $content, $money['collected_minor'], $dues),
            ],
            'content' => $content,
        ];
        $impact['hash'] = self::hash($impact);

        return $impact;
    }

    /**
     * States an order can be cancelled from: custom while work is open or a request is pending (M-04-T04/T05), ready
     * before full delivery (M-05-T09). After a complete delivery it is a return or a correction, never a cancellation.
     */
    public static function ensureCancellable(Order $order): void
    {
        $machine = $order->kind->machine();
        if ($order->isReady()) {
            if (! in_array($order->state(), [ReadyOrderStatus::Draft, ReadyOrderStatus::Confirmed, ReadyOrderStatus::PartiallyDelivered], true)) {
                throw new InvalidTransition($machine, 'T09', 'not_from_'.$order->status);
            }

            return;
        }
        if (! in_array($order->state(), [CustomOrderStatus::InProgress, CustomOrderStatus::Paused, CustomOrderStatus::ExecutionFinished, CustomOrderStatus::CancellationPending], true)) {
            throw new InvalidTransition($machine, 'T04', 'not_from_'.$order->status);
        }
        if (DeliveryProgress::complete($order)) {
            throw new InvalidTransition($machine, 'T04', 'fully_delivered');
        }
    }

    /**
     * The plan the confirm must match: identities, quantities and amounts, never display text.
     *
     * @param  array<string, mixed>  $impact
     */
    public static function hash(array $impact): string
    {
        /** @var array<string, mixed> $settlement */
        $settlement = $impact['settlement'];
        /** @var array<string, mixed> $stops */
        $stops = $impact['stops'];
        $plan = [
            'order' => $impact['order'],
            'scope' => $impact['scope'],
            'lines' => array_map(fn (array $l) => [$l['ref'], $l['quantity_milli'], $l['value_minor']], $impact['lines']),
            'tasks' => array_column($stops['tasks'], 'id'),
            'appointments' => array_column($stops['appointments'], 'id'),
            'stages' => array_column($stops['stages'], 'id'),
            'money' => $settlement['money'],
            'agreement' => $settlement['agreement'] === null ? null : array_diff_key($settlement['agreement'], ['dues' => true])
                + ['dues' => array_map(fn (array $d) => [$d['id'], $d['amount_after_minor']], $settlement['agreement']['dues'])],
            'stock' => array_map(fn (array $r) => [$r['reservation_id'], $r['release_milli']], $settlement['stock']),
            'purchases' => array_map(fn (array $p) => [$p['id'], $p['status'], array_column($p['lines'], 'remaining_milli')], $settlement['purchases']),
            'commission' => $settlement['commission'] === null ? null : $settlement['commission']['difference_minor'],
        ];

        return hash('sha256', (string) json_encode($plan));
    }

    /**
     * Quantity cut per ref, validated against what is still cancellable.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private static function cut(Order $order, CancellationInput $input, array $rows): array
    {
        if ($input->isFull()) {
            return array_filter(array_map(fn (array $r) => (int) $r['cancellable_milli'], $rows), fn (int $q) => $q > 0);
        }

        $cut = [];
        foreach ($input->lines as $i => $line) {
            $row = $rows[$line['ref']] ?? null;
            if ($row === null) {
                throw ValidationException::withMessages(["lines.{$i}.ref" => __('cancel.validation.line')]);
            }
            if ($line['quantity_milli'] > $row['cancellable_milli']) {
                throw ValidationException::withMessages(["lines.{$i}.quantity" => __('cancel.validation.above_cancellable', ['max' => Quantity::format((int) $row['cancellable_milli'])])]);
            }
            if ($row['whole_only'] && $line['quantity_milli'] !== $row['cancellable_milli']) {
                throw ValidationException::withMessages(["lines.{$i}.quantity" => __('cancel.validation.whole_line')]);
            }
            $cut[$line['ref']] = $line['quantity_milli'];
        }

        // A partial cancellation that leaves nothing to deliver and nothing delivered is a full cancellation.
        $left = 0;
        $delivered = 0;
        foreach ($rows as $ref => $row) {
            $left += (int) $row['cancellable_milli'] - ($cut[$ref] ?? 0);
            $delivered += (int) $row['delivered_milli'];
        }
        if ($left === 0 && $delivered === 0) {
            throw ValidationException::withMessages(['lines' => __('cancel.validation.use_full')]);
        }

        return $cut;
    }

    /**
     * The agreement after the cut, priced by the one P6 pricing path (QuotePricing): quantities reduced, the discount
     * kept (a percentage as is, a fixed amount in proportion to the remaining base, half-up). A full cancellation keeps
     * only what was delivered plus the retained value as its own non-commissionable line.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<string, int>  $cut
     * @return array<string, mixed>
     */
    private static function repriced(Agreement $agreement, CancellationInput $input, array $rows, array $cut): array
    {
        $old = $agreement->content;
        /** @var list<array<string, mixed>> $oldLines */
        $oldLines = $old['lines'] ?? [];
        $lines = [];
        foreach ($oldLines as $i => $line) {
            $quantity = Quantity::parse((string) $line['quantity']);
            $left = max(0, $quantity - ($cut['a'.$i] ?? 0));
            $lines[] = [
                'order_line_id' => $line['order_line_id'] ?? null,
                'description' => (string) $line['description'],
                'quantity' => Quantity::format($left),
                'unit' => $line['unit'] ?? null,
                'unit_price_minor' => isset($line['unit_price_minor']) ? (int) $line['unit_price_minor'] : null,
                'commissionable' => (bool) ($line['commissionable'] ?? true),
            ];
        }
        if ($input->isFull() && ($input->retainedMinor ?? 0) > 0) {
            $lines[] = [
                'order_line_id' => null, 'description' => __('cancel.retained_line'), 'quantity' => '1', 'unit' => null,
                'unit_price_minor' => $input->retainedMinor, 'commissionable' => false,
            ];
        }

        $settings = FinanceSettings::current();
        $type = (string) ($old['discount']['type'] ?? QuotePricing::DISCOUNT_NONE);
        $value = (int) ($old['discount']['value'] ?? 0);
        if ($type === QuotePricing::DISCOUNT_AMOUNT) {
            $base = (int) QuotePricing::price($lines, ['type' => QuotePricing::DISCOUNT_NONE], $settings)['totals']['base_minor'];
            $oldBase = (int) ($old['totals']['base_minor'] ?? 0);
            $value = $oldBase <= 0 ? 0 : min($base, Rounding::divide(bcmul((string) $value, (string) $base), (string) $oldBase));
        }
        $priced = QuotePricing::price($lines, ['type' => $type, 'value' => $value], $settings);

        return [...$priced, 'terms' => $old['terms'] ?? null, 'execution_days' => $old['execution_days'] ?? null];
    }

    /**
     * Open reservations to release: everything still held on a full cancellation (ready lines and custom materials);
     * the cut quantity of each ready line, newest reservation first, on a partial one. Issued stock never comes back here.
     *
     * @param  array<string, int>  $cut
     * @return list<array<string, mixed>>
     */
    private static function release(Order $order, CancellationInput $input, array $cut): array
    {
        $open = OrderStock::reservations($order)->filter(fn (StockReservation $r) => $r->status->isOpen() && $r->remainingMilli() > 0);
        $plan = [];
        if ($input->isFull()) {
            foreach ($open as $r) {
                $plan[] = [$r, $r->remainingMilli()];
            }
        } elseif ($order->isReady()) {
            foreach ($cut as $ref => $quantity) {
                $lineId = (int) substr($ref, 1);
                $left = $quantity;
                foreach ($open->where('order_line_id', $lineId)->sortByDesc('id') as $r) {
                    if ($left === 0) {
                        break;
                    }
                    $take = min($left, $r->remainingMilli());
                    $plan[] = [$r, $take];
                    $left -= $take;
                }
            }
        }

        return array_map(fn (array $p) => [
            'reservation_id' => $p[0]->id, 'number' => $p[0]->number, 'order_line_id' => $p[0]->order_line_id,
            'item' => StockItems::describe($p[0]->item)['name'], 'location' => $p[0]->location->code,
            'release_milli' => $p[1], 'whole' => $p[1] === $p[0]->remainingMilli(),
        ], $plan);
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<string, int>  $cut
     * @param  list<array<string, mixed>>  $release
     */
    private static function statusAfter(Order $order, CancellationInput $input, string $statusBefore, array $rows, array $cut, array $release): string
    {
        $delivered = array_sum(array_column($rows, 'delivered_milli')) > 0;
        if (! $order->isReady()) {
            return $input->isFull() ? CustomOrderStatus::Cancelled->value : $statusBefore;
        }
        if ($input->isFull()) {
            return ($delivered ? ReadyOrderStatus::Delivered : ReadyOrderStatus::Cancelled)->value;
        }
        $left = 0;
        foreach ($rows as $ref => $row) {
            $left += (int) $row['cancellable_milli'] - ($cut[$ref] ?? 0);
        }

        return $left === 0 && $delivered && $order->state() !== ReadyOrderStatus::Draft ? ReadyOrderStatus::Delivered->value : $order->status;
    }

    /**
     * Collected money against the value the order keeps. The refundable amount is a suggestion for the settlement
     * follow-up — no refund is created here (FIN-14, P-18: «لا رد أو إلغاء تحصيل آلي»).
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<string, int>  $cut
     * @param  array<string, mixed>|null  $content
     * @return array{collected_minor: int, value_before_minor: int|null, value_after_minor: int|null, retained_minor: int|null, pending_refunds_minor: int, refund_minor: int}
     */
    private static function money(Order $order, CancellationInput $input, ?Agreement $agreement, ?array $content, array $rows, array $cut): array
    {
        $collected = $order->isReady() ? 0 : MoneyLedger::collected($order->id)->minor;
        $pending = (int) Refund::query()->where('order_id', $order->id)
            ->whereIn('status', array_map(fn (RefundStatus $s) => $s->value, array_values(array_filter(RefundStatus::cases(), fn (RefundStatus $s) => $s->reserves()))))
            ->sum('amount_minor');

        if ($order->isReady()) {
            $before = $after = 0;
            foreach ($rows as $ref => $row) {
                $price = Money::of((int) ($row['unit_price_minor'] ?? 0));
                $effective = (int) $row['ordered_milli'] - (int) $row['cancelled_milli'];
                $before += $price->times(Quantity::format($effective))->minor;
                $after += $price->times(Quantity::format($effective - ($cut[$ref] ?? 0)))->minor;
            }
        } elseif ($agreement !== null && $content !== null) {
            $before = $agreement->total_minor;
            $after = (int) $content['totals']['total_minor'];
        } else {
            $before = null;
            $after = $input->isFull() ? ($input->retainedMinor ?? 0) : null;
        }

        $refund = $after === null ? 0 : max(0, $collected - $after - $pending);

        return [
            'collected_minor' => $collected, 'value_before_minor' => $before, 'value_after_minor' => $after,
            'retained_minor' => $input->isFull() ? ($input->retainedMinor ?? 0) : null, 'pending_refunds_minor' => $pending, 'refund_minor' => $refund,
        ];
    }

    /**
     * The schedule after the new value, as OrderSchedule::sync() will make it (same basis rows, same fitting).
     *
     * @return list<array{id: int|null, reference: string, name: string, amount_before_minor: int, amount_after_minor: int}>
     */
    private static function dues(Order $order, Money $value): array
    {
        $dues = Allocator::openDues($order->id);
        if ($dues->isEmpty()) {
            $template = OrderSchedule::TEMPLATES[OrderSchedule::DEFAULT_TEMPLATE];
            $rows = array_map(fn (array $r) => ['basis' => DueBasis::from($r['basis']), 'value' => $r['value']], $template);
            $amounts = OrderSchedule::fit(OrderSchedule::amounts($rows, $value), $value);

            return array_map(fn (array $r, int $amount) => [
                'id' => null, 'reference' => $r['reference'], 'name' => $r['name'][app()->getLocale()] ?? $r['name']['ar'],
                'amount_before_minor' => 0, 'amount_after_minor' => $amount,
            ], $template, $amounts);
        }
        $amounts = OrderSchedule::fit(OrderSchedule::amounts(array_values($dues->map(fn (OrderDue $d) => ['basis' => $d->basis, 'value' => $d->basis_value])->all()), $value), $value);

        return array_values($dues->values()->map(fn (OrderDue $d, int $i) => [
            'id' => $d->id, 'reference' => $d->reference, 'name' => (string) $d->name->get(app()->getLocale()),
            'amount_before_minor' => $d->amount_minor, 'amount_after_minor' => $amounts[$i],
        ])->all());
    }

    /**
     * Commission after the change (SHR-15): the pinned sale's rate on the eligible lines of the new agreement, times
     * what the new schedule will hold of the collected money — the same basis CommissionBasis reads after the apply.
     *
     * @param  array<string, mixed>  $content
     * @param  list<array{id: int|null, reference: string, name: string, amount_before_minor: int, amount_after_minor: int}>  $dues
     * @return array{user: string, accrued_minor: int, target_minor: int, difference_minor: int}|null
     */
    private static function commission(Order $order, array $content, int $collected, array $dues): ?array
    {
        $sale = SaleOwnership::active($order);
        if ($sale === null) {
            return null;
        }
        $agreed = (int) $content['totals']['total_minor'];
        $eligible = 0;
        foreach ($content['lines'] as $line) {
            if (! ($line['commissionable'] ?? false) || (($line['order_line_id'] ?? null) === null && ! $sale->manual_lines_eligible)) {
                continue;
            }
            $eligible += (int) ($line['net_minor'] ?? 0);
        }
        $held = max(0, min($agreed, $collected, array_sum(array_column($dues, 'amount_after_minor'))));
        $target = $agreed <= 0 || $eligible <= 0 || $sale->rate_bp === 0 || $held === 0
            ? 0
            : Rounding::divide(bcmul(bcmul((string) $eligible, (string) $sale->rate_bp), (string) $held), bcmul('10000', (string) $agreed));
        $accrued = CommissionAccrual::accrued($sale);

        return ['user' => (string) User::query()->whereKey($sale->user_id)->value('name'), 'accrued_minor' => $accrued, 'target_minor' => $target, 'difference_minor' => $target - $accrued];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<string, int>  $cut
     * @param  array<string, mixed>|null  $content
     * @return list<array{ref: string, order_line_id: int|null, agreement_line: int|null, description: string, unit: string|null, quantity_milli: int, value_minor: int|null}>
     */
    private static function cutLines(array $rows, array $cut, ?array $content): array
    {
        $out = [];
        foreach ($cut as $ref => $quantity) {
            $row = $rows[$ref];
            $value = null;
            if ($row['agreement_line'] !== null && $content !== null) {
                // Value taken off = the line's gross before and after the cut (discount and VAT included).
                $value = (int) ($row['unit_price_minor'] === null ? 0 : self::lineGross($row, $content));
            } elseif ($row['unit_price_minor'] !== null) {
                $value = Money::of((int) $row['unit_price_minor'])->times(Quantity::format($quantity))->minor;
            }
            $out[] = [
                'ref' => $ref, 'order_line_id' => $row['order_line_id'], 'agreement_line' => $row['agreement_line'],
                'description' => (string) $row['description'], 'unit' => $row['unit'], 'quantity_milli' => $quantity, 'value_minor' => $value,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $content
     */
    private static function lineGross(array $row, array $content): int
    {
        $index = (int) $row['agreement_line'];
        $price = Money::of((int) $row['unit_price_minor']);
        $before = $price->times(Quantity::format((int) $row['ordered_milli'] - (int) $row['cancelled_milli']))->minor;
        $after = (int) ($content['lines'][$index]['amount_minor'] ?? 0);

        return $before - $after;
    }

    /**
     * @return array{tasks: list<array<string, mixed>>, appointments: list<array<string, mixed>>, stages: list<array<string, mixed>>, change: array<string, mixed>|null}
     */
    private static function stops(Order $order): array
    {
        $locale = app()->getLocale();
        $tasks = Task::query()->where('order_id', $order->id)->whereIn('status', TaskStatus::open())->orderBy('id')->get();
        $names = User::query()->whereIn('id', $tasks->pluck('assignee_id')->filter()->all())->pluck('name', 'id');
        $appointments = Appointment::query()->where('order_id', $order->id)
            ->whereIn('status', [AppointmentStatus::Proposed->value, AppointmentStatus::AwaitingConfirmation->value, AppointmentStatus::Confirmed->value])
            ->orderBy('starts_at')->orderBy('id')->get();
        $stages = OrderStage::query()->where('order_id', $order->id)
            ->whereIn('status', [StageStatus::NotStarted->value, StageStatus::InProgress->value, StageStatus::Waiting->value])->orderBy('position')->get();

        return [
            'tasks' => array_values($tasks->map(fn (Task $t) => [
                'id' => $t->id, 'title' => (string) $t->title->get($locale), 'status' => $t->status->value, 'assignee' => $t->assignee_id === null ? null : $names->get($t->assignee_id),
            ])->all()),
            'appointments' => array_values($appointments->map(fn (Appointment $a) => [
                'id' => $a->id, 'kind' => $a->kind, 'status' => $a->status->value, 'starts_at' => $a->starts_at->toIso8601String(),
            ])->all()),
            'stages' => array_values($stages->map(fn (OrderStage $s) => ['id' => $s->id, 'name' => (string) $s->name->get($locale), 'status' => $s->status->value])->all()),
            'change' => self::change($order),
        ];
    }

    /**
     * An agreement change still open is built on the agreement the cancellation replaces: it cannot be applied after.
     *
     * @return array{number: string, status: string}|null
     */
    private static function change(Order $order): ?array
    {
        if ($order->isReady()) {
            return null;
        }
        $change = OrderAgreement::pendingChange($order);

        return $change === null ? null : ['number' => (string) $change->number, 'status' => $change->status->value];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @return array{delivered: list<array<string, mixed>>, issued: list<array<string, mixed>>, stages: list<array<string, mixed>>, collected_minor: int}
     */
    private static function stays(Order $order, array $rows, int $collected): array
    {
        $locale = app()->getLocale();
        $delivered = array_values(array_map(fn (array $r) => [
            'ref' => $r['ref'], 'description' => $r['description'], 'unit' => $r['unit'], 'delivered_milli' => $r['delivered_milli'],
        ], array_filter($rows, fn (array $r) => $r['delivered_milli'] > 0)));
        /** @var Collection<int, StockMovement> $issues */
        $issues = StockMovement::query()->where('order_id', $order->id)
            ->whereIn('kind', [MovementKind::Issue->value, MovementKind::IssueReserved->value])->with('item')->orderBy('id')->get();
        $issued = array_values($issues->groupBy(fn (StockMovement $m) => $m->stock_item_id.':'.$m->document_number)->map(fn (Collection $g) => [
            'number' => $g->first()?->document_number, 'item' => ($i = $g->first()?->item) === null ? '' : StockItems::describe($i)['name'],
            'unit' => $g->first()?->unit, 'issued_milli' => (int) $g->sum('quantity_milli'),
        ])->all());
        $stages = $order->isReady() ? [] : array_values(OrderStage::query()->where('order_id', $order->id)
            ->whereIn('status', [StageStatus::Completed->value, StageStatus::CompletedNeedsCorrection->value])->orderBy('position')->get()
            ->map(fn (OrderStage $s) => ['id' => $s->id, 'name' => (string) $s->name->get($locale)])->all());

        return ['delivered' => $delivered, 'issued' => $issued, 'stages' => $stages, 'collected_minor' => $collected];
    }

    /**
     * Supplier orders still waiting for goods for this order (FUL-07): referred to procurement, never cancelled here.
     *
     * @return list<array{id: int, number: string, status: string, supplier: string, lines: list<array<string, mixed>>}>
     */
    private static function purchases(Order $order): array
    {
        $locale = app()->getLocale();
        $lines = PurchaseOrderLine::query()->where('order_id', $order->id)->with('purchaseOrder.supplier')->orderBy('purchase_order_id')->orderBy('line_no')->get()
            ->filter(fn (PurchaseOrderLine $l) => in_array($l->purchaseOrder->status, [PurchaseStatus::Draft, PurchaseStatus::Ordered, PurchaseStatus::PartiallyReceived], true));

        return array_values($lines->groupBy('purchase_order_id')->map(function (Collection $group) use ($locale) {
            /** @var PurchaseOrder $po */
            $po = $group->first()->purchaseOrder;

            return [
                'id' => $po->id, 'number' => $po->number, 'status' => $po->status->value, 'supplier' => (string) $po->supplier->name->get($locale),
                'lines' => array_values($group->map(fn (PurchaseOrderLine $l) => [
                    'line_no' => $l->line_no, 'item' => (string) $l->item_name->get($locale), 'unit' => $l->unit,
                    'remaining_milli' => max(0, $l->quantity_milli - $l->accepted_milli), 'gross_minor' => $l->gross_minor,
                ])->all()),
            ];
        })->all());
    }

    public static function machine(Order $order): StateMachine
    {
        return $order->kind->machine();
    }
}
