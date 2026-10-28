<?php

namespace App\Finance\Gateway;

use App\Access\Exceptions\InvalidTransition;
use App\Agreements\CustomerActor;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\StateMachine;
use App\Finance\CollectionNotices;
use App\Finance\FinanceLock;
use App\Finance\OrderSchedule;
use App\Finance\PaymentPosting;
use App\Finance\PaymentRegistry;
use App\Access\TransitionRecorder;
use App\Models\Finance\Payment;
use App\Models\Order;
use App\Money\Money;
use App\Support\LockingTransaction;
use App\Workflow\AppointmentBooking;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Online payment (FIN-07, M-11-T02..T06, T08) without any provider bound in v1. A payment starts `pending_confirmation`
 * from the customer's account; it becomes money only in `applyWebhook()`, after the gateway verified the provider's
 * signed notification — the browser return page never confirms (FIN-03). A repeated or late notification returns the
 * known result (one receipt, T08); a success that arrives after a local failure or cancellation is referred to the
 * order's person, never silently dropped or silently posted.
 */
final class GatewayPayments
{
    public const SOURCE = 'gateway';

    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly OrderSchedule $schedule,
        private readonly PaymentPosting $posting,
        private readonly TransitionRecorder $transitions,
        private readonly CollectionNotices $notices,
    ) {}

    public static function merchantReference(Payment $payment): string
    {
        return (string) substr((string) $payment->reference, 0, 80);
    }

    /**
     * P-69 from the customer portal (P8): a pending payment and the provider page. The portal authenticated the
     * customer; this re-checks the order is theirs.
     *
     * @return array{payment: Payment, url: string}
     */
    public function begin(CustomerActor $customer, Order $order, Money $amount, string $gatewayKey, string $returnUrl): array
    {
        $gateway = $this->gateways->get($gatewayKey) ?? throw new InvalidTransition(StateMachine::M11, 'T00', 'gateway_unavailable');
        if ($amount->isNegative() || $amount->isZero()) {
            throw new InvalidTransition(StateMachine::M11, 'T00', 'invalid_amount');
        }

        $payment = LockingTransaction::run(function () use ($customer, $order, $amount, $gateway) {
            $order = FinanceLock::order($order->id, StateMachine::M11, 'T00');
            if ($order->customer_id !== $customer->customerId) {
                throw new AuthorizationException;
            }
            $merchant = 'PAY-'.Str::upper(Str::random(20));
            $payment = PaymentRegistry::create([
                'customer_id' => $order->customer_id, 'order_id' => $order->id, 'amount_minor' => $amount->minor,
                'method' => PaymentMethod::Online, 'paid_on' => AppointmentBooking::today(), 'reference' => $merchant,
                'reference_key' => self::key($gateway->key(), $merchant), 'origin' => Payment::ORIGIN_GATEWAY,
                'status' => PaymentStatus::PendingConfirmation, 'gateway' => $gateway->key(),
            ], 'T00');
            $this->transitions->record(StateMachine::M11, 'T00', $payment, null, PaymentStatus::PendingConfirmation, null, self::SOURCE);

            return $payment;
        });

        return ['payment' => $payment, 'url' => $gateway->checkoutUrl($payment, self::merchantReference($payment), $returnUrl)];
    }

    /**
     * The only way an online payment becomes money: verify, then apply once.
     */
    public function applyWebhook(string $gatewayKey, Request $request): ?Payment
    {
        $gateway = $this->gateways->get($gatewayKey);
        if ($gateway === null) {
            abort(404);
        }
        $event = $gateway->verify($request);
        if (! in_array($event->outcome, VerifiedGatewayEvent::OUTCOMES, true)) {
            throw new InvalidGatewaySignature('Unknown outcome.');
        }

        return LockingTransaction::run(function () use ($gateway, $event) {
            $known = Payment::query()->where('reference_key', self::key($gateway->key(), $event->merchantReference))->first();
            if ($known === null) {
                return null;
            }
            $order = FinanceLock::order($known->order_id, StateMachine::M11, 'T02');
            $head = FinanceLock::head($order);
            $payment = Payment::query()->lockForUpdate()->findOrFail($known->id);
            $payment->setRelation('order', $order);
            $from = $payment->status;
            $matches = $event->amountMinor === $payment->amount_minor && strtoupper($event->currency) === 'SAR';
            $context = ['transaction' => $event->transactionId, 'outcome' => $event->outcome];

            $to = match (true) {
                $from === PaymentStatus::Confirmed || $from === PaymentStatus::Corrected => null,
                $event->outcome === 'succeeded' && ! $matches => $from === PaymentStatus::PendingConfirmation ? ['T03', PaymentStatus::Unresolved] : null,
                $event->outcome === 'succeeded' && in_array($from, [PaymentStatus::Failed, PaymentStatus::Cancelled], true) => 'late',
                $event->outcome === 'succeeded' => [$from === PaymentStatus::Unresolved ? 'T04' : 'T02', PaymentStatus::Confirmed],
                in_array($event->outcome, ['pending', 'unresolved'], true) => $from === PaymentStatus::PendingConfirmation ? ['T03', PaymentStatus::Unresolved] : null,
                $event->outcome === 'failed' => match ($from) {
                    PaymentStatus::PendingConfirmation => ['T05', PaymentStatus::Failed],
                    PaymentStatus::Unresolved => ['T04', PaymentStatus::Failed],
                    default => null,
                },
                default => $from === PaymentStatus::PendingConfirmation ? ['T06', PaymentStatus::Cancelled] : null,
            };

            if ($to === null) {
                // M-11-T08: a repeated or stale event returns the known state — no second receipt or allocation.
                $this->transitions->record(StateMachine::M11, 'T08', $payment, $from, $from, null, self::SOURCE, null, $context);

                return $payment;
            }
            if ($to === 'late') {
                $this->transitions->record(StateMachine::M11, 'T08', $payment, $from, $from, null, self::SOURCE, 'late_success', $context);
                $this->notices->lateGatewayResult($order, $event->merchantReference);
                Audit::record(AuditAction::PaymentGatewayResult, $order, null, after: [...$context, 'payment' => $payment->id, 'late_success' => true], source: self::SOURCE, label: $order->number);

                return $payment;
            }

            [$transition, $status] = $to;
            if ($status === PaymentStatus::Confirmed) {
                $this->schedule->sync($order, $head, null, self::SOURCE);
                $this->posting->confirm($payment, $transition, [$order->id => $payment->amount()], [$order->id => $order], [$order->id => $head], null, self::SOURCE, $context);
            } else {
                $payment->forceFill(['status' => $status, 'lock_version' => $payment->lock_version + 1])->save();
                $this->transitions->record(StateMachine::M11, $transition, $payment, $from, $status, null, self::SOURCE, null, $context);
            }
            Audit::record(AuditAction::PaymentGatewayResult, $order, null, after: [...$context, 'payment' => $payment->id, 'status' => $status->value], source: self::SOURCE, label: $order->number);

            return $payment->refresh();
        });
    }

    private static function key(string $gateway, string $merchantReference): string
    {
        return 'online:'.$gateway.':'.strtoupper($merchantReference);
    }
}
