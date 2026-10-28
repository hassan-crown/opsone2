@extends('store.layout')

@section('content')
<div class="mx-auto max-w-reading space-y-6 px-4 py-8 md:px-8">
    <h1 class="text-title">{{ __('checkout.title') }}</h1>
    @if ($fromLink)
        <p class="rounded-md border border-stroke bg-brand-subtle px-4 py-3 text-body">{{ __('checkout.from_link', ['until' => $linkExpires?->timezone(config('app.timezone'))->format('Y-m-d H:i')]) }}</p>
    @endif
    @error('lines')<p role="alert" class="rounded-md bg-danger-bg px-4 py-3 text-danger-text">{{ $message }}</p>@enderror
    @error('payment')<p role="alert" class="rounded-md bg-danger-bg px-4 py-3 text-danger-text">{{ $message }}</p>@enderror

    {{-- 1. Items --}}
    <section aria-labelledby="c-items" class="rounded-lg border border-stroke-weak bg-surface p-4">
        <h2 id="c-items" class="text-subtitle font-semibold">1. {{ __('checkout.items') }}</h2>
        <ul class="mt-3 divide-y divide-stroke-weak">
            @foreach ($pricing['lines'] as $row)
                <li class="flex justify-between gap-3 py-2">
                    <span>{{ $names[$row['variant_id']] ?? '' }} × {{ $row['quantity'] }}</span>
                    <span class="text-end">
                        <span class="block">{{ ($money)($row['amount_minor']) }}</span>
                        @if ($row['discount_minor'] > 0)<span class="block text-caption text-success-text">{{ __('checkout.line_discount', ['amount' => ($money)($row['discount_minor'])]) }}</span>@endif
                    </span>
                </li>
            @endforeach
        </ul>
        @if (! $fromLink)
            <a class="mt-2 inline-block text-caption text-link hover:underline" href="{{ route('store.cart', ['locale' => $locale]) }}">{{ __('checkout.edit_cart') }}</a>
        @endif
    </section>

    {{-- 2. Identity (Q-48 a) --}}
    <section aria-labelledby="c-who" class="rounded-lg border border-stroke-weak bg-surface p-4">
        <h2 id="c-who" class="text-subtitle font-semibold">2. {{ __('checkout.identity') }}</h2>
        @if ($customer)
            <p class="mt-2">{{ __('checkout.signed_in_as', ['name' => $customer->name]) }}</p>
        @elseif ($buyer)
            <p class="mt-2 text-body text-weak">{{ __('checkout.otp.sent', ['to' => $buyer['hint'] ?? '']) }}</p>
            <form method="post" action="{{ route('store.checkout.verify', ['locale' => $locale]) }}" class="mt-3 flex flex-wrap items-end gap-3">
                @csrf
                <div>
                    <label for="code" class="block text-label font-medium">{{ __('checkout.otp.code') }}</label>
                    <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="field-control mt-1 w-40 text-start" dir="ltr" required>
                </div>
                <button class="min-h-11 rounded-md bg-brand px-5 text-control font-medium text-on-brand hover:bg-brand-hover">{{ __('checkout.otp.verify') }}</button>
            </form>
            @error('code')<p class="mt-2 text-caption text-danger-text">{{ $message }}</p>@enderror
            <form method="post" action="{{ route('store.checkout.code', ['locale' => $locale]) }}" class="mt-2">
                @csrf
                <input type="hidden" name="mobile" value="{{ $buyer['mobile'] }}"><input type="hidden" name="name" value="{{ $buyer['name'] }}"><input type="hidden" name="email" value="{{ $buyer['email'] }}">
                <button class="text-caption text-link hover:underline">{{ __('checkout.otp.resend') }}</button>
            </form>
        @else
            <p class="mt-2 text-body text-weak">{{ __('checkout.otp.lead') }}</p>
            <form method="post" action="{{ route('store.checkout.code', ['locale' => $locale]) }}" class="mt-3 space-y-3">
                @csrf
                <div>
                    <label for="name" class="block text-label font-medium">{{ __('checkout.otp.name') }}</label>
                    <input id="name" name="name" value="{{ old('name') }}" autocomplete="name" class="field-control mt-1 w-full" required>
                    @error('name')<p class="mt-1 text-caption text-danger-text">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="mobile" class="block text-label font-medium">{{ __('checkout.otp.mobile') }}</label>
                    <input id="mobile" name="mobile" value="{{ old('mobile') }}" inputmode="tel" autocomplete="tel" dir="ltr" class="field-control mt-1 w-full text-start" placeholder="05XXXXXXXX" required>
                    @error('mobile')<p class="mt-1 text-caption text-danger-text">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="email" class="block text-label font-medium">{{ __('checkout.otp.email') }}</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" dir="ltr" class="field-control mt-1 w-full text-start">
                    <p class="mt-1 text-caption text-weak">{{ __('checkout.otp.email_hint') }}</p>
                    @error('email')<p class="mt-1 text-caption text-danger-text">{{ $message }}</p>@enderror
                </div>
                <button class="min-h-11 rounded-md bg-brand px-5 text-control font-medium text-on-brand hover:bg-brand-hover">{{ __('checkout.otp.send') }}</button>
            </form>
        @endif
    </section>

    {{-- 3. Pickup (WEB-07: branch pickup; delivery zones are not enabled) --}}
    <section aria-labelledby="c-pickup" class="rounded-lg border border-stroke-weak bg-surface p-4">
        <h2 id="c-pickup" class="text-subtitle font-semibold">3. {{ __('checkout.pickup') }}</h2>
        <p class="mt-2">{{ __('checkout.pickup_from', ['branch' => $branch]) }}</p>
    </section>

    {{-- 4. Coupon and payment --}}
    <section aria-labelledby="c-pay" class="rounded-lg border border-stroke-weak bg-surface p-4">
        <h2 id="c-pay" class="text-subtitle font-semibold">4. {{ __('checkout.payment') }}</h2>
        <form method="post" action="{{ route('store.checkout.coupon', ['locale' => $locale]) }}" class="mt-3 flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label for="coupon" class="block text-label font-medium">{{ __('checkout.coupon') }}</label>
                <input id="coupon" name="coupon" value="{{ $couponCode }}" dir="ltr" maxlength="32" class="field-control mt-1 w-48 text-start uppercase">
            </div>
            <button class="min-h-11 rounded-md border border-stroke-control px-4 text-control hover:bg-hover">{{ __('checkout.apply') }}</button>
        </form>
        @if ($couponError)<p class="mt-2 text-caption text-danger-text" role="alert">{{ $couponError }}</p>@endif
        @if ($coupon)<p class="mt-2 text-caption text-success-text">{{ __('checkout.coupon_applied', ['code' => $coupon->code]) }}</p>@endif

        <dl class="mt-4 space-y-1 text-body">
            <div class="flex justify-between"><dt>{{ __('checkout.subtotal') }}</dt><dd>{{ ($money)($pricing['totals']['subtotal_minor']) }}</dd></div>
            @if ($pricing['totals']['discount_minor'] > 0)
                <div class="flex justify-between text-success-text"><dt>{{ __('checkout.discount') }}</dt><dd>− {{ ($money)($pricing['totals']['discount_minor']) }}</dd></div>
            @endif
            <div class="flex justify-between text-weak"><dt>{{ __('checkout.vat', ['rate' => $pricing['vat']['rate_bp'] / 100]) }}</dt><dd>{{ ($money)($pricing['totals']['vat_minor']) }}</dd></div>
            <div class="flex justify-between border-t border-stroke-weak pt-2 text-subtitle font-semibold"><dt>{{ __('checkout.total') }}</dt><dd>{{ ($money)($pricing['totals']['total_minor']) }}</dd></div>
        </dl>

        <form method="post" action="{{ route('store.checkout.start', ['locale' => $locale]) }}" class="mt-4">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
            <p class="text-caption text-weak">{{ __('checkout.hold_note', ['minutes' => $minutes]) }}</p>
            <button class="mt-3 inline-flex min-h-11 w-full items-center justify-center rounded-md bg-brand px-6 text-control font-medium text-on-brand hover:bg-brand-hover disabled:opacity-60" @disabled(! $customer)>{{ __('checkout.pay') }}</button>
            @unless ($customer)<p class="mt-2 text-caption text-weak">{{ __('checkout.validation.verify_first') }}</p>@endunless
        </form>
    </section>
</div>
@endsection
