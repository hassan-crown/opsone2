<?php

namespace App\Operations\Closure;

use App\Enums\CustomOrderStatus;
use App\Enums\DisputeStatus;
use App\Enums\OrderKind;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\ProofStatus;
use App\Enums\PurchaseStatus;
use App\Enums\ReadyOrderStatus;
use App\Enums\RefundStatus;
use App\Enums\ReturnStatus;
use App\Finance\OpenDues;
use App\Inventory\ReservationStatus;
use App\Models\Order;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Facility closure readiness (Q-53 a, FIN-12): what still stands between the facility and ending its service,
 * computed from the source records every time (no stored flags). Nothing here closes, refunds or deletes anything:
 * each item is resolved on its own screen, through its own transition.
 */
final class ClosureReadiness
{
    /**
     * @return list<array{key: string, count: int, amount_minor: int|null, bounded: bool}>
     */
    public function items(): array
    {
        $dues = OpenDues::of(Order::query());

        return [
            $this->item('open_orders', $this->openOrders()),
            [
                'key' => 'unpaid_dues',
                'count' => count($dues),
                'amount_minor' => array_sum(array_column($dues, 'remaining')),
                'bounded' => count(array_unique(array_map(fn (array $d) => $d['order']->id, $dues))) >= OpenDues::LIMIT,
            ],
            $this->item('pending_payments', DB::table('payments')->whereIn('status', [PaymentStatus::PendingConfirmation->value, PaymentStatus::Unresolved->value]), 'amount_minor'),
            $this->item('open_proofs', DB::table('payment_proofs')->whereIn('status', [ProofStatus::UnderReview->value, ProofStatus::NeedsCorrection->value])),
            $this->item('open_refunds', DB::table('refunds')->whereIn('status', [RefundStatus::Requested->value, RefundStatus::InProgress->value, RefundStatus::Unresolved->value, RefundStatus::Failed->value]), 'amount_minor'),
            $this->item('open_disputes', DB::table('financial_disputes')->whereIn('status', [DisputeStatus::Open->value, DisputeStatus::AwaitingExternal->value]), 'amount_minor'),
            $this->item('open_payouts', DB::table('commission_payouts')->whereIn('status', [PayoutStatus::Draft->value, PayoutStatus::Reserved->value]), 'amount_minor'),
            $this->item('open_settlements', DB::table('settlement_followups')->where('status', 'open')),
            $this->item('open_returns', DB::table('order_returns')->whereIn('status', array_values(array_map(fn (ReturnStatus $s) => $s->value, array_filter(ReturnStatus::cases(), fn (ReturnStatus $s) => $s->isOpen()))))),
            $this->item('stock_reservations', DB::table('stock_reservations')->whereIn('status', [ReservationStatus::Active->value, ReservationStatus::PartiallyIssued->value])),
            $this->item('open_purchases', DB::table('purchase_orders')->whereIn('status', array_values(array_map(fn (PurchaseStatus $s) => $s->value, array_filter(PurchaseStatus::cases(), fn (PurchaseStatus $s) => $s->open()))))),
        ];
    }

    /**
     * @param  list<array{key: string, count: int, amount_minor: int|null, bounded: bool}>  $items
     */
    public static function ready(array $items): bool
    {
        return array_sum(array_column($items, 'count')) === 0;
    }

    private function openOrders(): Builder
    {
        $final = fn (array $cases) => array_values(array_map(fn ($s) => $s->value, array_filter($cases, fn ($s) => $s->isFinal())));

        return DB::table('orders')->where(fn (Builder $q) => $q
            ->where(fn (Builder $c) => $c->where('kind', OrderKind::Custom->value)->whereNotIn('status', $final(CustomOrderStatus::cases())))
            ->orWhere(fn (Builder $r) => $r->where('kind', OrderKind::Ready->value)->whereNotIn('status', $final(ReadyOrderStatus::cases()))));
    }

    /**
     * @return array{key: string, count: int, amount_minor: int|null, bounded: bool}
     */
    private function item(string $key, Builder $query, ?string $amount = null): array
    {
        return [
            'key' => $key,
            'count' => (clone $query)->count(),
            'amount_minor' => $amount === null ? null : (int) (clone $query)->sum($amount),
            'bounded' => false,
        ];
    }
}
