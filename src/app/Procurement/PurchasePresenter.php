<?php

namespace App\Procurement;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\PurchaseStatus;
use App\Files\AttachmentService;
use App\Models\Attachment;
use App\Models\Branch;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseOrderLine;
use App\Models\Procurement\PurchaseReceipt;
use App\Models\Procurement\Supplier;
use App\Models\User;
use App\Organization\OrgPresenter;
use App\Support\Translatable\Translated;

/**
 * Shapes suppliers and purchase orders for the screens. Quantities leave as integer thousandths, amounts as integer
 * halalas. Cost fields (unit cost, line and order amounts) are left out entirely unless the viewer holds P-82 on the
 * order (FUL-05): the screen cannot show what the payload never carried.
 */
final class PurchasePresenter
{
    public function __construct(private readonly AttachmentService $files) {}

    /**
     * @return array{ar: string|null, en: string|null, label: string}
     */
    public static function text(Translated $text): array
    {
        return [...$text->toArray(), 'label' => (string) $text->get()];
    }

    /**
     * @return array<string, mixed>
     */
    public static function supplier(Supplier $s): array
    {
        return [
            'id' => $s->id, 'name' => self::text($s->name), 'contact_name' => $s->contact_name, 'phone' => $s->phone, 'email' => $s->email,
            'vat_number' => $s->vat_number, 'supplies' => $s->supplies, 'notes' => $s->notes, 'status' => $s->status,
            'lock_version' => $s->lock_version,
        ];
    }

    /**
     * A row of the purchase list.
     *
     * @return array<string, mixed>
     */
    public static function row(PurchaseOrder $po, User $viewer): array
    {
        $costs = PurchaseAccess::canSeeCosts($viewer, $po);

        return [
            'id' => $po->id, 'number' => $po->number, 'status' => $po->status->value,
            'supplier' => ['id' => $po->supplier_id, 'name' => self::text($po->supplier->name)],
            'branch' => ['id' => $po->branch_id, 'name' => OrgPresenter::name($po->branch)],
            'expected_on' => $po->expected_on?->toDateString(),
            'lines' => $po->lines->count(),
            'lines_summary' => $po->lines->take(2)->map(fn (PurchaseOrderLine $l) => (string) $l->item_name->get())->values()->all(),
            'costs_visible' => $costs,
            ...($costs ? ['gross_minor' => $po->gross_minor] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(PurchaseOrder $po, User $viewer): array
    {
        $po->load(['supplier', 'branch', 'responsible', 'lines.purchaseOrder', 'receipts.lines', 'receipts.receiver', 'receipts.attachments']);
        $costs = PurchaseAccess::canSeeCosts($viewer, $po);
        $open = $po->status->open();
        $edit = $po->status === PurchaseStatus::Draft && Access::can($viewer, Permission::P46, $po);

        return [
            'id' => $po->id, 'number' => $po->number, 'status' => $po->status->value,
            'supplier' => ['id' => $po->supplier_id, 'name' => self::text($po->supplier->name), 'active' => $po->supplier->isActive()],
            'branch' => ['id' => $po->branch_id, 'name' => OrgPresenter::name($po->branch)],
            'location_id' => $po->location_id,
            'expected_on' => $po->expected_on?->toDateString(),
            'responsible' => $po->responsible === null ? null : ['id' => $po->responsible->id, 'name' => $po->responsible->name],
            'notes' => $po->notes,
            'ordered_at' => $po->ordered_at?->toIso8601String(),
            'cancel_reason' => $po->cancel_reason,
            'cancelled_at' => $po->cancelled_at?->toIso8601String(),
            'lock_version' => $po->lock_version,
            'costs_visible' => $costs,
            ...($costs ? [
                'vat' => ['rate_bp' => $po->vat_rate_bp, 'inclusive' => $po->prices_include_vat],
                'totals' => ['net_minor' => $po->net_minor, 'vat_minor' => $po->vat_minor, 'gross_minor' => $po->gross_minor],
            ] : []),
            'lines' => $po->lines->map(fn (PurchaseOrderLine $l) => $this->line($l, $po, $costs))->values()->all(),
            'receipts' => $po->receipts->map(fn (PurchaseReceipt $r) => $this->receipt($r, $po, $viewer))->values()->all(),
            'can' => [
                'edit' => $edit,
                'confirm' => $edit,
                'receive' => $open && Access::can($viewer, Permission::P47, $po),
                'cancel' => in_array($po->status, [PurchaseStatus::Draft, PurchaseStatus::Ordered, PurchaseStatus::PartiallyReceived], true) && Access::can($viewer, Permission::P46, $po),
                'adjust' => $open && Access::can($viewer, Permission::P46, $po),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(PurchaseOrderLine $l, PurchaseOrder $po, bool $costs): array
    {
        return [
            'id' => $l->id, 'line_no' => $l->line_no, 'item_type' => $l->item_type, 'item_id' => $l->item_id,
            'name' => self::text($l->item_name), 'unit' => $l->unit,
            'quantity_milli' => $l->quantity_milli, 'received_milli' => $l->received_milli, 'accepted_milli' => $l->accepted_milli,
            'rejected_milli' => $l->rejected_milli,
            // A cancelled remainder is no longer waited for.
            'remaining_milli' => $po->status === PurchaseStatus::CancelledRemainder ? 0 : $l->remainingMilli(),
            'cancelled_milli' => $po->status === PurchaseStatus::CancelledRemainder ? $l->remainingMilli() : 0,
            'order_id' => $l->order_id,
            ...($costs ? ['unit_cost_minor' => $l->unit_cost_minor, 'net_minor' => $l->net_minor, 'vat_minor' => $l->vat_minor, 'gross_minor' => $l->gross_minor] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function receipt(PurchaseReceipt $r, PurchaseOrder $po, User $viewer): array
    {
        $r->setRelation('purchaseOrder', $po);

        return [
            'id' => $r->id, 'received_on' => $r->received_on->toDateString(), 'location_id' => $r->location_id,
            'reference' => $r->reference, 'note' => $r->note, 'receiver' => $r->receiver?->name,
            'lines' => $r->lines->map(fn ($l) => [
                'line_no' => $po->lines->firstWhere('id', $l->purchase_order_line_id)?->line_no,
                'received_milli' => $l->received_milli, 'accepted_milli' => $l->accepted_milli,
                'rejected_milli' => $l->rejected_milli, 'reject_reason' => $l->reject_reason,
            ])->values()->all(),
            'files' => $r->attachments->map(fn (Attachment $a) => [
                'uuid' => $a->uuid, 'name' => $a->original_name,
                'url' => $this->files->canDownload($viewer, $a) ? $this->files->downloadUrl($a, $viewer) : null,
            ])->values()->all(),
        ];
    }

    /**
     * Branches the viewer may create purchase orders for (P-46).
     *
     * @return list<array{id: int, name: string}>
     */
    public static function creatableBranches(User $viewer): array
    {
        return array_values(Branch::query()->orderBy('id')->get()
            ->filter(fn (Branch $b) => Access::can($viewer, Permission::P46, new PurchaseOrder(['branch_id' => $b->id])))
            ->map(fn (Branch $b) => ['id' => $b->id, 'name' => OrgPresenter::name($b)])->all());
    }
}
