<?php

namespace App\Delivery;

use App\Enums\DeliveryStatus;
use App\Files\AttachmentService;
use App\Inventory\Quantity;
use App\Models\Attachment;
use App\Models\Delivery\DeliveryNote;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;

/**
 * Delivery notes and what an order still owes, shaped for the screens. Quantities are decimal text; amounts (line
 * values) are present only with P-37 on the order — the keys are omitted otherwise (ADR-0018).
 */
final class DeliveryPresenter
{
    public function __construct(private readonly AttachmentService $files) {}

    /**
     * @return array<string, mixed>
     */
    public static function orderRef(Order $order): array
    {
        return [
            'id' => $order->id, 'number' => $order->number, 'kind' => $order->kind->value, 'status' => $order->status,
            'customer' => $order->customer->name, 'branch_id' => $order->branch_id, 'lock_version' => $order->lock_version,
        ];
    }

    /**
     * What an order still owes, line by line.
     *
     * @return list<array<string, mixed>>
     */
    public static function owed(Order $order, User $viewer): array
    {
        $amounts = DeliveryAccess::canSeeAmounts($viewer, $order);

        return array_values(array_map(fn (array $r) => self::line($r['line'], $amounts) + [
            'ordered' => Quantity::format($r['ordered']), 'delivered' => Quantity::format($r['delivered']),
            'released' => Quantity::format($r['released']), 'deliverable' => Quantity::format($r['deliverable']),
        ], DeliveryProgress::lines($order)));
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(DeliveryNote $note): array
    {
        return [
            'id' => $note->id, 'number' => $note->number, 'status' => $note->status->value,
            'order' => ['id' => $note->order->id, 'number' => $note->order->number, 'kind' => $note->order->kind->value],
            'customer' => $note->order->customer->name,
            'delivered_on' => $note->delivered_on?->toDateString(), 'receiver_name' => $note->receiver_name,
            'created_at' => $note->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(DeliveryNote $note, User $viewer): array
    {
        $note->load(['order.customer', 'lines.orderLine', 'attachments', 'confirmer']);
        $order = $note->order;
        $amounts = DeliveryAccess::canSeeAmounts($viewer, $order);
        $record = DeliveryAccess::canRecord($viewer, $order);
        $draft = $note->status === DeliveryStatus::Draft;
        $rows = $draft ? DeliveryProgress::lines($order) : [];

        return self::row($note) + [
            'note' => $note->note, 'lock_version' => $note->lock_version, 'cancel_reason' => $note->cancel_reason,
            'confirmed_by' => $note->confirmer?->name, 'confirmed_at' => $note->confirmed_at?->toIso8601String(),
            'order' => self::orderRef($order),
            'lines' => $note->lines->map(function ($l) use ($amounts, $rows) {
                /** @var OrderLine $line */
                $line = $l->orderLine;

                return self::line($line, $amounts) + [
                    'quantity' => Quantity::format($l->quantity_milli),
                    'deliverable' => isset($rows[$line->id]) ? Quantity::format($rows[$line->id]['deliverable']) : null,
                ] + ($amounts && $line->unit_price_minor !== null ? ['value_minor' => (int) round($l->quantity_milli * $line->unit_price_minor / 1000)] : []);
            })->values()->all(),
            'proofs' => $note->attachments->map(fn (Attachment $a) => [
                'uuid' => $a->uuid, 'name' => $a->original_name,
                'url' => $this->files->canDownload($viewer, $a) ? $this->files->downloadUrl($a, $viewer) : null,
            ])->values()->all(),
            'can' => [
                'confirm' => $draft && $record && DeliveryProgress::accepts($order),
                'cancel' => $draft && $record,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function line(OrderLine $line, bool $amounts): array
    {
        $item = is_array($line->snapshot['item'] ?? null) ? $line->snapshot['item'] : [];
        $name = $item['name'] ?? null;

        return [
            'order_line_id' => $line->id, 'position' => $line->position, 'type' => $line->line_type->value,
            'name' => is_array($name) ? (string) ($name[app()->getLocale()] ?? $name['ar'] ?? $name['en'] ?? '') : ($line->description ?? ''),
            'description' => $line->description, 'unit' => $line->unit,
        ] + ($amounts && $line->unit_price_minor !== null ? ['unit_price_minor' => $line->unit_price_minor] : []);
    }
}
