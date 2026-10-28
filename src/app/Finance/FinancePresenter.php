<?php

namespace App\Finance;

use App\Access\Access;
use App\Access\Permission;
use App\Agreements\OrderAgreement;
use App\Enums\DueStatus;
use App\Enums\ProofStatus;
use App\Files\AttachmentService;
use App\Models\Attachment;
use App\Models\Finance\MoneyEntry;
use App\Models\Finance\OrderDue;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentProof;
use App\Models\FinanceSettings;
use App\Models\Order;
use App\Models\User;
use App\Orders\OrderAccess;
use App\Workflow\AppointmentBooking;

/**
 * The order's money for the screens (FinOrder, FinOrderPaid, FinSales, FinOrderMobile, FinEnglish, FinDark): the four
 * separate figures of FIN-01 (agreed, net collected, remaining, due now), the schedule with derived statuses, the
 * payments with their receipt and allocation, the customer proofs and the stage collection checks. The caller checks
 * P-16. Amounts need P-37 over the order: without it every `*_minor` key is absent (render «hidden», never 0) —
 * statuses and dates stay, so a seller without P-37 still sees whether the first payment arrived.
 */
final class FinancePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function forOrder(Order $order, User $viewer): array
    {
        $amounts = OrderAccess::canSeeFinancials($viewer, $order);
        $agreement = OrderAgreement::current($order);
        $value = $agreement?->total();
        $collected = MoneyLedger::collected($order->id);
        $credit = MoneyLedger::credit($order->id);

        $dues = Allocator::openDues($order->id);
        $allocated = MoneyLedger::allocatedByDue($order->id);
        $dates = DueCalendar::dates($order, $dues);
        $dueNow = 0;
        $scheduled = 0;
        $dueRows = [];
        foreach ($dues as $due) {
            $held = $allocated[$due->id] ?? 0;
            $status = DueCalendar::status($due->amount_minor, $held, $dates[$due->id]);
            if (in_array($status, [DueStatus::Due, DueStatus::Overdue], true) || ($status === DueStatus::PartiallyPaid && $dates[$due->id] !== null && $dates[$due->id] <= AppointmentBooking::today())) {
                $dueNow += max(0, $due->amount_minor - $held);
            }
            $scheduled += $due->amount_minor;
            $dueRows[] = self::due($due, $held, $dates[$due->id], $status, $amounts);
        }

        $remaining = $value?->minus($collected);
        $summary = [
            'agreed_minor' => $value?->minor,
            'collected_minor' => $collected->minor,
            'remaining_minor' => $remaining === null ? null : max(0, $remaining->minor),
            'excess_minor' => $remaining === null ? $credit->minor : max(0, -$remaining->minor),
            'due_now_minor' => $dueNow,
            'credit_minor' => $credit->minor,
            'unscheduled_minor' => $value === null ? null : max(0, $value->minor - $scheduled),
            'settlement_needed_minor' => $agreement?->settlement_needed_minor,
        ];

        $payload = [
            'amounts_visible' => $amounts,
            'lock_version' => FinanceLock::versionOf($order),
            'has_agreement' => $agreement !== null,
            'agreement_version' => $agreement?->version,
            'summary' => $amounts ? $summary : null,
            'dues' => $dueRows,
            'payments' => self::payments($order, $viewer, $amounts),
            'proofs' => self::proofs($order, $viewer, $amounts),
            'conditions' => array_map(fn (array $c) => $amounts ? $c : array_diff_key($c, ['threshold_minor' => true]), CollectionConditions::overview($order)),
            'shortfalls' => CollectionConditions::shortfalls($order),
            'templates' => array_keys(OrderSchedule::TEMPLATES),
            'cash_proof_required' => FinanceSettings::current()->cashProofRequired(),
            'can' => [
                'record' => Access::can($viewer, Permission::P39, $order),
                'review' => Access::can($viewer, Permission::P40, $order),
                'schedule' => Access::can($viewer, Permission::P38, $order) && $agreement !== null,
                'allocate' => Access::can($viewer, Permission::P38, $order),
            ],
            'customer_orders' => self::customerOrders($order, $viewer),
        ];

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private static function due(OrderDue $due, int $held, ?string $dueOn, DueStatus $status, bool $amounts): array
    {
        $row = [
            'id' => $due->id,
            'reference' => $due->reference,
            'name' => $due->name->get(),
            'name_i18n' => $due->name->toArray(),
            'basis' => $due->basis->value,
            'event' => $due->due_event->value,
            'due_date' => $due->due_date?->toDateString(),
            'due_on' => $dueOn,
            'status' => $status->value,
        ];

        return $amounts ? $row + [
            'basis_value' => $due->basis_value,
            'amount_minor' => $due->amount_minor,
            'allocated_minor' => $held,
            'remaining_minor' => max(0, $due->amount_minor - $held),
        ] : $row;
    }

    /**
     * Payments whose money sits (or sat) on this order, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private static function payments(Order $order, User $viewer, bool $amounts): array
    {
        $ids = MoneyEntry::query()->where('order_id', $order->id)->distinct()->pluck('payment_id')->all();
        $payments = Payment::query()->with(['receipt', 'recorder', 'attachments'])
            ->where(fn ($q) => $q->whereIn('id', $ids)->orWhere('order_id', $order->id))
            ->orderByDesc('paid_on')->orderByDesc('id')->limit(100)->get();

        return array_values($payments->map(function (Payment $p) use ($order, $viewer, $amounts) {
            $places = MoneyLedger::placesOf($p->id, $order->id);
            $row = [
                'id' => $p->id,
                'receipt_number' => $p->receipt?->number,
                'method' => $p->method->value,
                'paid_on' => $p->paid_on->toDateString(),
                'reference' => $p->reference,
                'origin' => $p->origin,
                'status' => $p->status->value,
                'recorded_by' => $p->recorder?->name,
                'created_at' => $p->created_at->toIso8601String(),
                'lock_version' => $p->lock_version,
                'evidence' => $amounts ? self::files(array_values($p->attachments->all()), $viewer) : [],
            ];

            return $amounts ? $row + [
                'amount_minor' => $p->amount_minor,
                'share_minor' => array_sum($places),
                'credit_minor' => $places[0] ?? 0,
                'allocations' => array_map(fn (int $dueId, int $minor) => ['due_id' => $dueId, 'amount_minor' => $minor],
                    array_keys(array_diff_key($places, [0 => true])), array_values(array_diff_key($places, [0 => true]))),
            ] : $row;
        })->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function proofs(Order $order, User $viewer, bool $amounts): array
    {
        $proofs = PaymentProof::query()->with(['attachments', 'payment.receipt', 'decider'])->where('order_id', $order->id)
            ->orderByDesc('id')->limit(50)->get();
        $replaced = $proofs->pluck('replaces_id')->filter()->all();

        return array_values($proofs->map(function (PaymentProof $p) use ($viewer, $amounts, $replaced) {
            $match = $p->status === ProofStatus::UnderReview ? PaymentRegistry::byReference($p->reference_key) : null;
            $row = [
                'id' => $p->id,
                'status' => $p->status->value,
                'replaced' => in_array($p->id, $replaced, true),
                'replaces_id' => $p->replaces_id,
                'method' => $p->method->value,
                'paid_on' => $p->paid_on->toDateString(),
                'reference' => $p->reference,
                'submitted_at' => $p->created_at->toIso8601String(),
                'correction_reason' => $p->correction_reason,
                'decided_by' => $p->decider?->name,
                'decided_at' => $p->decided_at?->toIso8601String(),
                'receipt_number' => $p->payment?->receipt?->number,
                'matching_receipt' => $match?->receipt?->number,
                'lock_version' => $p->lock_version,
                'files' => $amounts ? self::files(array_values($p->attachments->all()), $viewer) : [],
            ];

            return $amounts ? $row + ['claimed_minor' => $p->claimed_minor, 'similar' => $match === null && PaymentRegistry::similar($p->customer_id, $p->claimed(), $p->paid_on->toDateString())->isNotEmpty()] : $row;
        })->all());
    }

    /**
     * Other open orders of the same customer the viewer may move money to (split / transfer).
     *
     * @return list<array{id: int, number: string}>
     */
    private static function customerOrders(Order $order, User $viewer): array
    {
        if (! Access::can($viewer, Permission::P38, $order) && ! Access::can($viewer, Permission::P39, $order)) {
            return [];
        }

        return array_values(Order::query()->where('customer_id', $order->customer_id)->whereKeyNot($order->id)
            ->where('kind', $order->kind->value)->orderByDesc('id')->limit(20)->get()
            ->filter(fn (Order $o) => Access::can($viewer, Permission::P38, $o) || Access::can($viewer, Permission::P39, $o))
            ->map(fn (Order $o) => ['id' => $o->id, 'number' => $o->number])->all());
    }

    /**
     * @param  list<Attachment>  $attachments
     * @return list<array{uuid: string, name: string, url: string|null}>
     */
    private static function files(array $attachments, User $viewer): array
    {
        $files = app(AttachmentService::class);

        return array_map(fn (Attachment $a) => [
            'uuid' => $a->uuid,
            'name' => $a->original_name,
            'url' => $files->canDownload($viewer, $a) ? $files->downloadUrl($a, $viewer) : null,
        ], $attachments);
    }
}
