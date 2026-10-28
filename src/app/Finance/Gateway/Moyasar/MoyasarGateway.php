<?php

namespace App\Finance\Gateway\Moyasar;

use App\Finance\Gateway\GatewayUnavailable;
use App\Finance\Gateway\IgnoredGatewayEvent;
use App\Finance\Gateway\InvalidGatewaySignature;
use App\Finance\Gateway\PaymentGateway;
use App\Finance\Gateway\VerifiedGatewayEvent;
use App\Models\Facility;
use App\Models\Finance\GatewaySession;
use App\Models\Finance\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

/**
 * Q-45: Moyasar behind PaymentGateway (ADR-0023). A checkout is a Moyasar invoice (hosted payment page) created server
 * side for the exact pending amount; the customer comes back to our pending page, which never confirms anything.
 *
 * Notifications are Moyasar account webhooks. Moyasar does not sign the body with an HMAC: it carries the shared
 * `secret_token` configured in the dashboard, compared here in constant time. Even then the body is not trusted for
 * money: the payment is re-read from Moyasar with the secret key (server to server) and only that record's status,
 * amount, currency and invoice are used. A live event is refused while the account is in test mode.
 */
final class MoyasarGateway implements PaymentGateway
{
    public const KEY = MoyasarSettings::KEY;

    /** Minutes the hosted invoice stays payable after our reservation window (the late-payment paths cover them). */
    public const INVOICE_GRACE_MINUTES = 10;

    public function __construct(private readonly MoyasarClient $client, private readonly MoyasarSettings $settings) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function checkoutUrl(Payment $payment, string $merchantReference, string $returnUrl, ?CarbonImmutable $expiresAt = null): string
    {
        $order = $payment->order;
        $invoice = $this->client->createInvoice([
            'amount' => $payment->amount_minor,
            'currency' => self::currency(),
            'description' => 'Order '.$order->number,
            'success_url' => $returnUrl,
            'back_url' => $returnUrl,
            'expired_at' => ($expiresAt ?? CarbonImmutable::now()->addMinutes(30))->addMinutes(self::INVOICE_GRACE_MINUTES)->toIso8601String(),
            'metadata' => ['merchant_reference' => $merchantReference, 'order' => $order->number],
        ]);
        $id = $invoice['id'] ?? null;
        $url = $invoice['url'] ?? null;
        if (! is_string($id) || $id === '' || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new GatewayUnavailable('Moyasar invoice without id or url.');
        }
        GatewaySession::query()->create([
            'payment_id' => $payment->id, 'gateway' => self::KEY, 'provider_ref' => $id, 'checkout_url' => $url,
            'expires_at' => $expiresAt?->addMinutes(self::INVOICE_GRACE_MINUTES),
        ]);

        return $url;
    }

    public function verify(Request $request): VerifiedGatewayEvent
    {
        $credentials = $this->settings->credentials() ?? throw new InvalidGatewaySignature('Moyasar is not configured.');
        $body = json_decode((string) $request->getContent(), true);
        if (! is_array($body)) {
            throw new InvalidGatewaySignature('Not JSON.');
        }
        $token = $body['secret_token'] ?? null;
        if (! is_string($token) || ! hash_equals($credentials['webhook_secret'], $token)) {
            throw new InvalidGatewaySignature('Bad secret token.');
        }
        if (($body['live'] ?? false) === true) {
            throw new InvalidGatewaySignature('Live event while the account is in test mode.');
        }
        $id = $body['data']['id'] ?? null;
        if (! is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,80}$/', $id) !== 1) {
            throw new InvalidGatewaySignature('No payment id.');
        }

        // The body only names the payment; its facts come from Moyasar itself.
        $payment = $this->client->payment($id);
        $status = $payment['status'] ?? null;
        if (in_array($status, ['initiated', 'authorized', 'verified'], true)) {
            throw new IgnoredGatewayEvent('Payment still in progress.');
        }
        // A declined card on an invoice that can still be paid is not a final failure: the customer may try again.
        if ($status === 'failed' && is_string($payment['invoice_id'] ?? null)
            && ($this->client->invoice($payment['invoice_id'])['status'] ?? null) === 'initiated') {
            throw new IgnoredGatewayEvent('Invoice still payable.');
        }

        return $this->event($payment);
    }

    public function fetch(Payment $payment): ?VerifiedGatewayEvent
    {
        $session = GatewaySession::query()->where('payment_id', $payment->id)->where('gateway', self::KEY)->first();
        if ($session === null) {
            return null;
        }
        $invoice = $this->client->invoice($session->provider_ref);
        $payments = is_array($invoice['payments'] ?? null) ? $invoice['payments'] : [];
        foreach ($payments as $p) {
            if (is_array($p) && in_array($p['status'] ?? null, ['paid', 'captured'], true)) {
                return $this->event($p, $session);
            }
        }

        return match ($invoice['status'] ?? null) {
            // Nothing paid and the invoice can no longer be paid: a settled failure.
            'expired', 'canceled', 'cancelled', 'voided' => new VerifiedGatewayEvent(
                $payment->reference ?? '', (string) $session->provider_ref, 'cancelled',
                (int) ($invoice['amount'] ?? $payment->amount_minor), (string) ($invoice['currency'] ?? self::currency()),
            ),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $p  a Moyasar payment record read with the secret key
     */
    private function event(array $p, ?GatewaySession $session = null): VerifiedGatewayEvent
    {
        $session ??= is_string($p['invoice_id'] ?? null)
            ? GatewaySession::query()->where('gateway', self::KEY)->where('provider_ref', $p['invoice_id'])->first()
            : null;
        $reference = $session === null
            ? (string) ($p['metadata']['merchant_reference'] ?? '')
            : (string) Payment::query()->whereKey($session->payment_id)->value('reference');

        $outcome = match ($p['status'] ?? null) {
            'paid', 'captured' => 'succeeded',
            'failed' => 'failed',
            'voided' => 'cancelled',
            default => 'unresolved', // e.g. refunded: a person looks at it
        };
        try {
            $at = isset($p['created_at']) && is_string($p['created_at']) ? CarbonImmutable::parse($p['created_at']) : null;
        } catch (Throwable) {
            $at = null;
        }

        return new VerifiedGatewayEvent($reference, (string) ($p['id'] ?? ''), $outcome, (int) ($p['amount'] ?? 0), (string) ($p['currency'] ?? ''), $at);
    }

    private static function currency(): string
    {
        return strtoupper((string) (Facility::current()->currency ?? 'SAR'));
    }
}
