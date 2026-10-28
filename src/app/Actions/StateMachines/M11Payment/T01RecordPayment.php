<?php

namespace App\Actions\StateMachines\M11Payment;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\StateMachine;
use App\Files\AttachmentService;
use App\Finance\FinanceLock;
use App\Finance\FinanceWrite;
use App\Finance\OrderSchedule;
use App\Finance\PaymentPosting;
use App\Finance\PaymentRegistry;
use App\Models\Finance\Payment;
use App\Models\FinanceSettings;
use App\Models\Order;
use App\Models\User;
use App\Money\Money;
use App\Workflow\AppointmentBooking;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * M-11-T00 + T01 by staff (P-39, FIN-04): a payment received is recorded with amount, method, date, reference and
 * evidence (a cash collection may go without a file when the owner turned `cash_proof_required` off — then the
 * note is mandatory, ADR-0014) and is confirmed at once — one numbered receipt, allocation oldest due first, the stage collection condition
 * — with no second approval. A part or more than what is due may be recorded; what no due needs stays as the order's
 * credit. Optionally split over other orders of the same customer (FIN-04: 7,000 + 3,000 of one 10,000). Refused
 * without effect: `duplicate_reference`, `matching_proof` (confirm the customer's proof instead) and an unacknowledged
 * `similar_payment`.
 */
final class T01RecordPayment
{
    public function __construct(
        private readonly FinanceWrite $write,
        private readonly OrderSchedule $schedule,
        private readonly PaymentPosting $posting,
        private readonly AttachmentService $files,
        private readonly TransitionRecorder $transitions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'max:20'],
            'method' => ['required', 'in:'.implode(',', PaymentMethod::manual())],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.AppointmentBooking::today()],
            'reference' => ['nullable', 'required_if:method,transfer,cheque', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:1000'],
            'split' => ['nullable', 'array', 'max:5'],
            'split.*.order_id' => ['required', 'integer', 'distinct'],
            'split.*.amount' => ['required', 'string', 'max:20'],
            'acknowledge_similar' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, Order $order, array $data, ?UploadedFile $evidence, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): Payment
    {
        /** @var array{amount: string, method: string, paid_on: string, reference?: string|null, note?: string|null, split?: list<array{order_id: int, amount: string}>|null, acknowledge_similar?: bool|null} $data */
        $data = Validator::make($data, self::rules())->validate();
        $amount = self::money($data['amount'], 'amount');
        $split = [];
        foreach ($data['split'] ?? [] as $i => $part) {
            if ((int) $part['order_id'] === $order->id) {
                throw ValidationException::withMessages(["split.{$i}.order_id" => __('finance.validation.split_same_order')]);
            }
            $split[(int) $part['order_id']] = self::money($part['amount'], "split.{$i}.amount");
        }
        $own = $amount->minus(Money::sum($split));
        if ($own->isNegative() || $own->isZero()) {
            throw ValidationException::withMessages(['split' => __('finance.validation.split_exceeds')]);
        }
        $method = PaymentMethod::from($data['method']);
        self::checkEvidence($method, $evidence, $data['note'] ?? null);
        $referenceKey = $method->referenceKey($data['reference'] ?? null);
        $payload = [...$data, 'order_id' => $order->id, 'lock_version' => $lockVersion, 'evidence' => $evidence === null ? null : hash_file('sha256', (string) $evidence->getRealPath())];

        $result = $this->write->run('payment.record', $actor, $idempotencyKey, $payload, function () use ($actor, $order, $data, $amount, $own, $split, $method, $referenceKey, $evidence, $lockVersion, $source) {
            $orders = FinanceLock::orders([$order->id, ...array_keys($split)], StateMachine::M11, 'T01');
            $main = $orders[$order->id];
            $heads = [];
            foreach ($orders as $id => $locked) {
                Access::authorize($actor, Permission::P39, $locked);
                if ($locked->customer_id !== $main->customer_id) {
                    throw ValidationException::withMessages(['split' => __('finance.validation.split_other_customer')]);
                }
                $heads[$id] = FinanceLock::head($locked);
                if ($id === $main->id) {
                    FinanceLock::checkVersion($heads[$id], $lockVersion);
                }
                $this->schedule->sync($locked, $heads[$id], $actor, $source);
            }

            PaymentRegistry::ensureNewReference($referenceKey);
            if (PaymentRegistry::pendingProof($referenceKey) !== null) {
                throw new InvalidTransition(StateMachine::M11, 'T01', 'matching_proof');
            }
            if (! ($data['acknowledge_similar'] ?? false) && PaymentRegistry::similar($main->customer_id, $amount, $data['paid_on'])->isNotEmpty()) {
                throw new InvalidTransition(StateMachine::M11, 'T01', 'similar_payment');
            }

            $payment = PaymentRegistry::create([
                'customer_id' => $main->customer_id,
                'order_id' => $main->id,
                'amount_minor' => $amount->minor,
                'method' => $method,
                'paid_on' => $data['paid_on'],
                'reference' => $data['reference'] ?? null,
                'reference_key' => $referenceKey,
                'origin' => Payment::ORIGIN_STAFF,
                'status' => PaymentStatus::Recorded,
                'note' => $data['note'] ?? null,
                'recorded_by' => $actor->id,
            ]);
            $payment->setRelation('order', $main);
            $this->transitions->record(StateMachine::M11, 'T00', $payment, null, PaymentStatus::Recorded, $actor, $source);
            if ($evidence !== null) {
                $this->files->store($actor, $payment, $evidence, 'payment_evidence');
            }

            $receipt = $this->posting->confirm($payment, 'T01', [$main->id => $own] + $split, $orders, $heads, $actor, $source);

            Audit::record(AuditAction::PaymentRecorded, $main, $actor, after: [
                'payment' => $payment->id, 'receipt' => $receipt->number, 'amount_minor' => $amount->minor, 'method' => $method->value,
                'paid_on' => $data['paid_on'], 'evidence' => $evidence !== null, 'shares' => array_map(fn (Money $m) => $m->minor, [$main->id => $own] + $split),
            ], source: $source, label: $main->number);

            return ['payment_id' => $payment->id, 'receipt' => $receipt->number];
        });

        return Payment::query()->findOrFail((int) $result['result']['payment_id']);
    }

    /**
     * A proof file is required, except for cash when the facility owner turned it off; a cash collection without a
     * file keeps its collector (the recording member) and must say why in the note.
     */
    private static function checkEvidence(PaymentMethod $method, ?UploadedFile $evidence, ?string $note): void
    {
        if ($evidence !== null) {
            return;
        }
        if ($method !== PaymentMethod::Cash || FinanceSettings::current()->cashProofRequired()) {
            throw ValidationException::withMessages(['evidence' => __('finance.validation.evidence_required')]);
        }
        if (trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => __('finance.validation.cash_note_required')]);
        }
    }

    private static function money(string $text, string $field): Money
    {
        try {
            $money = Money::fromInput(trim($text));
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => __('finance.validation.amount')]);
        }
        if ($money->isNegative() || $money->isZero()) {
            throw ValidationException::withMessages([$field => __('finance.validation.amount')]);
        }

        return $money;
    }
}
