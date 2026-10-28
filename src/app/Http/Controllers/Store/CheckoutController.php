<?php

namespace App\Http\Controllers\Store;

use App\Finance\Gateway\GatewayUnavailable;
use App\Http\Controllers\Controller;
use App\Models\Finance\GatewaySession;
use App\Models\Store\StoreCheckout;
use App\Models\Store\StoreLink;
use App\Models\Store\StoreSettings;
use App\Portal\OtpThrottled;
use App\Portal\PortalSession;
use App\Store\Cart;
use App\Store\CheckoutOtp;
use App\Store\CheckoutStatus;
use App\Store\StoreCatalogue;
use App\Store\StoreCheckouts;
use App\Store\StoreGate;
use App\Store\StoreLinks;
use App\Store\StoreView;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Checkout (one page, four parts: items, identity by code — Q-48 a, pickup — WEB-07, coupon and payment) and
 * CheckoutPending (WEB-06). The browser sends only what the buyer chose: lines come from the session cart or the
 * basket link kept server side, never an owner, price or customer id. The pending page shows the server's state and
 * never confirms anything from its URL (the provider's return carries no trust).
 */
final class CheckoutController extends Controller
{
    private const BUYER = 'store.buyer';

    private const COUPON = 'store.coupon';

    private const LINK = 'store.link';

    public function __construct(
        private readonly StoreGate $gate,
        private readonly StoreCatalogue $catalogue,
        private readonly StoreCheckouts $checkouts,
        private readonly StoreLinks $links,
        private readonly CheckoutOtp $otp,
        private readonly StoreView $view,
        private readonly Cart $cart,
    ) {}

    public function show(Request $request, string $locale): View|RedirectResponse
    {
        $this->gate->abortUnlessOpen();
        if ($request->query('basket') === 'cart') {
            $request->session()->forget(self::LINK);
        }
        $link = $this->link($request);
        $lines = $link !== null ? StoreLinks::linesOf($link) : $this->cart->lines();
        if ($lines === []) {
            return redirect()->route('store.cart', ['locale' => $locale]);
        }
        $customer = PortalSession::customer();
        $preview = $this->checkouts->preview($lines, $request->session()->get(self::COUPON), $customer?->id);
        $names = [];
        foreach ($preview['pricing']['lines'] as $row) {
            $variant = $this->catalogue->variant((int) $row['variant_id']);
            $names[(int) $row['variant_id']] = $variant === null ? '' : $variant->product->name->get($locale).' — '.$variant->name->get($locale);
        }
        $settings = StoreSettings::current();

        return $this->view->render('store.checkout', $locale, __('checkout.title'), [
            'pricing' => $preview['pricing'],
            'names' => $names,
            'coupon' => $preview['coupon'],
            'couponCode' => $request->session()->get(self::COUPON),
            'couponError' => $preview['error'],
            'fromLink' => $link !== null,
            'linkExpires' => $link?->expires_at,
            'customer' => $customer,
            'buyer' => $request->session()->get(self::BUYER),
            'branch' => $locale === 'en' && $settings->branch?->name_en !== null ? $settings->branch->name_en : $settings->branch?->name_ar,
            'minutes' => $settings->reservation_minutes,
            'idempotencyKey' => (string) Str::uuid(),
        ], false);
    }

    public function sendCode(Request $request, string $locale): RedirectResponse
    {
        $this->gate->abortUnlessOpen();
        $data = $request->validate([
            'mobile' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'email' => ['nullable', 'email', 'max:190'],
        ]);
        $mobile = CheckoutOtp::normalizeMobile($data['mobile']) ?? throw ValidationException::withMessages(['mobile' => __('checkout.otp.mobile_invalid')]);
        $email = isset($data['email']) && $data['email'] !== '' ? mb_strtolower(trim($data['email'])) : null;
        try {
            $hint = $this->otp->issue($mobile, trim($data['name']), $email, $request->ip());
        } catch (OtpThrottled $e) {
            throw ValidationException::withMessages(['mobile' => __('portal.otp.throttled', ['minutes' => (int) ceil($e->retryAfterSeconds / 60)])]);
        }
        $request->session()->put(self::BUYER, ['mobile' => $mobile, 'name' => trim($data['name']), 'email' => $email, 'hint' => $hint]);

        return redirect()->route('store.checkout', ['locale' => $locale])->with('status', __('checkout.otp.sent', ['to' => $hint]));
    }

    public function verify(Request $request, string $locale): RedirectResponse
    {
        $this->gate->abortUnlessOpen();
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        /** @var array{mobile: string, name: string, email: string|null}|null $buyer */
        $buyer = $request->session()->get(self::BUYER);
        if ($buyer === null) {
            return redirect()->route('store.checkout', ['locale' => $locale]);
        }
        try {
            $verified = $this->otp->verify($buyer, trim($data['code']), $request->ip());
        } catch (OtpThrottled $e) {
            throw ValidationException::withMessages(['code' => __('portal.otp.throttled', ['minutes' => (int) ceil($e->retryAfterSeconds / 60)])]);
        }
        if ($verified === null) {
            throw ValidationException::withMessages(['code' => __('portal.otp.invalid')]);
        }
        // The cart and the basket link survive the session regeneration of the sign-in.
        $keep = $request->session()->only([Cart::KEY, self::LINK, self::COUPON]);
        PortalSession::signIn($request, $verified['customer'], $verified['channel']);
        $request->session()->put($keep);
        $request->session()->forget(self::BUYER);

        return redirect()->route('store.checkout', ['locale' => $locale]);
    }

    public function coupon(Request $request, string $locale): RedirectResponse
    {
        $this->gate->abortUnlessOpen();
        $data = $request->validate(['coupon' => ['nullable', 'string', 'max:32']]);
        $code = trim((string) ($data['coupon'] ?? ''));
        if ($code === '') {
            $request->session()->forget(self::COUPON);
        } else {
            $request->session()->put(self::COUPON, $code);
        }

        return redirect()->route('store.checkout', ['locale' => $locale]);
    }

    public function start(Request $request, string $locale): RedirectResponse
    {
        $this->gate->abortUnlessOpen();
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'max:64']]);
        $customer = PortalSession::customer();
        if ($customer === null) {
            throw ValidationException::withMessages(['mobile' => __('checkout.validation.verify_first')]);
        }
        $link = $this->link($request);
        $lines = $link !== null ? StoreLinks::linesOf($link) : $this->cart->lines();

        $result = $this->checkouts->start($customer, $lines, $link, $request->session()->get(self::COUPON), $data['idempotency_key'],
            route('store.checkout.pending', ['locale' => $locale, 'reference' => '__REF__']));
        if ($link === null) {
            $this->cart->clear();
        }
        $request->session()->forget([self::LINK, self::COUPON]);

        return redirect()->away($result['url']);
    }

    public function pending(string $locale, string $reference): View
    {
        $checkout = $this->own($reference);

        return $this->view->render('store.pending', $locale, __('checkout.pending.title'), [
            'checkout' => $checkout,
            'order' => $checkout->order,
            'payUrl' => $checkout->status === CheckoutStatus::PendingPayment && $checkout->payment_id !== null
                ? GatewaySession::query()->where('payment_id', $checkout->payment_id)->value('checkout_url')
                : null,
        ], false);
    }

    public function cancel(string $locale, string $reference): RedirectResponse
    {
        $checkout = $this->own($reference);
        try {
            $this->checkouts->abandon($checkout);
        } catch (GatewayUnavailable) {
            return back()->withErrors(['checkout' => __('checkout.validation.try_later')]);
        }

        return redirect()->route('store.checkout.pending', ['locale' => $locale, 'reference' => $checkout->reference]);
    }

    /**
     * Only the signed-in buyer's own checkout; anything else is 404 (customer isolation).
     */
    private function own(string $reference): StoreCheckout
    {
        $customer = PortalSession::customer() ?? abort(404);
        if (! Str::isUuid($reference)) {
            abort(404);
        }

        return StoreCheckout::query()->where('reference', $reference)->where('customer_id', $customer->id)->with('order')->first() ?? abort(404);
    }

    private function link(Request $request): ?StoreLink
    {
        $id = $request->session()->get(self::LINK);
        if (! is_int($id)) {
            return null;
        }
        $link = StoreLink::query()->find($id);
        if ($link === null || ! $link->status->isOpen() || $link->isLapsed()) {
            $request->session()->forget(self::LINK);

            return null;
        }

        return $link;
    }

    /**
     * The basket behind a link (SAL-04): shown without any customer data; opening it reserves nothing.
     */
    public function openLink(Request $request, string $locale, string $token): View|RedirectResponse
    {
        $this->gate->abortUnlessOpen();
        $link = $this->links->open($token) ?? abort(404);
        $request->session()->put(self::LINK, $link->id);

        return redirect()->route('store.checkout', ['locale' => $locale]);
    }
}
