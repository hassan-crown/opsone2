<?php

namespace App\Orders;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Agreements\OrderAgreement;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Delivery\DeliveryProgress;
use App\Enums\CustomOrderStatus;
use App\Enums\DeliveryStatus;
use App\Enums\OrderKind;
use App\Enums\PurchaseStatus;
use App\Enums\ReadyOrderStatus;
use App\Enums\RefundStatus;
use App\Finance\DebtWriteOffs;
use App\Installation\InstallationRemarks;
use App\Models\Delivery\DeliveryNote;
use App\Models\Delivery\DeliveryRemark;
use App\Models\Finance\MoneyEntry;
use App\Models\Finance\OrderDue;
use App\Models\Finance\Refund;
use App\Models\Order;
use App\Models\Procurement\PurchaseOrderLine;
use App\Models\Returns\OrderReturn;
use App\Models\Settlement\SettlementFollowUp;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Support\StaleRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Comprehensive closure of an order (M-04-T07 for a made-to-order order, M-05-T10 for a ready one): the order moves to
 * `closed` only when money, refunds, purchasing, delivery, returns and blocking remarks are all settled. The agreement
 * stays as it is and the reason is kept; closing never forgives a debt (a due still owed blocks it until it is paid
 * or written off by P-103, ADR-0035). Authorized by P-25
 * for a finished order and P-18 for a cancelled one («الصلاحية P-18 أو P-25 بحسب القرار»).
 */
final class OrderClosure
{
    public function __construct(private readonly TransitionRecorder $transitions) {}

    /**
     * Why the order cannot close now (empty when it can). Keys are stable; `details` carry numbers only; `items` name
     * each unresolved delivery remark (M-14-T04, ADR-0035).
     *
     * @return list<array{key: string, details: array<string, int>, items?: list<array{id: int, note: string|null, description: string}>}>
     */
    public static function blockers(Order $order): array
    {
        $blockers = [];
        /**
         * @param  array<string, int>  $details
         * @param  list<array{id: int, note: string|null, description: string}>|null  $items
         */
        $add = function (string $key, array $details = [], ?array $items = null) use (&$blockers) {
            $blockers[] = ['key' => $key, 'details' => $details] + ($items === null ? [] : ['items' => $items]);
        };

        // A write-off (P-103, ADR-0035) settles what it wrote off; closing alone never forgives a debt.
        $owed = (int) OrderDue::query()->where('order_id', $order->id)->whereNull('removed_at')->sum('amount_minor')
            - (int) MoneyEntry::query()->where('order_id', $order->id)->whereNotNull('order_due_id')->sum('amount_minor')
            - DebtWriteOffs::writtenOff($order->id);
        if ($owed > 0) {
            $add('money_owed', ['minor' => $owed]);
        }

        $refunds = Refund::query()->where('order_id', $order->id)->whereIn('status', array_map(fn (RefundStatus $s) => $s->value, [RefundStatus::Requested, RefundStatus::InProgress, RefundStatus::Unresolved]))->count();
        if ($refunds > 0) {
            $add('refund_pending', ['count' => $refunds]);
        }

        $followUp = SettlementFollowUp::query()->where('order_id', $order->id)->first();
        if ($followUp !== null && $followUp->isOpen()) {
            $add('settlement_open');
        }

        $purchases = PurchaseOrderLine::query()->where('order_id', $order->id)
            ->whereHas('purchaseOrder', fn ($q) => $q->whereIn('status', [PurchaseStatus::Draft->value, PurchaseStatus::Ordered->value, PurchaseStatus::PartiallyReceived->value]))->count();
        if ($purchases > 0) {
            $add('purchase_open', ['count' => $purchases]);
        }

        $drafts = DeliveryNote::query()->where('order_id', $order->id)->where('status', DeliveryStatus::Draft->value)->count();
        if ($drafts > 0) {
            $add('delivery_draft', ['count' => $drafts]);
        }
        if (in_array($order->state(), [CustomOrderStatus::ExecutionFinished, ReadyOrderStatus::Delivered], true)) {
            $left = array_sum(array_column(DeliveryProgress::lines($order), 'deliverable'));
            if ($left > 0) {
                $add('delivery_remaining', ['milli' => (int) $left]);
            }
        }

        $returns = OrderReturn::query()->where('order_id', $order->id)->get()->filter(fn (OrderReturn $r) => $r->status->isOpen())->count();
        if ($returns > 0) {
            $add('return_open', ['count' => $returns]);
        }

        $remarks = DeliveryRemark::query()->where('order_id', $order->id)->where('status', DeliveryRemark::OPEN)->with('note:id,number')->orderBy('id')->get();
        if ($remarks->isNotEmpty()) {
            $add('remark_open', ['count' => $remarks->count()], array_values($remarks->map(fn (DeliveryRemark $r) => [
                'id' => $r->id, 'note' => $r->note?->number, 'description' => $r->description,
            ])->all()));
        }

        // Remarks from installation visits (ADR-0036): same model as the delivery remarks — every one blocks until it is
        // resolved on its own; listed one per line.
        $installation = InstallationRemarks::openItems($order->id);
        if ($installation !== []) {
            $add('installation_remark_open', ['count' => count($installation)], $installation);
        }

        /** @var list<array{key: string, details: array<string, int>, items?: list<array{id: int, note: string|null, description: string}>}> $blockers */
        return $blockers;
    }

