<?php

namespace App\Store;

use App\Access\Exceptions\InvalidTransition;
use App\Actions\Store\ReadyOrderPayment;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\PaymentStatus;
use App\Enums\ReadyOrderStatus;
use App\Enums\StateMachine;
use App\Finance\FinanceLock;
use App\Finance\Gateway\GatewayPayments;
use App\Finance\Gateway\GatewayRegistry;
use App\Finance\Gateway\GatewayUnavailable;
use App\Finance\Gateway\Moyasar\MoyasarGateway;
use App\Finance\Gateway\PaymentGateway;
use App\Models\Customer;
use App\Models\Finance\GatewaySession;
use App\Models\Finance\Payment;
use App\Models\Order;
use App\Models\Store\Coupon;
use App\Models\Store\StoreCheckout;
use App\Models\Store\StoreLink;
use App\Money\Money;
use App\Orders\OrderOpener;
use App\Support\GaplessSequence;
use App\Support\LockingTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The store checkout (WEB-05/06, process P07, ADR-0023). `start()` is one transaction: the store must be selling, the
 * basket link (if any) takes its one attempt (M-31-T02), the ready order opens (M-05-T00) and reserves for the window
 * (T02, 30 minutes by default), the coupon use is reserved under its lock (M-32-T01) and the pending online payment is
 * created (M-11-T00). Only after the commit is the provider called (no network inside a lock); if it cannot start the
 * payment, everything is released at once. Repeating a start with the same key returns the same checkout.
 *
 * Money and the purchase are decided only by verified provider results (`StoreGatewayOutcomes`); the expiry service
 * re-reads the provider before releasing anything, and never assumes a failure when the provider cannot be reached.
 */
final class StoreCheckouts
{
    /** After this long without an answer from the provider past the window, a person looks at it (M-05-T05). */
    public const UNREACHABLE_GRACE_MINUTES = 30;

    public function __construct(
        private readonly StoreGate $gate,
        private readonly StoreCatalogue $catalogue,
        private readonly OrderOpener $opener,
        private readonly ReadyOrderPayment $payments,
        private readonly Coupons $coupons,
        private readonly StoreLinks $links,
        private readonly GatewayPayments $gatewayPayments,
        private readonly GatewayRegistry $gateways,
        private readonly GaplessSequence $sequence,
    ) {}

    public function gateway(): PaymentGateway
    {
        return $this->gateways->get(MoyasarGateway::KEY) ?? throw new GatewayUnavailable('No gateway.');
    }

    /**
     * @param  array<int, int>  $lines  variant id → whole quantity
     * @return array{checkout: StoreCheckout, url: string}
     */
    public function start(Customer $customer, array $lines, ?StoreLink $link, ?string $couponCode, string $idempotencyKey, string $returnUrl): array
    {
        if (preg_match('/^[A-Za-z0-9-]{8,64}$/', $idempotencyKey) !== 1) {
            throw ValidationException::withMessages(['idempotency_key' => __('checkout.validation.retry')]);
        }
        $existing = StoreCheckout::query()->where('customer_id', $customer->id)->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return ['checkout' => $existing, 'url' => $this->resumeUrl($existing, str_replace('__REF__', $existing->reference, $returnUrl))];
        }
        $gateway = $this->gateway();
        if ($lines === [] && $link === null) {
            throw ValidationException::withMessages(['lines' => __('checkout.validation.empty')]);
        }

        try {
            $checkout = $this->open($customer, $lines, $link, $couponCode, $idempotencyKey, $gateway);
        } catch (UniqueConstraintViolationException $e) {
            // The same start sent twice at once: the first one won, answer with it.
            $existing = StoreCheckout::query()->where('customer_id', $customer->id)->where('idempotency_key', $idempotencyKey)->first() ?? throw $e;

            return ['checkout' => $existing, 'url' => $this->resumeUrl($existing, str_replace('__REF__', $existing->reference, $returnUrl))];
        }

        // The provider sends the buyer back to the pending page of this checkout (which confirms nothing).
        $returnUrl = str_replace('__REF__', $checkout->reference, $returnUrl);
        try {
            $payment = Payment::query()->with('order')->findOrFail((int) $checkout->payment_id);
            $url = $gateway instanceof MoyasarGateway
                ? $gateway->checkoutUrl($payment, GatewayPayments::merchantReference($payment), $returnUrl, CarbonImmutable::instance($checkout->expires_at))
                : $gateway->checkoutUrl($payment, GatewayPayments::merchantReference($payment), $returnUrl);
        } catch (GatewayUnavailable) {
            // No invoice exists, so no money can arrive: release now (M-31-T04 / M-32-T03 / M-05-T04).
            $this->settleWithoutMoney($checkout, CheckoutStatus::Abandoned, 'gateway_unavailable', true);
            throw ValidationException::withMessages(['payment' => __('checkout.validation.gateway_unavailable')]);
        }

