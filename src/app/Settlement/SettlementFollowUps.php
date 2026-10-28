<?php

namespace App\Settlement;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\PurchaseStatus;
use App\Enums\RefundStatus;
use App\Enums\StateMachine;
use App\Models\Finance\Refund;
use App\Models\Order;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Settlement\SettlementFollowUp;
use App\Models\Settlement\SettlementItem;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Support\StaleRecord;
use App\Workflow\WorkGuard;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The one settlement follow-up per order (SRS-INT-08, FIN-14, M-34 «إحالة التسويات»). A cancellation (and later a
 * return, P-53) adds the actions its decision leaves; each item is followed from its own source and never performed
 * here: a refund is made through P6 M-12 (its limit reserved there), a supplier order is settled in procurement, stock
 * is released by the decision itself. The follow-up closes only when every item is done or dropped, and reopens when a
 * later source adds an item.
 *
 * Every write runs inside the caller's transaction with the order row locked first.
 */
final class SettlementFollowUps
{
    /**
     * The order's follow-up, created on first use and reopened when it was closed. Call under the order lock.
     */
    public function open(Order $order): SettlementFollowUp
    {
        self::assertTransaction();
        $followUp = SettlementFollowUp::query()->where('order_id', $order->id)->lockForUpdate()->first();
        if ($followUp === null) {
            return SettlementFollowUp::query()->create(['order_id' => $order->id, 'status' => SettlementFollowUp::OPEN]);
        }
        if (! $followUp->isOpen()) {
            $followUp->forceFill(['status' => SettlementFollowUp::OPEN, 'closed_at' => null, 'lock_version' => $followUp->lock_version + 1])->save();
        }

        return $followUp;
    }