    /**
     * One line per blocker, then one per unresolved remark.
     *
     * @param  list<array{key: string, details: array<string, int>, items?: list<array{id: int, note: string|null, description: string}>}>  $blockers
     * @return list<string>
     */
    public static function messages(array $blockers): array
    {
        $lines = [];
        foreach ($blockers as $b) {
            $lines[] = __('order.closure.blocker.'.$b['key'], $b['details']);
            foreach ($b['items'] ?? [] as $item) {
                $lines[] = __('order.closure.blocker.remark_item', ['note' => (string) $item['note'], 'remark' => mb_strimwidth($item['description'], 0, 120, '…')]);
            }
        }

        return $lines;
    }

    /** The permission the decision needs: P-18 over a cancelled order, P-25 over a finished one. */
    public static function permissionFor(Order $order): Permission
    {
        return in_array($order->state(), [CustomOrderStatus::Cancelled, ReadyOrderStatus::Cancelled], true) ? Permission::P18 : Permission::P25;
    }

    /** Can this order be closed from where it is (state only, not the blockers)? */
    public static function closable(Order $order): bool
    {
        $table = $order->kind === OrderKind::Custom ? CustomOrderStatus::transitions() : ReadyOrderStatus::transitions();
        $code = $order->kind === OrderKind::Custom ? 'T07' : 'T10';

        return in_array($order->state(), $table[$code]['from'], true);
    }

    public function close(User $actor, Order $order, string $reason, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): Order
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => __('order.closure.reason_required')]);
        }

        return LockingTransaction::run(function () use ($actor, $order, $reason, $lockVersion, $source) {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($lockVersion !== null && $locked->lock_version !== $lockVersion) {
                throw new StaleRecord($locked->lock_version);
            }
            $code = $locked->kind === OrderKind::Custom ? 'T07' : 'T10';
            OrderTransitions::check($locked, $code);
            Access::authorize($actor, self::permissionFor($locked), $locked);

            $blockers = self::blockers($locked);
            if ($blockers !== []) {
                throw ValidationException::withMessages(['order' => self::messages($blockers)]);
            }

            $from = $locked->state();
            $to = $locked->kind === OrderKind::Custom ? CustomOrderStatus::Closed : ReadyOrderStatus::Closed;
            $locked->status = $to->value;
            $locked->lock_version++;
            $locked->save();

            DB::table('order_closures')->insert([
                'order_id' => $locked->id, 'from_status' => $from->value, 'reason' => $reason,
                'agreement_version' => OrderAgreement::current($locked)?->version, 'closed_by' => $actor->id, 'closed_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->transitions->record($locked->kind->machine(), $code, $locked, $from, $to, $actor, $source, $reason);
            Audit::record(AuditAction::OrderClosed, $locked, $actor, before: ['status' => $from->value], after: ['status' => $to->value], source: $source, label: $locked->number);

            return $locked;
        });
    }
}
