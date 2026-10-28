<?php

namespace App\Actions\StateMachines\M11Payment;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\CorrectionKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\StateMachine;
use App\Files\AttachmentService;
use App\Finance\Allocator;
use App\Finance\CollectionConditions;
use App\Finance\FinanceLock;
use App\Finance\FinanceWrite;
use App\Finance\MoneyLedger;
use App\Finance\PaymentPosting;
use App\Finance\PaymentRegistry;
use App\Finance\Settlement\SettlementInput;
use App\Finance\Settlement\VoucherNumbers;
use App\Models\Finance\MoneyEntry;
use App\Models\Finance\MoneyJournal;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentCorrection;
use App\Models\Finance\Refund;
use App\Models\Order;
use App\Models\User;
use App\Workflow\AppointmentBooking;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * M-11-T07 (P-41, FIN-08, FinCorrect): the documented correction of a confirmed payment recorded in error. Every
 * effect of the payment is reversed (`MoneyLedger::reversePayment`, newest first) — no row is edited or deleted — and
 * the payment becomes `corrected`; its receipt stays as issued and a correction voucher CR-YYYY-000123 is added. An
 * amendment re-enters the right figures as a new payment with its own receipt (the reversal + re-entry pair). The
 * allocation and stage checks are recomputed; a completed stage is never un-completed (shortfalls are listed). This
 * is a record correction, never a bank refund. The P-41 grant is the approval (no second person is imposed, P05).
 * Refused without effect: `payment_has_refunds` (a refund holds or took part of it), `split_payment` (an amendment
 * of money spread over several orders: void it and record again), `nothing_changed`.
 */
