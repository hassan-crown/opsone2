<?php

use App\Inventory\Quantity;
use App\Models\Customer;
use App\Models\Finance\GatewaySession;
use App\Models\Inventory\StockLocation;
use App\Models\Store\StoreCheckout;
use App\Models\Store\StoreSettings;
use App\Portal\PortalSession;
use App\Store\StoreCheckouts;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../Inventory/InventoryTestHelpers.php';
require_once __DIR__.'/../Portal/PortalTestHelpers.php';

const STORE_WEBHOOK_SECRET = 'whsec-test-0123456789abcdef';

/**
 * A fake Moyasar account for tests (ADR-0023): invoices and payments live here, every HTTP call is answered by
 * Http::fake — no real network. `down` makes every call fail like an unreachable provider.
 */
final class FakeMoyasar
{
    /** @var array<string, array<string, mixed>> */
    public static array $invoices = [];

    /** @var array<string, array<string, mixed>> */
    public static array $payments = [];

    public static bool $down = false;

    public static int $created = 0;

    public static function reset(): void
    {
        self::$invoices = [];
        self::$payments = [];
        self::$down = false;
        self::$created = 0;
        config([
            'services.moyasar.base_url' => 'https://api.moyasar.test/v1',
            'services.moyasar.publishable_key' => 'pk_test_'.str_repeat('a', 24),
            'services.moyasar.secret_key' => 'sk_test_'.str_repeat('b', 24),
            'services.moyasar.webhook_secret' => STORE_WEBHOOK_SECRET,
        ]);
        Http::preventStrayRequests();
        Http::fake(fn (HttpRequest $request) => self::answer($request));
    }

    private static function answer(HttpRequest $request): mixed
    {
        if (self::$down) {
            return Http::response(['message' => 'unavailable'], 503);
        }
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        if ($request->method() === 'POST' && str_ends_with($path, '/invoices')) {
            self::$created++;
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

    /**
     * The customer pays on Moyasar's page: a payment record on the checkout's invoice.
     */
    public static function pay(StoreCheckout $checkout, string $status = 'paid', ?int $amount = null, string $currency = 'SAR', ?string $at = null): string
    {
        $invoiceId = (string) GatewaySession::query()->where('payment_id', $checkout->payment_id)->value('provider_ref');
        $id = 'pay_'.Str::lower(Str::random(12));
        self::$payments[$id] = [
            'id' => $id, 'status' => $status, 'amount' => $amount ?? $checkout->total_minor, 'currency' => $currency,
            'invoice_id' => $invoiceId, 'created_at' => $at ?? now()->toIso8601String(), 'metadata' => [],
        ];
        self::$invoices[$invoiceId]['payments'][] = self::$payments[$id];
        if (in_array($status, ['paid', 'captured'], true)) {
            self::$invoices[$invoiceId]['status'] = 'paid';
        }

        return $id;
    }

    public static function expireInvoice(StoreCheckout $checkout): void
    {
        $invoiceId = (string) GatewaySession::query()->where('payment_id', $checkout->payment_id)->value('provider_ref');
        self::$invoices[$invoiceId]['status'] = 'expired';
    }
}

/**
 * Store fixture: the inventory fixture (owner, sales in branch A, a published ready variant, location A1 in branch A),
 * the store open on branch A followed by the sales member, :stock pieces at A1 and a fake Moyasar test account.
 */
function storeFixture(int $stock = 5): object
{
    $s = stockFixture();
    FakeMoyasar::reset();
    StoreSettings::query()->create([
        'enabled' => true, 'branch_id' => $s->f->org->a->id, 'assignee_id' => $s->f->sales->id, 'reservation_minutes' => 30,
    ]);
    // The store reserves from the branch's first usable location; keep it A1 for the test.
    StockLocation::query()->whereKey($s->la2->id)->update(['status' => 'disabled']);
    if ($stock > 0) {
        stockReceive($s->la, 'ready_variant', $s->f->variant->id, (string) $stock);
    }
    Auth::forgetGuards();

    return $s;
}

function storeBuyer(?string $mobile = null): Customer
{
    return Customer::factory()->create(['branch_id' => StoreSettings::current()->branch_id, 'portal_login_enabled' => true, 'mobile' => $mobile ?? '+9665'.random_int(10000000, 99999999)]);
}

/**
 * Starts a checkout directly through the service (the HTTP path is covered by the screen tests).
 *
 * @param  array<int, int>  $lines
 * @return array{checkout: StoreCheckout, url: string}
 */
function storeStart(Customer $customer, array $lines, ?string $coupon = null, ?App\Models\Store\StoreLink $link = null, ?string $key = null): array
{
    return app(StoreCheckouts::class)->start($customer, $lines, $link, $coupon, $key ?? (string) Str::uuid(), 'https://shop.test/ar/store/checkout/__REF__');
}

/**
 * A Moyasar account webhook for a payment (the body only names it; the app re-reads the payment).
 */
function moyasarWebhook(string $paymentId, string $type = 'payment_paid', ?string $secret = null, bool $live = false): TestResponse
{
    $body = (string) json_encode([
        'id' => (string) Str::uuid(), 'type' => $type, 'created_at' => now()->toIso8601String(), 'secret_token' => $secret ?? STORE_WEBHOOK_SECRET,
        'account_name' => 'Facility', 'live' => $live, 'data' => ['id' => $paymentId, 'status' => 'paid', 'amount' => 1],
    ]);

    return test()->call('POST', '/webhooks/payments/moyasar', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body);
}

function storeAvailable(object $s): string
{
    return stockBalance($s->la, 'ready_variant', $s->f->variant->id)['available'];
}

function storeQty(int $milli): string
{
    return Quantity::format($milli);
}

function actingAsBuyer(Customer $customer): void
{
    Auth::guard(PortalSession::GUARD)->setUser($customer);
}

/**
 * The code of the latest checkout code message (payload encrypted at rest; the model decrypts it).
 */
function storeOtpCode(): string
{
    $message = App\Models\OutboxMessage::query()->where('idempotency_key', 'like', 'store-otp:%')->latest('id')->firstOrFail();
    $text = (string) ($message->payload['text'] ?? implode(' ', $message->payload['lines'] ?? []));
    preg_match('/\b(\d{6})\b/', $text, $m);

    return $m[1];
}
