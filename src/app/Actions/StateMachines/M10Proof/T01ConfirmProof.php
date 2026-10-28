<?php

namespace App\Actions\StateMachines\M10Proof;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\PaymentStatus;
use App\Enums\ProofStatus;
use App\Enums\StateMachine;
use App\Finance\CollectionNotices;
use App\Finance\FinanceLock;
use App\Finance\FinanceWrite;
use App\Finance\OrderSchedule;
use App\Finance\PaymentPosting;
use App\Finance\PaymentRegistry;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentProof;
use App\Models\User;
use App\Money\Money;
use App\Support\StaleRecord;
use App\Workflow\AppointmentBooking;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * M-10-T01 (P-40, FIN-05, INT-04): the authorized person confirms the amount actually received. If the same transfer
 * was already recorded (a payment with the same trusted reference), the proof is linked to it — no second payment,
 * receipt or allocation. Otherwise one payment (M-11 T00+T01) and one receipt are created and linked. A received
 * amount different from the claim is recorded once and explained to the customer; both are never counted.
 */
final class T01ConfirmProof
{
    public function __construct(
        private readonly FinanceWrite $write,
        private readonly OrderSchedule $schedule,
        private readonly PaymentPosting $posting,
        private readonly TransitionRecorder $transitions,
        private readonly CollectionNotices $notices,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'max:20'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.AppointmentBooking::today()],
            'reference' => ['nullable', 'string', 'max:80'],
            'acknowledge_similar' => ['nullable', 'boolean'],
            // The person decided this proof is a payment already recorded without a matching reference.
            'link_payment_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, PaymentProof $proof, array $data, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): PaymentProof
    {
        /** @var array{amount: string, paid_on: string, reference?: string|null, acknowledge_similar?: bool|null, link_payment_id?: int|null} $data */
        $data = Validator::make($data, self::rules())->validate();
        try {
            $received = Money::fromInput(trim($data['amount']));
        } catch (\Throwable) {
            throw ValidationException::withMessages(['amount' => __('finance.validation.amount')]);
        }
        if ($received->isNegative() || $received->isZero()) {
            throw ValidationException::withMessages(['amount' => __('finance.validation.amount')]);
        }
        $payload = [...$data, 'proof_id' => $proof->id, 'lock_version' => $lockVersion];

        $this->write->run('proof.confirm', $actor, $idempotencyKey, $payload, function () use ($actor, $proof, $data, $received, $lockVersion, $source) {
            $order = FinanceLock::order($proof->order_id, StateMachine::M10, 'T01');
            Access::authorize($actor, Permission::P40, $order);
            $proof = PaymentProof::query()->lockForUpdate()->findOrFail($proof->id);
            if ($lockVersion !== null && $proof->lock_version !== $lockVersion) {
                throw new StaleRecord($proof->lock_version);
            }
            if ($proof->status !== ProofStatus::UnderReview) {
                throw new InvalidTransition(StateMachine::M10, 'T01', 'not_from_'.$proof->status->value);
            }
            $head = FinanceLock::head($order);
            $this->schedule->sync($order, $head, $actor, $source);

            $reference = ($data['reference'] ?? null) ?: $proof->reference;
            $referenceKey = $proof->method->referenceKey($reference);
            $existing = isset($data['link_payment_id'])
                ? Payment::query()->find((int) $data['link_payment_id']) ?? throw new InvalidTransition(StateMachine::M10, 'T01', 'reference_conflict')
                : PaymentRegistry::byReference($referenceKey);

            if ($existing !== null) {
                // The same transfer is already money (INT-04): link the proof, nothing else.
                if ($existing->customer_id !== $order->customer_id || ! $existing->status->isMoney()) {
                    throw new InvalidTransition(StateMachine::M10, 'T01', 'reference_conflict');
                }
                $payment = $existing;
                $linked = true;
            } else {
                if (! ($data['acknowledge_similar'] ?? false) && PaymentRegistry::similar($order->customer_id, $received, $data['paid_on'])->isNotEmpty()) {
                    throw new InvalidTransition(StateMachine::M10, 'T01', 'similar_payment');
                }
                $payment = PaymentRegistry::create([
                    'customer_id' => $order->customer_id,
                    'order_id' => $order->id,
                    'amount_minor' => $received->minor,
                    'method' => $proof->method,
                    'paid_on' => $data['paid_on'],
                    'reference' => $reference,
                    'reference_key' => $referenceKey,
                    'origin' => Payment::ORIGIN_PROOF,
                    'status' => PaymentStatus::Recorded,
                    'recorded_by' => $actor->id,
                ]);
                $payment->setRelation('order', $order);
                $this->transitions->record(StateMachine::M11, 'T00', $payment, null, PaymentStatus::Recorded, $actor, $source, null, ['proof' => $proof->id]);
                $this->posting->confirm($payment, 'T01', [$order->id => $received], [$order->id => $order], [$order->id => $head], $actor, $source, ['proof' => $proof->id]);
                $linked = false;
            }

            $proof->forceFill([
                'status' => ProofStatus::Confirmed, 'payment_id' => $payment->id, 'decided_by' => $actor->id, 'decided_at' => now(),
                'lock_version' => $proof->lock_version + 1,
            ])->save();
            $this->transitions->record(StateMachine::M10, 'T01', $proof, ProofStatus::UnderReview, ProofStatus::Confirmed, $actor, $source, null, [
                'payment' => $payment->id, 'linked_existing' => $linked, 'claimed_minor' => $proof->claimed_minor, 'received_minor' => $payment->amount_minor,
            ]);
            if ($payment->amount_minor !== $proof->claimed_minor) {
                $this->notices->proofAmountDiffers($proof, $order, $payment->amount());
            }
            FinanceLock::touch($head);
            Audit::record(AuditAction::ProofConfirmed, $order, $actor, after: [
                'proof' => $proof->id, 'payment' => $payment->id, 'linked_existing' => $linked,
                'claimed_minor' => $proof->claimed_minor, 'received_minor' => $payment->amount_minor,
            ], source: $source, label: $order->number);

            return ['proof_id' => $proof->id, 'payment_id' => $payment->id];
        });

        return $proof->refresh();
    }
}
