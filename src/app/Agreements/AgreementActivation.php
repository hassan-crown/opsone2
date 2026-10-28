<?php

namespace App\Agreements;

use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\AgreementStatus;
use App\Models\Agreements\Agreement;
use App\Models\Agreements\CustomerDecision;
use App\Models\Order;
use App\Models\User;
use App\Money\Money;

/**
 * Makes approved content the order's agreement (AGR-01, AGR-07, AGR-09), once: the next version is written, the
 * previous one is only marked superseded, the order shows the new value, and «settlement needed» records what was
 * collected above the new value (AGR-06) — no refund is created here. Runs inside the approval's transaction with
 * the order row locked; a second approval of the same content is refused before reaching it (approval_key).
 */
final class AgreementActivation
{
    public function __construct(private readonly CollectedAmounts $collected) {}

    /**
     * @param  array<string, mixed>  $content  priced, complete content
     */
    public function activate(Order $order, array $content, string $sourceType, int $sourceId, CustomerDecision $decision, ?User $actor, string $source): Agreement
    {
        $previous = Agreement::query()->where('order_id', $order->id)->where('status', AgreementStatus::Active->value)->lockForUpdate()->first();
        $totals = $content['totals'];
        $total = Money::of((int) $totals['total_minor']);
        $previousTotal = $previous === null ? null : Money::of($previous->total_minor);
        $settlement = $this->collected->collectedFor($order)->minus($total)->max(Money::zero());

        if ($previous !== null) {
            $previous->forceFill(['status' => AgreementStatus::Superseded, 'active_key' => null, 'superseded_at' => now()])->save();
        }

        $agreement = Agreement::query()->create([
            'order_id' => $order->id,
            'version' => $previous === null ? 1 : $previous->version + 1,
            'status' => AgreementStatus::Active,
            'active_key' => $order->id,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'customer_decision_id' => $decision->id,
            'content' => $content,
            'net_minor' => (int) $totals['net_minor'],
            'vat_minor' => (int) $totals['vat_minor'],
            'total_minor' => $total->minor,
            'discount_minor' => (int) $totals['discount_minor'],
            'products_minor' => (int) $totals['products_minor'],
            'previous_total_minor' => $previousTotal?->minor,
            'difference_minor' => $total->minus($previousTotal ?? Money::zero())->minor,
            'settlement_needed_minor' => $settlement->minor,
            'approved_at' => $decision->decided_at,
        ]);

        Order::query()->whereKey($order->id)->update(['total_minor' => $total->minor, 'lock_version' => $order->lock_version + 1]);
        $order->total_minor = $total->minor;
        $order->lock_version++;

        Audit::record(AuditAction::AgreementActivated, $order, $actor, before: $previous === null ? [] : [
            'version' => $previous->version, 'total_minor' => $previous->total_minor,
        ], after: [
            'version' => $agreement->version, 'total_minor' => $agreement->total_minor, 'source' => $sourceType.':'.$sourceId,
            'settlement_needed_minor' => $agreement->settlement_needed_minor,
        ], source: $source, label: $order->number);

        AgreementApplied::dispatch($order->id, $agreement->id, $agreement->version, $previousTotal?->minor, $agreement->total_minor,
            $agreement->difference_minor, $agreement->settlement_needed_minor);

        return $agreement;
    }
}