        return ['checkout' => $checkout->refresh(), 'url' => $url];
    }

    /**
     * @param  array<int, int>  $lines
     */
    private function open(Customer $customer, array $lines, ?StoreLink $link, ?string $couponCode, string $idempotencyKey, PaymentGateway $gateway): StoreCheckout
    {
        return LockingTransaction::run(function () use ($customer, $lines, $link, $couponCode, $idempotencyKey, $gateway) {
            $settings = $this->gate->assertSelling();
            if ($link !== null) {
                $link = $this->links->beginAttempt($link, $customer);
                $lines = StoreLinks::linesOf($link);
            }

            $priced = [];
            $orderLines = [];
            foreach ($lines as $variantId => $quantity) {
                $variant = $this->catalogue->variant((int) $variantId);
                if ($variant === null || $quantity < 1) {
                    throw ValidationException::withMessages(['lines' => __('checkout.validation.unavailable')]);
                }
                $priced[] = ['variant' => $variant, 'quantity' => (int) $quantity];
                $orderLines[] = ['type' => 'ready_variant', 'variant_id' => $variant->id, 'quantity' => (string) (int) $quantity];
            }
            $coupon = null;
            if ($couponCode !== null && $couponCode !== '') {
                $coupon = $this->coupons->find($couponCode) ?? throw ValidationException::withMessages(['coupon' => __('coupons.validation.unavailable')]);
            }
            $pricing = StorePricing::price($priced, $coupon);

            $order = $this->opener->openFromStore($customer, (int) $settings->branch_id, $link === null ? $settings->assignee_id : $link->owner_id, $orderLines, ReadyOrderPayment::SOURCE);
            $order = FinanceLock::order($order->id, StateMachine::M11, 'T00');
            $order->total_minor = $pricing['totals']['total_minor'];
            $order->save();

            $expiresAt = now()->addMinutes(max(5, $settings->reservation_minutes));
            $checkout = StoreCheckout::query()->create([
                'number' => $this->sequence->format('store_checkouts', 'CK', 6),
                'reference' => (string) Str::uuid(),
                'customer_id' => $customer->id,
                'order_id' => $order->id,
                'link_id' => $link?->id,
                'coupon_id' => $coupon?->id,
                'status' => CheckoutStatus::PendingPayment,
                'subtotal_minor' => $pricing['totals']['subtotal_minor'],
                'discount_minor' => $pricing['totals']['discount_minor'],
                'vat_minor' => $pricing['totals']['vat_minor'],
                'total_minor' => $pricing['totals']['total_minor'],
                'pricing' => $pricing,
                'expires_at' => $expiresAt,
                'idempotency_key' => $idempotencyKey,
            ]);
            $this->payments->start($order, ['checkout' => $checkout->number, 'expires_at' => $expiresAt->toIso8601String()]);
            if ($coupon !== null) {
                $this->coupons->reserve($coupon, $checkout, $pricing);
            }
            $payment = $this->gatewayPayments->createPending($order, Money::of($pricing['totals']['total_minor']), $gateway);
            $checkout->forceFill(['payment_id' => $payment->id])->save();

            Audit::record(AuditAction::StoreCheckoutStarted, $order, null, after: [
                'checkout' => $checkout->number, 'total_minor' => $checkout->total_minor, 'discount_minor' => $checkout->discount_minor,
                'coupon' => $coupon?->code, 'link' => $link?->number, 'expires_at' => $expiresAt->toIso8601String(),
            ], source: ReadyOrderPayment::SOURCE, label: $order->number);

            return $checkout;
        });
    }

    /**
     * The customer gives up on the pending page. Moyasar is asked first: a payment already made is applied instead.
     */
    public function abandon(StoreCheckout $checkout): StoreCheckout
    {
        if (! $checkout->status->isOpen()) {
            return $checkout;
        }
        if ($this->applyProviderResult($checkout)) {
            return $checkout->refresh();
        }
        $this->settleWithoutMoney($checkout, CheckoutStatus::Abandoned, 'abandoned', false);

        return $checkout->refresh();
    }

    /**
     * Time service (scheduler, every minute): windows that ended. Returns how many were settled.
     */
    public function expireDue(int $limit = 100): int
    {
        $count = 0;
        $due = StoreCheckout::query()->where('status', CheckoutStatus::PendingPayment)->where('expires_at', '<=', now())->orderBy('id')->limit($limit)->get();
        foreach ($due as $checkout) {
            try {
                if ($this->applyProviderResult($checkout)) {
                    $count++;

                    continue;
                }
            } catch (GatewayUnavailable) {
                $this->providerUnreachable($checkout);

                continue;
            }
            $this->settleWithoutMoney($checkout, CheckoutStatus::Expired, 'reservation_expired', false);
            $count++;
        }

        // Payments still open after their window was released: settle them from the provider once the invoice ended.
        $open = StoreCheckout::query()->whereIn('status', [CheckoutStatus::Expired, CheckoutStatus::Abandoned])
            ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::PendingConfirmation))
            ->where('updated_at', '<=', now()->subMinutes(MoyasarGateway::INVOICE_GRACE_MINUTES))
            ->orderBy('id')->limit($limit)->get();
        foreach ($open as $checkout) {
            try {
                $this->applyProviderResult($checkout);
            } catch (GatewayUnavailable) {
                // try again on the next run
            }
        }

        return $count;
    }

    /**
     * Reads the payment from the provider (server to server) and applies a settled result through the same verified
     * path as a webhook. Returns true when something was applied.
     */
    public function applyProviderResult(StoreCheckout $checkout): bool
    {
        $payment = $checkout->payment;
        if ($payment === null || $payment->status !== PaymentStatus::PendingConfirmation) {
            return false;
        }
        $gateway = $this->gateway();
        $event = $gateway->fetch($payment);
        if ($event === null) {
            return false;
        }
        $this->gatewayPayments->applyVerified($gateway, $event);

        return true;
    }

    /**
     * No money and no open transaction: cancel the order, release the stock and the coupon use, give the link back.
     * With `$cancelPayment` (the provider never created anything) the pending payment is cancelled too (M-11-T06);
     * otherwise it stays pending until the provider settles it (a late success then follows the late path).
     */
    public function settleWithoutMoney(StoreCheckout $checkout, CheckoutStatus $to, string $reason, bool $cancelPayment): void
    {
        LockingTransaction::run(function () use ($checkout, $to, $reason, $cancelPayment) {
            $order = FinanceLock::order($checkout->order_id, StateMachine::M11, 'T02');
            $checkout = StoreCheckout::query()->lockForUpdate()->findOrFail($checkout->id);
            if (! $checkout->status->isOpen()) {
                return;
            }
            if ($order->status === ReadyOrderStatus::AwaitingPayment->value) {
                $this->payments->cancel($order, $reason, ['checkout' => $checkout->number]);
            }
            $this->coupons->release($checkout, $reason);
            if ($checkout->link_id !== null) {
                $this->links->endAttempt($checkout, false);
            }
            if ($cancelPayment && $checkout->payment_id !== null) {
                $this->gatewayPayments->cancelPending($checkout->payment_id, $reason);
            }
            $checkout->forceFill(['status' => $to, 'lock_version' => $checkout->lock_version + 1])->save();
            Audit::record(AuditAction::StoreCheckoutReleased, $order, null, before: ['status' => CheckoutStatus::PendingPayment->value],
                after: ['checkout' => $checkout->number, 'status' => $to->value], reason: $reason, source: ReadyOrderPayment::SOURCE, label: $order->number);
        });
    }

    private function providerUnreachable(StoreCheckout $checkout): void
    {
        LockingTransaction::run(function () use ($checkout) {
            $order = FinanceLock::order($checkout->order_id, StateMachine::M11, 'T02');
            $checkout = StoreCheckout::query()->lockForUpdate()->findOrFail($checkout->id);
            if (! $checkout->status->isOpen()) {
                return;
            }
            $checkout->provider_failures = min(255, $checkout->provider_failures + 1);
            if ($checkout->expires_at->lessThanOrEqualTo(now()->subMinutes(self::UNREACHABLE_GRACE_MINUTES))
                && $order->status === ReadyOrderStatus::AwaitingPayment->value) {
                // Never a fake failure: the reservation stays and a person checks the payment (M-05-T05).
                $this->payments->flag($order, 'provider_unreachable', ['checkout' => $checkout->number]);
                app(StoreGatewayOutcomes::class)->needsAttention($checkout, $order, 'provider_unreachable');
            }
            $checkout->save();
        });
    }

    private function resumeUrl(StoreCheckout $checkout, string $returnUrl): string
    {
        if ($checkout->status->isOpen() && $checkout->payment_id !== null) {
            $url = GatewaySession::query()->where('payment_id', $checkout->payment_id)->value('checkout_url');
            if (is_string($url)) {
                return $url;
            }
        }

        return $returnUrl;
    }

    /**
     * Whether a coupon code could apply to these lines for this customer now (the checkout page's price preview).
     *
     * @param  array<int, int>  $lines
     * @return array{pricing: array<string, mixed>, coupon: Coupon|null, error: string|null}
     */
    public function preview(array $lines, ?string $couponCode, ?int $customerId): array
    {
        $priced = [];
        foreach ($lines as $variantId => $quantity) {
            $variant = $this->catalogue->variant((int) $variantId);
            if ($variant !== null) {
                $priced[] = ['variant' => $variant, 'quantity' => (int) $quantity];
            }
        }
        $coupon = null;
        $error = null;
        if ($couponCode !== null && $couponCode !== '') {
            $coupon = $this->coupons->find($couponCode);
            try {
                $this->coupons->check($coupon, StorePricing::price($priced, $coupon), $customerId);
            } catch (ValidationException $e) {
                $error = (string) collect($e->errors())->flatten()->first();
                $coupon = null;
            }
        }

        return ['pricing' => StorePricing::price($priced, $coupon), 'coupon' => $coupon, 'error' => $error];
    }

    /**
     * Throws when the order is not one of this store's checkouts awaiting payment.
     */
    public static function forOrder(Order $order): ?StoreCheckout
    {
        return StoreCheckout::query()->where('order_id', $order->id)->first();
    }

    public static function assertOpen(StoreCheckout $checkout): void
    {
        if (! $checkout->status->isOpen()) {
            throw new InvalidTransition(StateMachine::M05, 'T03', 'checkout_'.$checkout->status->value);
        }
    }
}
