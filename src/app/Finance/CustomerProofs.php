<?php

namespace App\Finance;

use App\Access\Exceptions\InvalidTransition;
use App\Agreements\CustomerActor;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\ProofStatus;
use App\Enums\StateMachine;
use App\Files\AttachmentService;
use App\Models\Finance\PaymentProof;
use App\Models\Order;
use App\Money\Money;
use App\Support\LockingTransaction;
use App\Access\TransitionRecorder;
use App\Workflow\AppointmentBooking;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * What the customer does from their own account (P-68, FIN-05): upload a transfer proof (M-10-T00), upload a
 * replacement after a correction request (T03, the previous one kept) or withdraw an unconfirmed proof (T04). For
 * the customer portal (P8), which authenticates the customer and checks the order first; this re-checks that the
 * order is theirs. A proof is never money: nothing here touches the ledger.
 */
final class CustomerProofs
{
    public const SOURCE = 'customer';

    public function __construct(
        private readonly AttachmentService $files,
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
            'method' => ['required', 'in:transfer,cheque'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.AppointmentBooking::today()],
            'reference' => ['nullable', 'string', 'max:80'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(CustomerActor $customer, Order $order, array $data, UploadedFile $file): PaymentProof
    {
        return $this->create($customer, $order, $data, $file, null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function resubmit(CustomerActor $customer, PaymentProof $previous, array $data, UploadedFile $file): PaymentProof
    {
        return $this->create($customer, $previous->order, $data, $file, $previous);
    }

    public function withdraw(CustomerActor $customer, PaymentProof $proof): PaymentProof
    {
        return LockingTransaction::run(function () use ($customer, $proof) {
            $order = FinanceLock::order($proof->order_id, StateMachine::M10, 'T04');
            self::ensureOwn($customer, $order);
            $proof = PaymentProof::query()->lockForUpdate()->findOrFail($proof->id);
            $from = $proof->status;
            if (! in_array($from, ProofStatus::transitions()['T04']['from'], true) || $proof->payment_id !== null) {
                throw new InvalidTransition(StateMachine::M10, 'T04', 'not_from_'.$from->value);
            }
            $proof->forceFill(['status' => ProofStatus::Cancelled, 'decided_at' => now(), 'lock_version' => $proof->lock_version + 1])->save();
            $this->transitions->record(StateMachine::M10, 'T04', $proof, $from, ProofStatus::Cancelled, null, self::SOURCE, 'withdrawn_by_customer');
            Audit::record(AuditAction::ProofCancelled, $order, null, after: ['proof' => $proof->id, 'by' => 'customer'], source: self::SOURCE, label: $order->number);

            return $proof;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function create(CustomerActor $customer, Order $order, array $data, UploadedFile $file, ?PaymentProof $previous): PaymentProof
    {
        /** @var array{amount: string, method: string, paid_on: string, reference?: string|null} $data */
        $data = Validator::make($data, self::rules())->validate();
        try {
            $claimed = Money::fromInput(trim($data['amount']));
        } catch (\Throwable) {
            throw ValidationException::withMessages(['amount' => __('finance.validation.amount')]);
        }
        if ($claimed->isNegative() || $claimed->isZero()) {
            throw ValidationException::withMessages(['amount' => __('finance.validation.amount')]);
        }
        $method = PaymentMethod::from($data['method']);

        return LockingTransaction::run(function () use ($customer, $order, $data, $file, $previous, $claimed, $method) {
            $code = $previous === null ? 'T00' : 'T03';
            $order = FinanceLock::order($order->id, StateMachine::M10, $code);
            self::ensureOwn($customer, $order);
            if ($previous !== null) {
                $previous = PaymentProof::query()->lockForUpdate()->findOrFail($previous->id);
                $replaced = PaymentProof::query()->where('replaces_id', $previous->id)->exists();
                if ($previous->status !== ProofStatus::NeedsCorrection || $replaced || $previous->order_id !== $order->id) {
                    throw new InvalidTransition(StateMachine::M10, 'T03', 'not_from_'.$previous->status->value);
                }
            }

            $proof = PaymentProof::query()->create([
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'claimed_minor' => $claimed->minor,
                'method' => $method,
                'paid_on' => $data['paid_on'],
                'reference' => $data['reference'] ?? null,
                'reference_key' => $method->referenceKey($data['reference'] ?? null),
                'status' => ProofStatus::UnderReview,
                'replaces_id' => $previous?->id,
                'submitted_mobile' => $customer->mobile,
            ]);
            $proof->setRelation('order', $order);
            $this->transitions->record(StateMachine::M10, $code, $proof, $previous === null ? null : ProofStatus::NeedsCorrection, ProofStatus::UnderReview, null, self::SOURCE, null,
                $previous === null ? [] : ['replaces' => $previous->id]);
            $this->files->storeFromCustomer($proof, $file, 'payment_proof');
            Audit::record(AuditAction::ProofSubmitted, $order, null, after: [
                'proof' => $proof->id, 'claimed_minor' => $claimed->minor, 'replaces' => $previous?->id,
            ], source: self::SOURCE, label: $order->number);
            $this->notices->proofSubmitted($proof, $order);

            return $proof;
        });
    }

    private static function ensureOwn(CustomerActor $customer, Order $order): void
    {
        if ($order->customer_id !== $customer->customerId) {
            throw new AuthorizationException;
        }
    }
}
