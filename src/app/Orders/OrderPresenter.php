<?php

namespace App\Orders;

use App\Access\Access;
use App\Access\Permission;
use App\Commissions\CommissionPresenter;
use App\Enums\OrderKind;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Organization\OrgPresenter;

/**
 * Shapes orders for JSON/Inertia. Money (line prices, totals, catalog reference prices inside a snapshot) is left
 * out of the payload — not just hidden in the UI — unless the actor holds P-37 over the order; the payload then
 * says `financials_hidden: true`. An unknown price is `null`, never 0 (COM-08).
 */
final class OrderPresenter
{
    /** Keys removed from a line's frozen catalog payload when money is hidden. */
    private const MONEY_KEYS = ['price_minor', 'reference_price_minor'];

    /**
     * @return array<string, mixed>
     */
    public static function row(Order $order, User $actor): array
    {
        $financials = OrderAccess::canSeeFinancials($actor, $order);

        return [
            'id' => $order->id,
            'number' => $order->number,
            'kind' => $order->kind->value,
            'status' => $order->status,
            'customer' => ['id' => $order->customer_id, 'name' => $order->customer->name],
            'branch' => ['id' => $order->branch_id, 'name' => OrgPresenter::name($order->branch)],
            'assignee' => $order->assignee === null ? null : ['id' => $order->assignee->id, 'name' => $order->assignee->name],
            'purchase_reference' => $order->purchaseReference?->number,
            'created_at' => $order->created_at->toIso8601String(),
            'financials_hidden' => ! $financials,
        ] + ($financials ? ['total_minor' => $order->total_minor] : []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Order $order, User $actor): array
    {
        $order->loadMissing(['customer', 'branch', 'assignee', 'purchaseReference', 'contact']);
        $financials = OrderAccess::canSeeFinancials($actor, $order);
        $siblings = $order->purchase_reference_id === null ? [] : Order::query()
            ->where('purchase_reference_id', $order->purchase_reference_id)->whereKeyNot($order->id)->get()
            ->filter(fn (Order $o) => OrderAccess::canView($actor, $o))
            ->map(fn (Order $o) => ['id' => $o->id, 'number' => $o->number, 'kind' => $o->kind->value])->values()->all();

        return self::row($order, $actor) + [
            'lock_version' => $order->lock_version,
            'customer_id' => $order->customer_id,
            'contact' => $order->contact === null ? null : ['id' => $order->contact->id, 'name' => $order->contact->name, 'mobile' => $order->contact->mobile],
            'need_summary' => $order->need_summary,
            'needs_measurement' => $order->needs_measurement,
            'needs_design' => $order->needs_design,
            'invoice_type' => $order->invoice_type,
            'related_orders' => $siblings,
            'lines' => $order->lines()->get()->map(fn (OrderLine $l) => self::line($l, $financials))->all(),
            'can' => [
                'edit' => Access::can($actor, Permission::P17, $order),
                'transfer_branch' => Access::can($actor, Permission::P19, $order),
                'sale' => $order->kind === OrderKind::Custom && CommissionPresenter::canViewSale($actor, $order),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function line(OrderLine $line, bool $financials): array
    {
        $snapshot = $line->snapshot;
        if (! $financials && isset($snapshot['item']) && is_array($snapshot['item'])) {
            $snapshot['item'] = array_diff_key($snapshot['item'], array_flip(self::MONEY_KEYS));
        }

        return [
            'id' => $line->id,
            'position' => $line->position,
            'type' => $line->line_type->value,
            'catalog_version_id' => $line->catalog_version_id,
            'snapshot' => $snapshot,
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit' => $line->unit,
            'removed' => $line->isRemoved(),
        ] + ($financials ? ['unit_price_minor' => $line->unit_price_minor, 'line_total_minor' => $line->line_total_minor] : []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function auditSnapshot(Order $order): array
    {
        return $order->only([
            'number', 'kind', 'status', 'customer_id', 'contact_id', 'branch_id', 'assignee_id', 'purchase_reference_id',
            'need_summary', 'needs_measurement', 'needs_design', 'invoice_type', 'total_minor',
        ]);
    }
}
