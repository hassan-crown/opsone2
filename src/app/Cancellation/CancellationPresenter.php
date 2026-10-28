<?php

namespace App\Cancellation;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Enums\CancellationStatus;
use App\Models\Agreements\CustomerDecision;
use App\Models\Cancellation\OrderCancellation;
use App\Models\Order;
use App\Models\Settlement\SettlementFollowUp;
use App\Models\Settlement\SettlementItem;
use App\Models\User;
use App\Settlement\SettlementFollowUps;

/**
 * Payloads of CancelReview and the settlement follow-up. Amounts (`*_minor`) are present only for a viewer with P-37
 * on the order and supplier costs only with P-82 — the keys are left out, never zeroed («hidden», ADR-0013).
 */
final class CancellationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function page(Order $order, User $viewer): array
    {
        $order->loadMissing('customer');
        $amounts = Access::can($viewer, Permission::P37, $order);
        $review = Access::can($viewer, Permission::P18, $order);
        $pending = OrderCancellation::query()->where('active_key', $order->id)->first();
        $cancellable = true;
        try {
            CancellationImpact::ensureCancellable($order);
        } catch (InvalidTransition) {
            $cancellable = false;
        }

        return [
            'order' => [
                'id' => $order->id, 'number' => $order->number, 'kind' => $order->kind->value, 'status' => $order->status,
                'customer' => $order->customer->name, 'lock_version' => $order->lock_version,
            ],
            'amounts_visible' => $amounts,
            'lines' => $review ? array_values(array_map(fn (array $r) => self::mask([
                'ref' => $r['ref'], 'description' => $r['description'], 'unit' => $r['unit'], 'ordered_milli' => $r['ordered_milli'],
                'delivered_milli' => $r['delivered_milli'], 'cancelled_milli' => $r['cancelled_milli'], 'cancellable_milli' => $r['cancellable_milli'],
                'whole_only' => $r['whole_only'], 'unit_price_minor' => $r['unit_price_minor'],
            ], $amounts), CancellableLines::for($order))) : [],
            'pending' => $pending === null ? null : self::request($pending, $amounts),
            'history' => array_values(OrderCancellation::query()->where('order_id', $order->id)->where('status', '!=', CancellationStatus::Pending->value)
                ->orderByDesc('id')->limit(10)->get()->map(fn (OrderCancellation $c) => self::request($c, $amounts))->all()),
            'settlement' => self::followUp(SettlementFollowUps::forOrder($order), $viewer, $order),
            'channels' => CustomerDecision::CHANNELS,
            'can' => [
                'review' => $review && $cancellable,
                'amounts' => $amounts,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $impact
     * @return array<string, mixed>
     */
    public static function impact(array $impact, User $viewer, Order $order): array
    {
        $amounts = Access::can($viewer, Permission::P37, $order);
        $costs = Access::can($viewer, Permission::P82, $order);
        unset($impact['content']);
        $purchases = $impact['settlement']['purchases'];
        $impact = self::mask($impact, $amounts);
        $impact['settlement']['purchases'] = self::mask($purchases, $costs);
        if (! $amounts && $impact['settlement']['commission'] !== null) {
            $impact['settlement']['commission'] = ['user' => $impact['settlement']['commission']['user']];
        }

        return $impact;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function followUp(?SettlementFollowUp $followUp, User $viewer, Order $order): ?array
    {
        if ($followUp === null || ! Access::can($viewer, Permission::P16, $order)) {
            return null;
        }
        $amounts = Access::can($viewer, Permission::P37, $order);
        $items = $followUp->items()->with('resolver')->get();
        $covered = SettlementFollowUps::refundCoverage($order, $items);

        return [
            'id' => $followUp->id, 'status' => $followUp->status, 'closed_at' => $followUp->closed_at?->toIso8601String(),
            'lock_version' => $followUp->lock_version,
            'items' => array_values($items->map(fn (SettlementItem $i) => self::mask([
                'id' => $i->id, 'kind' => $i->kind, 'source' => $i->source, 'status' => $i->status, 'resolution' => $i->resolution,
                'note' => $i->note, 'details' => $i->details ?? [], 'ref_type' => $i->ref_type, 'ref_id' => $i->ref_id,
                'target_minor' => $i->target_minor, 'covered_minor' => $covered[$i->id] ?? null,
                'resolved_at' => $i->resolved_at?->toIso8601String(), 'resolver' => $i->resolver?->name, 'lock_version' => $i->lock_version,
                'can_resolve' => $i->isOpen() && Access::can($viewer, SettlementFollowUps::permissionFor($i->kind), $order),
            ], $amounts))->all()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function request(OrderCancellation $c, bool $amounts): array
    {
        $c->loadMissing(['requester', 'decider']);

        return self::mask([
            'id' => $c->id, 'number' => $c->number, 'scope' => $c->scope, 'status' => $c->status->value, 'source' => $c->source,
            'channel' => $c->channel, 'requested_on' => $c->requested_on->toDateString(), 'reason' => $c->reason, 'lines' => $c->lines ?? [],
            'retained_minor' => $c->retained_minor, 'decision_reason' => $c->decision_reason, 'requested_by' => $c->requester?->name,
            'decided_by' => $c->decider?->name, 'decided_at' => $c->decided_at?->toIso8601String(), 'lock_version' => $c->lock_version,
            'applied' => $c->impact === null ? null : [
                'status_after' => $c->impact['order']['status_after'] ?? null,
                'refund_minor' => $c->impact['settlement']['money']['refund_minor'] ?? null,
                'lines' => $c->impact['lines'] ?? [],
            ],
        ], $amounts);
    }

    /**
     * Drops every `*_minor` key when `$visible` is false (recursively).
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function mask(array $data, bool $visible): array
    {
        if ($visible) {
            return $data;
        }
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && str_ends_with($key, '_minor')) {
                continue;
            }
            $out[$key] = is_array($value) ? self::mask($value, false) : $value;
        }

        return $out;
    }
}