final class T07CorrectPayment
{
    public function __construct(
        private readonly FinanceWrite $write,
        private readonly MoneyLedger $ledger,
        private readonly Allocator $allocator,
        private readonly CollectionConditions $conditions,
        private readonly PaymentPosting $posting,
        private readonly VoucherNumbers $numbers,
        private readonly AttachmentService $files,
        private readonly TransitionRecorder $transitions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'kind' => ['required', 'in:void,amend'],
            'reason' => ['required', 'string', 'max:1000'],
            'amount' => ['nullable', 'required_if:kind,amend', 'string', 'max:20'],
            'method' => ['nullable', 'required_if:kind,amend', 'in:'.implode(',', PaymentMethod::manual())],
            'paid_on' => ['nullable', 'required_if:kind,amend', 'date_format:Y-m-d', 'before_or_equal:'.AppointmentBooking::today()],
            'reference' => ['nullable', 'string', 'max:80'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, Order $order, Payment $payment, array $data, ?UploadedFile $evidence, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): PaymentCorrection
    {
        /** @var array{kind: string, reason: string, amount?: string|null, method?: string|null, paid_on?: string|null, reference?: string|null} $data */
        $data = Validator::make($data, self::rules())->validate();
        $kind = CorrectionKind::from($data['kind']);
        $reason = SettlementInput::reason($data['reason'], 'reason', 1000);
        $amount = $kind === CorrectionKind::Amend ? SettlementInput::money((string) ($data['amount'] ?? ''), 'amount') : null;
        $method = $kind === CorrectionKind::Amend ? PaymentMethod::from((string) ($data['method'] ?? '')) : null;
        $paidOn = (string) ($data['paid_on'] ?? '');
        if ($method !== null && in_array($method, [PaymentMethod::Transfer, PaymentMethod::Cheque], true) && trim((string) ($data['reference'] ?? '')) === '') {
            throw ValidationException::withMessages(['reference' => __('refunds.validation.reference')]);
        }
        $payload = [...$data, 'order' => $order->id, 'payment' => $payment->id, 'lock_version' => $lockVersion,
            'evidence' => $evidence === null ? null : hash_file('sha256', (string) $evidence->getRealPath())];

        $result = $this->write->run('payment.correct', $actor, $idempotencyKey, $payload, function () use ($actor, $order, $payment, $data, $kind, $reason, $amount, $method, $paidOn, $evidence, $lockVersion, $source) {
            $orderIds = MoneyEntry::query()->where('payment_id', $payment->id)->distinct()->pluck('order_id')->map(fn ($id) => (int) $id)->all();
            $orders = FinanceLock::orders(array_values([$order->id, $payment->order_id, ...$orderIds]), StateMachine::M11, 'T07');
            $heads = [];
            foreach ($orders as $id => $locked) {
                Access::authorize($actor, Permission::P41, $locked);
                $heads[$id] = FinanceLock::head($locked);
            }
            FinanceLock::checkVersion($heads[$order->id], $lockVersion);

            $fresh = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if (! in_array($fresh->status, PaymentStatus::transitions()['T07']['from'], true)) {
                throw new InvalidTransition(StateMachine::M11, 'T07', 'not_from_'.$fresh->status->value);
            }
            if (Refund::query()->where('payment_id', $fresh->id)->whereNotIn('status', [RefundStatus::Failed->value, RefundStatus::Cancelled->value])->exists()) {
                throw new InvalidTransition(StateMachine::M11, 'T07', 'payment_has_refunds');
            }
            $shares = MoneyLedger::sharesOf($fresh->id);
            if ($kind === CorrectionKind::Amend) {
                if (count($shares) > 1) {
                    throw new InvalidTransition(StateMachine::M11, 'T07', 'split_payment');
                }
                $same = $amount?->minor === $fresh->amount_minor && $method === $fresh->method
                    && $paidOn === $fresh->paid_on->toDateString() && trim((string) ($data['reference'] ?? '')) === (string) $fresh->reference;
                if ($same) {
                    throw new InvalidTransition(StateMachine::M11, 'T07', 'nothing_changed');
                }
            }

            $before = [
                'receipt' => $fresh->receipt?->number, 'amount_minor' => $fresh->amount_minor, 'method' => $fresh->method->value,
                'paid_on' => $fresh->paid_on->toDateString(), 'reference' => $fresh->reference, 'shares' => $shares,
            ];
            $journals = $this->ledger->reversePayment($fresh, $reason, $actor, $source);
            $fresh->forceFill(['status' => PaymentStatus::Corrected, 'lock_version' => $fresh->lock_version + 1])->save();
            $number = $this->numbers->correction();
            $this->transitions->record(StateMachine::M11, 'T07', $fresh, PaymentStatus::Confirmed, PaymentStatus::Corrected, $actor, $source, $reason, ['kind' => $kind->value, 'number' => $number]);

            $replacement = null;
            $after = null;
            if ($amount !== null) {
                $target = array_key_first($shares) ?? $fresh->order_id;
                $reference = trim((string) ($data['reference'] ?? '')) ?: null;
                $key = $method->referenceKey($reference);
                // The corrected payment keeps its trusted reference: a re-entry of the same transfer is not a duplicate.
                if ($key !== null && $key === $fresh->reference_key) {
                    $key = null;
                } else {
                    PaymentRegistry::ensureNewReference($key, 'T07');
                }
                $replacement = PaymentRegistry::create([
                    'customer_id' => $fresh->customer_id, 'order_id' => $target, 'amount_minor' => $amount->minor,
                    'method' => $method, 'paid_on' => $paidOn, 'reference' => $reference, 'reference_key' => $key,
                    'origin' => Payment::ORIGIN_STAFF, 'status' => PaymentStatus::Recorded, 'note' => $number,
                    'recorded_by' => $actor->id,
                ], 'T07');
                $replacement->setRelation('order', $orders[$target]);
                $this->transitions->record(StateMachine::M11, 'T00', $replacement, null, PaymentStatus::Recorded, $actor, $source, null, ['corrects' => $fresh->id]);
                if ($evidence !== null) {
                    $this->files->store($actor, $replacement, $evidence, 'payment_evidence');
                }
                $receipt = $this->posting->confirm($replacement, 'T01', [$target => $amount], $orders, $heads, $actor, $source, ['corrects' => $fresh->id]);
                $after = ['payment' => $replacement->id, 'receipt' => $receipt->number, 'amount_minor' => $amount->minor,
                    'method' => $method->value, 'paid_on' => $paidOn, 'reference' => $reference];
            }

            foreach ($orders as $id => $locked) {
                $this->allocator->settle($locked, $actor, $source);
                $this->conditions->evaluate($locked, $fresh->id, $actor);
                FinanceLock::touch($heads[$id]);
            }

            $correction = PaymentCorrection::query()->create([
                'number' => $number, 'payment_id' => $fresh->id, 'order_id' => $order->id, 'kind' => $kind, 'reason' => $reason,
                'replacement_payment_id' => $replacement?->id,
                'reversed_journals' => array_map(fn (MoneyJournal $j) => $j->id, $journals),
                'before' => $before, 'after' => $after, 'corrected_by' => $actor->id,
            ]);
            Audit::record(AuditAction::PaymentCorrected, $order, $actor, before: $before, after: ['number' => $number, 'kind' => $kind->value] + ($after ?? []),
                reason: $reason, source: $source, label: $order->number);

            return ['correction_id' => $correction->id, 'difference_minor' => ($amount === null ? 0 : $amount->minor) - $fresh->amount_minor];
        });

        return PaymentCorrection::query()->findOrFail((int) $result['result']['correction_id']);
    }
}
