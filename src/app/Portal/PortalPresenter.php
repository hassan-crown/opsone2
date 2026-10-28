<?php

namespace App\Portal;

use App\Agreements\OrderAgreement;
use App\Enums\AppointmentStatus;
use App\Enums\CancellationStatus;
use App\Enums\CustomOrderStatus;
use App\Enums\DocumentStatus;
use App\Enums\DueStatus;
use App\Enums\ProofStatus;
use App\Enums\ReadyOrderStatus;
use App\Finance\Allocator;
use App\Finance\DueCalendar;
use App\Finance\MoneyLedger;
use App\Models\Agreements\AgreementDocument;
use App\Models\Agreements\CustomerDecision;
use App\Models\Appointment;
use App\Models\Attachment;
use App\Models\Cancellation\OrderCancellation;
use App\Models\Customer;
use App\Models\Finance\OrderDue;
use App\Models\Finance\PaymentProof;
use App\Models\Finance\Receipt;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderStage;
use App\Workflow\AppointmentBooking;
use Carbon\CarbonImmutable;

/**
 * Customer-facing payloads (P-66, WEB-09/10). Built from an allow-list, never from a staff presenter: no costs, no
 * internal notes, no staff names or assignees, no departments or task lists, no commission data, no branch internals.
 * Stages appear only when the flow marks them for the customer, by their public name.
 */
final class PortalPresenter
{
    /** Document content keys a customer may read; everything else (commission flags, line ids, sources) stays out. */
    private const DOCUMENT_KEYS = ['description', 'language', 'parties', 'execution_days', 'terms', 'agreement_terms', 'clauses'];

    private const LINE_KEYS = ['description', 'quantity', 'unit', 'unit_price_minor', 'discount_minor', 'net_minor', 'vat_minor', 'gross_minor'];

    private const TOTAL_KEYS = ['discount_minor', 'net_minor', 'vat_minor', 'total_minor'];

