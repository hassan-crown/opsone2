<?php

namespace App\Finance\Settlement;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\DisputeStatus;
use App\Enums\MembershipStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Files\AttachmentService;
use App\Finance\MoneyLedger;
use App\Models\Attachment;
use App\Models\Finance\ExternalDocument;
use App\Models\Finance\FinancialDispute;
use App\Models\Finance\MoneyEntry;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentCorrection;
use App\Models\Finance\Refund;
use App\Models\Membership;
use App\Models\Order;
use App\Models\User;
use App\Orders\OrderAccess;

/**
 * The order's settlement for the screens (FinRefund, FinRefundExec, FinCorrect, FinDispute, FinDisputeResolve,
 * FinInvoiceLink, the finance tab sections): refundable limits per payment, refunds, correction vouchers, disputes and
 * external documents. The caller checks P-16. Amounts need P-37 over the order: without it every `*_minor` key and the
 * money files are absent (render «hidden», never 0). Document files follow P-44.
 */
final class SettlementPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function forOrder(Order $order, User $viewer): array
    {
        $amounts = OrderAccess::canSeeFinancials($viewer, $order);
        $can = [
            'request_refund' => Access::can($viewer, Permission::P42, $order),
            'execute_refund' => Access::can($viewer, Permission::P43, $order),
            'correct' => Access::can($viewer, Permission::P41, $order),
            'dispute' => Access::can($viewer, Permission::P85, $order),
            'documents' => Access::can($viewer, Permission::P44, $order),
        ];
        $customer = $order->customer;
        $missing = array_keys(array_filter([
            'vat_number' => blank($customer->vat_number),
            'commercial_registration' => blank($customer->commercial_registration),
            'email' => blank($customer->email),
        ]));

        return [
            'amounts_visible' => $amounts,
            'payments' => self::payments($order, $amounts),
            'refunds' => self::refunds($order, $viewer, $amounts, $can),
            'corrections' => self::corrections($order, $amounts),
            'disputes' => self::disputes($order, $viewer, $amounts, $can),
            'documents' => self::documents($order, $viewer, $amounts, $can),
            'open_disputes' => FinancialDispute::query()->where('order_id', $order->id)->where('status', '!=', DisputeStatus::Resolved->value)->count(),
            'customer_tax' => [
                'name' => $customer->name,
                'kind' => $customer->kind->value,
                'vat_number' => $customer->vat_number,
                'commercial_registration' => $customer->commercial_registration,
                'missing' => $missing,
            ],
            'members' => $can['dispute'] ? self::members($order) : [],
            'can' => $can,
        ];
    }

    /**
     * Confirmed payments whose money sits on this order, with their refundable limit.
     *
     * @return list<array<string, mixed>>
     */
    private static function payments(Order $order, bool $amounts): array
    {
        $ids = MoneyEntry::query()->where('order_id', $order->id)->distinct()->pluck('payment_id')->all();
        $payments = Payment::query()->with('receipt')->where(fn ($q) => $q->whereIn('id', $ids)->orWhere('order_id', $order->id))
            ->whereIn('status', [PaymentStatus::Confirmed->value, PaymentStatus::Corrected->value])
            ->orderByDesc('paid_on')->orderByDesc('id')->limit(100)->get();

        return array_values($payments->map(function (Payment $p) use ($order, $amounts) {
            $row = [
                'id' => $p->id,
                'receipt_number' => $p->receipt?->number,
                'method' => $p->method->value,
                'paid_on' => $p->paid_on->toDateString(),
                'reference' => $p->reference,
                'status' => $p->status->value,
                'lock_version' => $p->lock_version,
                'refundable' => $p->status === PaymentStatus::Confirmed && RefundLimits::refundable($p, $order->id)->minor > 0,
                'correctable' => $p->status === PaymentStatus::Confirmed
                    && Refund::query()->where('payment_id', $p->id)->whereNotIn('status', [RefundStatus::Failed->value, RefundStatus::Cancelled->value])->doesntExist(),
                'split' => count(MoneyLedger::sharesOf($p->id)) > 1,
            ];

            return $amounts ? $row + [
                'amount_minor' => $p->amount_minor,
                'placed_minor' => RefundLimits::placed($p->id, $order->id)->minor,
                'reserved_minor' => RefundLimits::reserved($p->id, $order->id)->minor,
                'refunded_minor' => RefundLimits::executed($p->id, $order->id)->minor,
                'refundable_minor' => RefundLimits::refundable($p, $order->id)->minor,
            ] : $row;
        })->all());
    }

    /**
     * @param  array<string, bool>  $can
     * @return list<array<string, mixed>>
     */
    private static function refunds(Order $order, User $viewer, bool $amounts, array $can): array
    {
        $refunds = Refund::query()->with(['payment.receipt', 'requester', 'executor', 'attachments'])->where('order_id', $order->id)
            ->orderByDesc('id')->limit(100)->get();

        return array_values($refunds->map(function (Refund $r) use ($viewer, $amounts, $can) {
            $row = [
                'id' => $r->id,
                'number' => $r->number,
                'payment_id' => $r->payment_id,
                'receipt_number' => $r->payment->receipt?->number,
                'status' => $r->status->value,
                'method' => $r->method->value,
                'reason' => $r->reason,
                'attempt' => $r->attempt,
                'reference' => $r->reference,
                'failure_reason' => $r->failure_reason,
                'cancel_reason' => $r->cancel_reason,
                'requested_by' => $r->requester?->name,
                'requested_at' => $r->created_at->toIso8601String(),
                'executed_by' => $r->executor?->name,
                'executed_at' => $r->executed_at?->toIso8601String(),
                'lock_version' => $r->lock_version,
                'files' => $amounts ? self::files(array_values($r->attachments->all()), $viewer) : [],
                'can' => [
                    'start' => $can['execute_refund'] && $r->status === RefundStatus::Requested,
                    'confirm' => $can['execute_refund'] && in_array($r->status, [RefundStatus::Requested, RefundStatus::InProgress], true),
                    'fail' => $can['execute_refund'] && in_array($r->status, [RefundStatus::InProgress, RefundStatus::Unresolved], true),
                    'retry' => $can['execute_refund'] && $r->status === RefundStatus::Failed,
                    'cancel' => $can['request_refund'] && $r->status === RefundStatus::Requested,
                ],
            ];

            return $amounts ? $row + ['amount_minor' => $r->amount_minor] : $row;
        })->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function corrections(Order $order, bool $amounts): array
    {
        $paymentIds = MoneyEntry::query()->where('order_id', $order->id)->distinct()->pluck('payment_id')->all();
        $corrections = PaymentCorrection::query()->with(['payment.receipt', 'replacement.receipt', 'corrector'])
            ->where(fn ($q) => $q->where('order_id', $order->id)->orWhereIn('payment_id', $paymentIds))
            ->orderByDesc('id')->limit(50)->get();

        return array_values($corrections->map(function (PaymentCorrection $c) use ($amounts) {
            $row = [
                'id' => $c->id,
                'number' => $c->number,
                'kind' => $c->kind->value,
                'reason' => $c->reason,
                'receipt_number' => $c->payment->receipt?->number,
                'replacement_receipt' => $c->replacement?->receipt?->number,
                'corrected_by' => $c->corrector?->name,
                'created_at' => $c->created_at->toIso8601String(),
            ];

            return $amounts ? $row + [
                'before_minor' => (int) ($c->before['amount_minor'] ?? 0),
                'after_minor' => $c->after === null ? 0 : (int) ($c->after['amount_minor'] ?? 0),
            ] : $row;
        })->all());
    }

    /**
     * @param  array<string, bool>  $can
     * @return list<array<string, mixed>>
     */
    private static function disputes(Order $order, User $viewer, bool $amounts, array $can): array
    {
        $disputes = FinancialDispute::query()->with(['payment.receipt', 'refund', 'due', 'responsible', 'resolver', 'attachments'])
            ->where('order_id', $order->id)->orderByRaw("CASE WHEN status = 'resolved' THEN 1 ELSE 0 END")->orderByDesc('id')->limit(50)->get();

        return array_values($disputes->map(function (FinancialDispute $d) use ($viewer, $amounts, $can) {
            $row = [
                'id' => $d->id,
                'subject' => $d->subject->value,
                'reason' => $d->reason,
                'status' => $d->status->value,
                'receipt_number' => $d->payment?->receipt?->number,
                'refund_number' => $d->refund?->number,
                'due_name' => $d->due?->name->get(),
                'responsible' => ['id' => $d->responsible_id, 'name' => $d->responsible->name],
                'external_party' => $d->external_party,
                'outcome' => $d->outcome?->value,
                'resolution' => $d->resolution,
                'resolved_by' => $d->resolver?->name,
                'resolved_at' => $d->resolved_at?->toIso8601String(),
                'opened_at' => $d->created_at->toIso8601String(),
                'age_days' => (int) $d->created_at->diffInDays(now()),
                'lock_version' => $d->lock_version,
                'files' => $amounts ? self::files(array_values($d->attachments->all()), $viewer) : [],
                'can' => [
                    'await' => $can['dispute'] && $d->status === DisputeStatus::Open,
                    'resume' => $can['dispute'] && $d->status === DisputeStatus::AwaitingExternal,
                    'resolve' => $can['dispute'] && $d->status->isOpen(),
                ],
            ];

            return $amounts ? $row + ['amount_minor' => $d->amount_minor, 'amount_note' => $d->amount_note] : $row;
        })->all());
    }

    /**
     * @param  array<string, bool>  $can
     * @return list<array<string, mixed>>
     */
    private static function documents(Order $order, User $viewer, bool $amounts, array $can): array
    {
        $documents = ExternalDocument::query()->with(['payment.receipt', 'refund', 'reviewer', 'attachments'])
            ->where('order_id', $order->id)->orderByDesc('id')->limit(100)->get();

        return array_values($documents->map(fn (ExternalDocument $d) => self::document($d, $viewer, $amounts, $can['documents']))->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function document(ExternalDocument $d, User $viewer, bool $amounts, bool $manage): array
    {
        $row = [
            'id' => $d->id,
            'order_id' => $d->order_id,
            'kind' => $d->kind->value,
            'event' => $d->event->value,
            'payment_id' => $d->payment_id,
            'receipt_number' => $d->payment?->receipt?->number,
            'refund_number' => $d->refund?->number,
            'source' => $d->source,
            'number' => $d->number,
            'issued_on' => $d->issued_on?->toDateString(),
            'currency' => $d->currency,
            'note' => $d->note,
            'status' => $d->status->value,
            'correction_reason' => $d->correction_reason,
            'reviewed_by' => $d->reviewer?->name,
            'lock_version' => $d->lock_version,
            'files' => $manage ? self::files(array_values($d->attachments->sortByDesc('id')->all()), $viewer) : [],
            'can' => [
                'attach' => $manage && in_array($d->status->value, ['requested', 'needs_correction'], true),
                'review' => $manage && $d->status->value === 'attached',
            ],
        ];

        return $amounts ? $row + ['amount_minor' => $d->amount_minor] : $row;
    }

    /**
     * Active members who may follow the order (the dispute's responsible person).
     *
     * @return list<array{id: int, name: string}>
     */
    private static function members(Order $order): array
    {
        $ids = Membership::query()->where('status', MembershipStatus::Active)->pluck('user_id')->all();

        return array_values(User::query()->whereIn('id', $ids)->where('is_support', false)->orderBy('name')->limit(100)->get()
            ->filter(fn (User $u) => Access::can($u, Permission::P16, $order))
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->all());
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