    /**
     * Adds one item, once per `source` + `kind` + reference (a replay returns the existing item). `done` records an
     * action the decision already performed (stock released, commission corrected) so the list stays complete.
     *
     * @param  array<string, mixed>  $details  non-sensitive values for the screen (numbers, quantities)
     */
    public function add(Order $order, string $kind, string $source, array $details = [], ?int $targetMinor = null, ?string $refType = null, ?int $refId = null, bool $done = false): SettlementItem
    {
        if (! in_array($kind, SettlementItem::KINDS, true)) {
            throw new LogicException("Unknown settlement item kind {$kind}.");
        }
        $key = mb_substr($source.':'.$kind.':'.($refType ?? '-').':'.($refId ?? 0), 0, 120);
        $existing = SettlementItem::query()->where('item_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }
        $followUp = $this->open($order);

        return SettlementItem::query()->create([
            'settlement_followup_id' => $followUp->id, 'order_id' => $order->id, 'kind' => $kind, 'source' => $source,
            'ref_type' => $refType, 'ref_id' => $refId, 'item_key' => $key, 'details' => $details === [] ? null : $details,
            'target_minor' => $targetMinor, 'status' => $done ? SettlementItem::DONE : SettlementItem::OPEN,
            'resolution' => $done ? 'auto' : null, 'resolved_at' => $done ? now() : null,
        ]);
    }

    /**
     * Re-reads every open item from its source and closes the follow-up when nothing is left. Idempotent; call under
     * the order lock (cancellation apply, refund executed, the follow-up screen).
     */
    public function sync(Order $order, ?User $actor = null): ?SettlementFollowUp
    {
        self::assertTransaction();
        $followUp = SettlementFollowUp::query()->where('order_id', $order->id)->lockForUpdate()->first();
        if ($followUp === null) {
            return null;
        }
        $items = SettlementItem::query()->where('settlement_followup_id', $followUp->id)->orderBy('id')->lockForUpdate()->get();
        $changed = false;

        foreach (self::refundCoverage($order, $items) as $itemId => $covered) {
            $item = $items->firstWhere('id', $itemId);
            if ($item instanceof SettlementItem && $item->isOpen() && $covered >= (int) $item->target_minor) {
                $this->markDone($item, 'auto', null, null);
                $changed = true;
            }
        }
        foreach ($items->where('kind', SettlementItem::SUPPLIER)->filter(fn (SettlementItem $i) => $i->isOpen()) as $item) {
            /** @var SettlementItem $item */
            $status = PurchaseOrder::query()->find($item->ref_id)?->status;
            if ($status === null || in_array($status, [PurchaseStatus::Received, PurchaseStatus::CancelledRemainder], true)) {
                $this->markDone($item, 'auto', null, null);
                $changed = true;
            }
        }

        $closed = $this->closeIfDone($followUp, $actor);

        return $changed || $closed ? $followUp->refresh() : $followUp;
    }

    /**
     * A person settles an item another way (P-43 refund, P-46 supplier order, P-18 otherwise — the permission of the
     * action the item stands for, INT-08: «تطبق صلاحية الإجراء الأصلي»): `done` with what was done, or `dropped`
     * with why it is not needed (the customer keeps the credit, the supplier delivered for stock).
     */
    public function resolve(User $actor, SettlementItem $item, string $status, string $note, ?int $lockVersion): SettlementItem
    {
        $note = WorkGuard::reason($note, 'note');
        if (! in_array($status, [SettlementItem::DONE, SettlementItem::DROPPED], true)) {
            throw new LogicException('An item is resolved as done or dropped.');
        }

        return LockingTransaction::run(function () use ($actor, $item, $status, $note, $lockVersion) {
            $order = Order::query()->lockForUpdate()->findOrFail($item->order_id);
            $this->sync($order, $actor);
            $locked = SettlementItem::query()->lockForUpdate()->findOrFail($item->id);
            $locked->setRelation('order', $order);
            Access::authorize($actor, self::permissionFor($locked->kind), $order);
            if ($lockVersion !== null && $locked->lock_version !== $lockVersion) {
                throw new StaleRecord($locked->lock_version);
            }
            if (! $locked->isOpen()) {
                throw new InvalidTransition(StateMachine::M34, 'settle', 'not_from_'.$locked->status);
            }
            $this->markDone($locked, 'manual', $actor, $note, $status);
            Audit::record(AuditAction::SettlementItemResolved, $order, $actor, before: ['status' => SettlementItem::OPEN],
                after: ['item' => $locked->kind, 'status' => $status, 'source' => $locked->source], reason: $note, label: $order->number);
            $this->closeIfDone($locked->followUp()->lockForUpdate()->firstOrFail(), $actor);

            return $locked;
        });
    }

    public static function permissionFor(string $kind): Permission
    {
        return match ($kind) {
            SettlementItem::REFUND => Permission::P43,
            SettlementItem::SUPPLIER => Permission::P46,
            default => Permission::P18,
        };
    }

    public static function forOrder(Order $order): ?SettlementFollowUp
    {
        return SettlementFollowUp::query()->where('order_id', $order->id)->first();
    }

    /**
     * Executed refunds on the order since each refund item opened, first come first covered (FIFO by item).
     *
     * @param  iterable<SettlementItem>  $items
     * @return array<int, int> item id → amount covered
     */
    public static function refundCoverage(Order $order, iterable $items): array
    {
        $refundItems = [];
        foreach ($items as $item) {
            if ($item->kind === SettlementItem::REFUND && $item->resolution !== 'manual') {
                $refundItems[] = $item;
            }
        }
        if ($refundItems === []) {
            return [];
        }
        $since = min(array_map(fn (SettlementItem $i) => $i->created_at, $refundItems));
        $pool = (int) Refund::query()->where('order_id', $order->id)->where('status', RefundStatus::Executed->value)
            ->where('executed_at', '>=', $since)->sum('amount_minor');
        $covered = [];
        foreach ($refundItems as $item) {
            $target = (int) $item->target_minor;
            $covered[$item->id] = min($pool, $target);
            $pool = max(0, $pool - $target);
        }

        return $covered;
    }

    private function markDone(SettlementItem $item, string $resolution, ?User $actor, ?string $note, string $status = SettlementItem::DONE): void
    {
        $item->forceFill([
            'status' => $status, 'resolution' => $resolution, 'note' => $note, 'resolved_by' => $actor?->id, 'resolved_at' => now(),
            'lock_version' => $item->lock_version + 1,
        ])->save();
    }

    private function closeIfDone(SettlementFollowUp $followUp, ?User $actor): bool
    {
        if (! $followUp->isOpen() || SettlementItem::query()->where('settlement_followup_id', $followUp->id)->where('status', SettlementItem::OPEN)->exists()) {
            return false;
        }
        $followUp->forceFill(['status' => SettlementFollowUp::CLOSED, 'closed_at' => now(), 'lock_version' => $followUp->lock_version + 1])->save();
        $order = $followUp->order;
        Audit::record(AuditAction::SettlementClosed, $order, $actor, after: ['status' => SettlementFollowUp::CLOSED], label: $order->number);

        return true;
    }

    private static function assertTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Settlement follow-up writes run inside the decision\'s transaction.');
        }
    }
}
