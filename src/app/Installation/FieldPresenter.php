<?php

namespace App\Installation;

use App\Calendar\CalendarPresenter;
use App\Files\AttachmentService;
use App\Models\Appointment;
use App\Models\Installation\InstallationPhoto;
use App\Models\Installation\InstallationRemark;
use App\Models\OrderLine;
use App\Models\User;
use App\Support\Translatable\Translated;

/**
 * Payloads of the field app (ADR-0036). A booked technician needs to reach the customer, so their own visits carry
 * the customer's name, phone and address (need-to-know, only for appointments they are booked on); never amounts,
 * internal notes or other orders.
 */
final class FieldPresenter
{
    public function __construct(private readonly AttachmentService $files) {}

    /**
     * Eager loads of a list or a visit (lazy loading is refused outside production, ADR-0027).
     *
     * @return list<string>
     */
    public static function with(): array
    {
        return ['order.customer', 'order.contact', 'order.lines', 'members', 'installationVisit'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(Appointment $a): array
    {
        $start = CalendarPresenter::local($a->starts_at);
        $end = CalendarPresenter::local($a->ends_at);
        $order = $a->order;
        $contact = $order->contact;
        $visit = $a->installationVisit;

        return [
            'id' => $a->id,
            'date' => $start->format('Y-m-d'),
            'start' => $start->format('H:i'),
            'end' => $end->format('H:i'),
            'appointment_status' => $a->status->value,
            'status' => InstallationStatus::of($visit)->value,
            'order' => ['id' => $order->id, 'number' => $order->number],
            'customer' => [
                'name' => $order->customer->name,
                'phone' => $contact !== null ? $contact->mobile : $order->customer->mobile,
                'contact' => $contact?->name,
            ],
            'address' => $a->address,
            'maps_url' => $a->mapsUrl(),
            'items' => array_values($order->lines->filter(fn (OrderLine $l) => ! $l->isRemoved())->map(fn (OrderLine $l) => [
                'name' => self::lineName($l), 'quantity' => self::quantity($l->quantity), 'unit' => $l->unit,
            ])->all()),
            'notes' => $order->need_summary,
            'team' => array_values($a->members->map(fn (User $u) => $u->name)->all()),
        ];
    }

    /**
     * The visit screen: the row plus what has been done on site so far.
     *
     * @return array<string, mixed>
     */
    public function visit(Appointment $a, User $viewer): array
    {
        $visit = $a->installationVisit;
        $photos = $visit === null ? collect() : InstallationPhoto::query()->where('installation_visit_id', $visit->id)->with('attachment')->orderBy('id')->get();
        $remarks = $visit === null ? collect() : InstallationRemark::query()->where('installation_visit_id', $visit->id)->with(['creator:id,name', 'resolver:id,name', 'followUp:id,name'])->orderBy('id')->get();
        $signature = $visit?->signature_attachment_id === null ? null : $visit->signature()->first();

        return self::row($a) + [
            'visit' => $visit === null ? null : [
                'id' => $visit->id,
                'lock_version' => $visit->lock_version,
                'arrived_at' => CalendarPresenter::local($visit->arrived_at)->format('Y-m-d H:i'),
                'located' => $visit->arrival_latitude !== null,
                'finished_at' => $visit->finished_at === null ? null : CalendarPresenter::local($visit->finished_at)->format('Y-m-d H:i'),
                'duration_minutes' => $visit->durationMinutes(),
                'signed' => $visit->isSigned(),
                'signer_name' => $visit->signer_name,
                'signature_method' => $visit->signature_method,
                'signed_at' => $visit->signed_at === null ? null : CalendarPresenter::local($visit->signed_at)->format('Y-m-d H:i'),
                'signature_url' => $signature === null ? null : $this->files->downloadUrl($signature, $viewer),
                'signature_requested' => $visit->signature_requested_at !== null,
            ],
            'photos' => array_values($photos->map(fn (InstallationPhoto $p) => [
                'id' => $p->id, 'phase' => $p->phase, 'client_uuid' => $p->client_uuid, 'url' => $this->files->downloadUrl($p->attachment, $viewer),
            ])->all()),
            'remarks' => array_values($remarks->map(fn (InstallationRemark $r) => self::remark($r))->all()),
            'min_photos' => FieldVisits::MIN_PHOTOS_PER_PHASE,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function remark(InstallationRemark $r): array
    {
        return InstallationRemarks::row($r) + ['created_at' => CalendarPresenter::local($r->created_at)->format('Y-m-d H:i')];
    }

    public static function lineName(OrderLine $line): string
    {
        if ($line->description !== null && ! isset($line->snapshot['item'])) {
            return $line->description;
        }
        $item = is_array($line->snapshot['item'] ?? null) ? $line->snapshot['item'] : [];
        $name = Translated::fromArray(is_array($item['name'] ?? null) ? $item['name'] : null)->get() ?? '';
        $product = Translated::fromArray(is_array($item['product_name'] ?? null) ? $item['product_name'] : null)->get();

        return $product !== null && $product !== '' ? $product.' — '.$name : ($name !== '' ? $name : (string) $line->description);
    }

    /** `2.000` → `2`, `1.500` → `1.5`. */
    private static function quantity(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }
}
