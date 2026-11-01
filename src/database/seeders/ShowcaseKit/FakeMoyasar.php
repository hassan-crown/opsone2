<?php

namespace Database\Seeders\ShowcaseKit;

use App\Models\Finance\GatewaySession;
use App\Models\Store\StoreCheckout;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * An in-process stand-in for the facility's Moyasar TEST account, used only while seeding history: invoices and
 * payments live here and every HTTP call the store makes is answered by Http::fake — no network, no real keys.
 */
final class FakeMoyasar
{
    /** @var array<string, array<string, mixed>> */
    public static array $invoices = [];

    /** @var array<string, array<string, mixed>> */
    public static array $payments = [];

    public static function install(): void
    {
        config([
            'services.moyasar.base_url' => 'https://api.moyasar.test/v1',
            'services.moyasar.publishable_key' => 'pk_test_'.str_repeat('a', 24),
            'services.moyasar.secret_key' => 'sk_test_'.str_repeat('b', 24),
            'services.moyasar.webhook_secret' => 'whsec-seeder-'.str_repeat('c', 20),
        ]);
        Http::fake(fn (HttpRequest $request) => self::answer($request));
    }

    private static function answer(HttpRequest $request): mixed
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        if ($request->method() === 'POST' && str_ends_with($path, '/invoices')) {
            $id = 'inv_'.Str::lower(Str::random(12));
            self::$invoices[$id] = [
                'id' => $id, 'status' => 'initiated', 'amount' => $request['amount'], 'currency' => $request['currency'],
                'url' => 'https://checkout.moyasar.test/invoices/'.$id, 'metadata' => $request['metadata'], 'payments' => [],
                'success_url' => $request['success_url'],
            ];

            return Http::response(self::$invoices[$id], 201);
        }
        if ($request->method() === 'GET' && preg_match('#/invoices/([^/]+)$#', $path, $m) === 1) {
            return isset(self::$invoices[$m[1]]) ? Http::response(self::$invoices[$m[1]]) : Http::response([], 404);
        }
        if ($request->method() === 'GET' && preg_match('#/payments/([^/]+)$#', $path, $m) === 1) {
            return isset(self::$payments[$m[1]]) ? Http::response(self::$payments[$m[1]]) : Http::response([], 404);
        }

        return Http::response([], 404);
    }

    public static function pay(StoreCheckout $checkout): void
    {
        $invoiceId = (string) GatewaySession::query()->where('payment_id', $checkout->payment_id)->value('provider_ref');
        $id = 'pay_'.Str::lower(Str::random(12));
        self::$payments[$id] = [
            'id' => $id, 'status' => 'paid', 'amount' => $checkout->total_minor, 'currency' => 'SAR',
            'invoice_id' => $invoiceId, 'created_at' => now()->toIso8601String(), 'metadata' => [],
        ];
        self::$invoices[$invoiceId]['payments'][] = self::$payments[$id];
        self::$invoices[$invoiceId]['status'] = 'paid';
    }

    public static function expire(StoreCheckout $checkout): void
    {
        $invoiceId = (string) GatewaySession::query()->where('payment_id', $checkout->payment_id)->value('provider_ref');
        self::$invoices[$invoiceId]['status'] = 'expired';
    }
}
