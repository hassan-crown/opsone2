@extends('store.layout')

@push('head')
    @if ($checkout->status->value === 'pending_payment')<meta http-equiv="refresh" content="10">@endif
@endpush

@section('content')
<section class="mx-auto max-w-reading px-4 py-10 md:px-8" aria-labelledby="pending-h">
    <h1 id="pending-h" class="text-title">{{ __('checkout.pending.state.'.$checkout->status->value) }}</h1>
    <p class="mt-2 text-body text-weak">{{ __('checkout.pending.lead.'.$checkout->status->value) }}</p>
    @error('checkout')<p role="alert" class="mt-4 rounded-md bg-danger-bg px-4 py-3 text-danger-text">{{ $message }}</p>@enderror

    <dl class="mt-6 space-y-2 rounded-lg border border-stroke-weak bg-surface p-4">
        <div class="flex justify-between gap-3"><dt class="text-weak">{{ __('checkout.pending.order') }}</dt><dd class="font-medium" dir="ltr">{{ $order->number }}</dd></div>
        <div class="flex justify-between gap-3"><dt class="text-weak">{{ __('checkout.total') }}</dt><dd class="font-medium">{{ ($money)($checkout->total_minor) }}</dd></div>
        @if ($checkout->status->value === 'pending_payment')
            <div class="flex justify-between gap-3"><dt class="text-weak">{{ __('checkout.pending.held_until') }}</dt><dd dir="ltr">{{ $checkout->expires_at->timezone(config('app.timezone'))->format('H:i') }}</dd></div>
        @endif
    </dl>

    <div class="mt-6 flex flex-wrap gap-3">
        @if ($payUrl)
            <a href="{{ $payUrl }}" class="inline-flex min-h-11 items-center rounded-md bg-brand px-6 text-control font-medium text-on-brand hover:bg-brand-hover">{{ __('checkout.pending.continue') }}</a>
            <form method="post" action="{{ route('store.checkout.cancel', ['locale' => $locale, 'reference' => $checkout->reference]) }}">
                @csrf
                <button class="min-h-11 rounded-md border border-stroke-control px-5 text-control hover:bg-hover">{{ __('checkout.pending.cancel') }}</button>
            </form>
        @endif
        <a href="{{ url('/portal/orders/'.$order->id) }}" class="inline-flex min-h-11 items-center rounded-md border border-stroke-control px-5 text-control hover:bg-hover">{{ __('checkout.pending.follow') }}</a>
    </div>
</section>
@endsection