    /**
     * @return array<string, mixed>
     */
    public static function order(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'kind' => $order->kind->value,
            'status' => $order->status,
            'open' => ! in_array($order->status, [CustomOrderStatus::Cancelled->value, CustomOrderStatus::Closed->value, ReadyOrderStatus::Delivered->value], true),
            'title' => self::title($order),
            'created_on' => $order->created_at->toDateString(),
            'stage' => self::currentStage($order),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function orderDetail(Customer $customer, Order $order): array
    {
        $appointments = PortalAccess::appointments($customer)->where('order_id', $order->id)->orderBy('starts_at')->get();
        $documents = PortalAccess::documents($customer)->where('order_id', $order->id)->orderByDesc('sent_at')->get();
        $proofs = PortalAccess::proofs($customer)->with('attachments')->where('order_id', $order->id)->orderByDesc('id')->get();
        $receipts = PortalAccess::receipts($customer)->where('order_id', $order->id)->orderByDesc('issued_at')->get();
        $cancellation = PortalAccess::cancellations($customer)->where('order_id', $order->id)->latest('id')->first();

        return self::order($order) + [
            'lines' => $order->isReady() ? array_values($order->activeLines()->orderBy('position')->get()
                ->map(fn (OrderLine $l) => ['description' => $l->description, 'quantity' => $l->quantity, 'unit' => $l->unit])->all()) : [],
            'timeline' => self::timeline($order),
            'finance' => self::finance($order),
            'proofs' => array_values($proofs->map(fn (PaymentProof $p) => self::proof($customer, $p))->all()),
            'receipts' => array_values($receipts->map(fn (Receipt $r) => self::receipt($r, $order))->all()),
            'appointments' => array_values($appointments->map(fn (Appointment $a) => self::appointment($a, $order))->all()),
            'documents' => array_values($documents->map(fn (AgreementDocument $d) => self::documentSummary($d, $order))->all()),
            'cancellation' => $cancellation === null ? null : self::cancellation($cancellation),
            'can' => [
                'upload_proof' => self::acceptsProofs($order),
                'request_cancellation' => self::cancellable($order) && ($cancellation === null || $cancellation->status !== CancellationStatus::Pending),
            ],
        ];
    }

    /**
     * «مطلوب منك» (WEB-03/09): what waits on the customer now, each with its place to act.
     *
     * @return list<array<string, mixed>>
     */
    public static function requiredActions(Customer $customer): array
    {
        $items = [];
        foreach (PortalAccess::documents($customer)->with('order')->where('status', DocumentStatus::AwaitingCustomer->value)->orderBy('sent_at')->get() as $document) {
            $items[] = ['type' => 'document', 'id' => $document->id, 'order' => $document->order->number, 'order_id' => $document->order_id,
                'kind' => $document->kind->value, 'number' => $document->number, 'since' => $document->sent_at?->toDateString()];
        }
        foreach (PortalAccess::appointments($customer)->with('order')->where('status', AppointmentStatus::AwaitingConfirmation->value)->orderBy('starts_at')->get() as $appointment) {
            $items[] = ['type' => 'appointment', 'id' => $appointment->id, 'order' => $appointment->order->number, 'order_id' => $appointment->order_id,
                'kind' => $appointment->kind, 'starts_at' => self::local($appointment->starts_at)];
        }
        foreach (PortalAccess::proofs($customer)->with('order')->where('status', ProofStatus::NeedsCorrection->value)->get() as $proof) {
            if (PaymentProof::query()->where('replaces_id', $proof->id)->exists()) {
                continue;
            }
            $items[] = ['type' => 'proof', 'id' => $proof->id, 'order' => $proof->order->number, 'order_id' => $proof->order_id,
                'reason' => $proof->correction_reason];
        }
        foreach (PortalAccess::orders($customer)->get() as $order) {
            $finance = self::finance($order);
            if (($finance['due_now_minor'] ?? 0) > 0 && self::acceptsProofs($order)) {
                $items[] = ['type' => 'payment', 'id' => $order->id, 'order' => $order->number, 'order_id' => $order->id,
                    'amount_minor' => $finance['due_now_minor'], 'pending_minor' => $finance['pending_proofs_minor']];
            }
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public static function document(Customer $customer, AgreementDocument $document): array
    {
        $order = $document->order;
        $content = $document->content;
        $safe = array_intersect_key($content, array_flip(self::DOCUMENT_KEYS));
        if (isset($content['lines']) && is_array($content['lines'])) {
            $safe['lines'] = array_values(array_map(fn ($l) => is_array($l) ? array_intersect_key($l, array_flip(self::LINE_KEYS)) : [], $content['lines']));
        }
        if (isset($content['totals']) && is_array($content['totals'])) {
            $safe['totals'] = array_intersect_key($content['totals'], array_flip(self::TOTAL_KEYS));
        }
        if (isset($content['vat']) && is_array($content['vat'])) {
            $safe['vat'] = array_intersect_key($content['vat'], array_flip(['rate_bp', 'inclusive']));
        }
        $decision = CustomerDecision::query()->where('subject_type', 'document')->where('subject_id', $document->id)->latest('id')->first();
        $replacement = AgreementDocument::query()->where('replaces_id', $document->id)->whereNotNull('sent_at')->first(['id', 'number']);

        return self::documentSummary($document, $order) + [
            'content' => $safe,
            'content_hash' => $document->content_hash,
            'valid_until' => $document->valid_until?->toDateString(),
            'files' => array_values($document->attachments()->get()->map(fn (Attachment $a) => self::file($customer, $a))->all()),
            'decision' => $decision === null ? null : [
                'decision' => $decision->decision, 'decided_at' => $decision->decided_at->toIso8601String(),
                'by_customer' => $decision->method === CustomerDecision::METHOD_ACCOUNT, 'note' => $decision->note,
            ],
            'replaced_by' => $replacement === null ? null : ['id' => $replacement->id, 'number' => $replacement->number],
            'can' => [
                'decide' => $document->status === DocumentStatus::AwaitingCustomer
                    && ($document->valid_until === null || ! $document->valid_until->copy()->endOfDay()->isPast()),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function documentSummary(AgreementDocument $document, Order $order): array
    {
        return [
            'id' => $document->id,
            'kind' => $document->kind->value,
            'number' => $document->number,
            'status' => $document->status->value,
            'order' => $order->number,
            'order_id' => $order->id,
            'sent_on' => $document->sent_at?->toDateString(),
            'decided_at' => $document->decided_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function proof(Customer $customer, PaymentProof $proof): array
    {
        return [
            'id' => $proof->id,
            'status' => $proof->status->value,
            'claimed_minor' => $proof->claimed_minor,
            'method' => $proof->method->value,
            'paid_on' => $proof->paid_on->toDateString(),
            'reference' => $proof->reference,
            'correction_reason' => $proof->status === ProofStatus::NeedsCorrection ? $proof->correction_reason : null,
            'replaced' => PaymentProof::query()->where('replaces_id', $proof->id)->exists(),
            'submitted_at' => $proof->created_at->toIso8601String(),
            'files' => array_values($proof->attachments->map(fn (Attachment $a) => self::file($customer, $a))->all()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function receipt(Receipt $receipt, Order $order): array
    {
        return [
            'id' => $receipt->id,
            'number' => $receipt->number,
            'amount_minor' => $receipt->amount_minor,
            'issued_on' => $receipt->issued_at->toDateString(),
            'order' => $order->number,
            'order_id' => $order->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function appointment(Appointment $appointment, Order $order): array
    {
        $change = $appointment->pending_change;

        return [
            'id' => $appointment->id,
            'kind' => $appointment->kind,
            'status' => $appointment->status->value,
            'starts_at' => self::local($appointment->starts_at),
            'ends_at' => self::local($appointment->ends_at),
            'address' => $appointment->address,
            'maps_url' => $appointment->mapsUrl(),
            'order' => $order->number,
            'order_id' => $order->id,
            // The facility's proposed new time (M-16-T03): the original stays until they approve it.
            'proposed' => $change === null ? null : ['starts_at' => self::local($change['starts_at']), 'ends_at' => self::local($change['ends_at'])],
            'customer_request' => $appointment->customer_request,
            'can' => [
                'confirm' => $appointment->status === AppointmentStatus::AwaitingConfirmation,
                'request_change' => in_array($appointment->status, [AppointmentStatus::AwaitingConfirmation, AppointmentStatus::Confirmed], true)
                    && $appointment->customer_request === null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function cancellation(OrderCancellation $cancellation): array
    {
        return [
            'id' => $cancellation->id,
            'number' => $cancellation->number,
            'status' => $cancellation->status->value,
            'reason' => $cancellation->source === OrderCancellation::SOURCE_ACCOUNT ? $cancellation->reason : null,
            'requested_on' => $cancellation->requested_on->toDateString(),
            // The decision reason is addressed to the customer (rejection must be explained, WEB-13).
            'decision_reason' => $cancellation->status === CancellationStatus::Rejected ? $cancellation->decision_reason : null,
            'decided_at' => $cancellation->decided_at?->toIso8601String(),
            'can_withdraw' => $cancellation->status === CancellationStatus::Pending && $cancellation->source === OrderCancellation::SOURCE_ACCOUNT,
        ];
    }

    /**
     * Money of the order as the customer sees it: agreed value, confirmed collections, what is due now and the schedule.
     * Proofs under review are shown apart and never counted as paid.
     *
     * @return array<string, mixed>
     */
    public static function finance(Order $order): array
    {
        $agreement = OrderAgreement::current($order);
        $collected = MoneyLedger::collected($order->id);
        $dues = Allocator::openDues($order->id);
        $allocated = MoneyLedger::allocatedByDue($order->id);
        $dates = DueCalendar::dates($order, $dues);
        $today = AppointmentBooking::today();
        $dueNow = 0;
        $rows = [];
        foreach ($dues as $due) {
            /** @var OrderDue $due */
            $held = (int) ($allocated[$due->id] ?? 0);
            $on = $dates[$due->id];
            $status = DueCalendar::status($due->amount_minor, $held, $on);
            if (in_array($status, [DueStatus::Due, DueStatus::Overdue], true) || ($status === DueStatus::PartiallyPaid && $on !== null && $on <= $today)) {
                $dueNow += max(0, $due->amount_minor - $held);
            }
            $rows[] = [
                'id' => $due->id, 'name' => $due->name->get(), 'due_on' => $on, 'status' => $status->value,
                'amount_minor' => $due->amount_minor, 'remaining_minor' => max(0, $due->amount_minor - $held),
            ];
        }
        $pending = (int) PaymentProof::query()->where('order_id', $order->id)->where('status', ProofStatus::UnderReview->value)->sum('claimed_minor');
        $total = $agreement?->total_minor;

        return [
            'agreed_minor' => $total,
            'collected_minor' => $collected->minor,
            'remaining_minor' => $total === null ? null : max(0, $total - $collected->minor),
            'due_now_minor' => $dueNow,
            'pending_proofs_minor' => $pending,
            'dues' => $rows,
        ];
    }

    /**
     * Recent changes on the customer's orders, newest first (the workspace «آخر التحديثات» and notifications), derived
     * from the records themselves so they always match what the customer sees.
     *
     * @return list<array<string, mixed>>
     */
    public static function feed(Customer $customer, int $limit = 30): array
    {
        $events = [];
        foreach (PortalAccess::documents($customer)->with('order')->latest('sent_at')->limit($limit)->get() as $d) {
            $events[] = ['type' => 'document_sent', 'at' => $d->sent_at?->toIso8601String(), 'order' => $d->order->number, 'order_id' => $d->order_id, 'ref' => $d->number, 'kind' => $d->kind->value, 'link' => ['document', $d->id]];
            if ($d->decided_at !== null && in_array($d->status, [DocumentStatus::Approved, DocumentStatus::ChangesRequested], true)) {
                $events[] = ['type' => 'document_'.$d->status->value, 'at' => $d->decided_at->toIso8601String(), 'order' => $d->order->number, 'order_id' => $d->order_id, 'ref' => $d->number, 'kind' => $d->kind->value, 'link' => ['document', $d->id]];
            }
        }
        foreach (PortalAccess::receipts($customer)->with('order')->latest('issued_at')->limit($limit)->get() as $r) {
            $events[] = ['type' => 'receipt_issued', 'at' => $r->issued_at->toIso8601String(), 'order' => $r->order->number, 'order_id' => $r->order_id, 'ref' => $r->number, 'amount_minor' => $r->amount_minor, 'link' => ['order', $r->order_id]];
        }
        foreach (PortalAccess::proofs($customer)->with('order')->whereNotNull('decided_at')->latest('decided_at')->limit($limit)->get() as $p) {
            $events[] = ['type' => 'proof_'.$p->status->value, 'at' => $p->decided_at?->toIso8601String(), 'order' => $p->order->number, 'order_id' => $p->order_id, 'ref' => null, 'amount_minor' => $p->claimed_minor, 'link' => ['order', $p->order_id]];
        }
        foreach (PortalAccess::appointments($customer)->with('order')->latest('updated_at')->limit($limit)->get() as $a) {
            $events[] = ['type' => 'appointment_'.$a->status->value, 'at' => $a->updated_at?->toIso8601String(), 'order' => $a->order->number, 'order_id' => $a->order_id, 'ref' => null, 'starts_at' => self::local($a->starts_at), 'link' => ['order', $a->order_id]];
        }
        foreach (PortalAccess::cancellations($customer)->with('order')->latest('updated_at')->limit($limit)->get() as $c) {
            $events[] = ['type' => 'cancellation_'.$c->status->value, 'at' => ($c->decided_at ?? $c->created_at)->toIso8601String(), 'order' => $c->order->number, 'order_id' => $c->order_id, 'ref' => $c->number, 'link' => ['order', $c->order_id]];
        }
        $events = array_values(array_filter($events, fn (array $e) => $e['at'] !== null));
        usort($events, fn (array $a, array $b) => strcmp((string) $b['at'], (string) $a['at']));

        return array_slice($events, 0, $limit);
    }

    /**
     * @return array{name: string, size_bytes: int, url: string}
     */
    public static function file(Customer $customer, Attachment $attachment): array
    {
        return ['name' => $attachment->original_name, 'size_bytes' => $attachment->size_bytes, 'url' => PortalFiles::url($attachment, $customer)];
    }

    public static function acceptsProofs(Order $order): bool
    {
        return OrderAgreement::current($order) !== null
            && ! in_array($order->status, [CustomOrderStatus::Cancelled->value, CustomOrderStatus::Closed->value], true);
    }

    public static function cancellable(Order $order): bool
    {
        return ! in_array($order->status, [
            CustomOrderStatus::Cancelled->value, CustomOrderStatus::Closed->value, CustomOrderStatus::CancellationPending->value,
            ReadyOrderStatus::Delivered->value,
        ], true);
    }

    /**
     * @return list<array{name: string|null, status: string, started_on: string|null, completed_on: string|null}>
     */
    private static function timeline(Order $order): array
    {
        return array_values(OrderStage::query()->where('order_id', $order->id)->where('show_to_customer', true)->orderBy('position')->get()
            ->map(fn (OrderStage $s) => [
                'name' => $s->public_name->get() ?? $s->name->get(),
                'status' => $s->status->value,
                'started_on' => $s->started_at?->toDateString(),
                'completed_on' => $s->completed_at?->toDateString(),
            ])->all());
    }

    private static function currentStage(Order $order): ?string
    {
        $stage = OrderStage::query()->where('order_id', $order->id)->where('show_to_customer', true)
            ->whereNotNull('started_at')->whereNull('completed_at')->orderBy('position')->first();

        return $stage === null ? null : ($stage->public_name->get() ?? $stage->name->get());
    }

    private static function title(Order $order): ?string
    {
        if (! $order->isReady() && $order->need_summary !== null && $order->need_summary !== '') {
            return $order->need_summary;
        }
        $line = $order->activeLines()->orderBy('position')->first();

        return $line?->description;
    }

    private static function local(mixed $at): string
    {
        return CarbonImmutable::parse($at)->setTimezone(AppointmentBooking::timezone())->toIso8601String();
    }
}
