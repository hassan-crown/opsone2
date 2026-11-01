<?php

namespace App\EInvoicing;

use App\Access\Access;
use App\Access\Permission;
use App\Models\EInvoicing\EInvoice;
use App\Models\Order;
use App\Models\User;

/**
 * The order's ZATCA documents for its finance tab (ADR-0032): only for whoever sees the order's money (P-37), else
 * nothing at all (amounts are never hidden field by field here).
 */
final class EInvoicePresenter
{
    /**
     * @return list<array{id: int, number: string, kind: string, type: string, total_minor: int, issue_date: string, status: string}>
     */
    public static function forOrder(Order $order, User $actor): array
    {
        if (! Access::can($actor, Permission::P37, $order)) {
            return [];
        }

        return array_values(EInvoice::query()->where('order_id', $order->id)->orderBy('id')->get()->map(fn (EInvoice $i) => [
            'id' => $i->id,
            'number' => $i->number,
            'kind' => $i->kind,
            'type' => $i->type,
            'total_minor' => $i->total_minor,
            'issue_date' => $i->issue_date->format('Y-m-d'),
            'status' => $i->submission_status,
        ])->all());
    }
}
