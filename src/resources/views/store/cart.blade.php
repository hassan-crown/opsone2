@extends('store.layout')

@section('content')
<section class="mx-auto max-w-reading px-4 py-10 md:px-8" aria-labelledby="cart-h">
    <h1 id="cart-h" class="text-title">{{ __('store.public.cart') }}</h1>
    @if ($lines === [])
        <p class="mt-6 rounded-lg border border-dashed border-stroke bg-surface p-6 text-center text-weak">{{ __('store.public.cart_empty') }}</p>
        <a href="{{ route('store.index', ['locale' => $locale]) }}" class="mt-6 inline-flex min-h-11 items-center rounded-md border border-stroke-control px-5 text-control">{{ __('store.public.browse') }}</a>
    @else
        <ul class="mt-6 divide-y divide-stroke-weak rounded-lg border border-stroke-weak bg-surface">
            @foreach ($lines as $line)
                <li class="flex flex-wrap items-center justify-between gap-3 p-4">
                    <div>
                        <p class="font-medium"><a class="hover:underline" href="{{ route('store.product', ['locale' => $locale, 'product' => $line['product_id']]) }}">{{ $line['name'] }}</a></p>
                        <p class="text-caption text-weak">{{ $line['variant'] }} · {{ ($money)($line['unit_minor']) }}</p>
                        @if ($line['available'] < $line['quantity'])
                            <p class="text-caption text-danger-text">{{ __('store.public.short', ['count' => $line['available']]) }}</p>
                        @endif
                    </div>
                    <form method="post" action="{{ route('store.cart.update', ['locale' => $locale, 'variant' => $line['variant_id']]) }}" class="flex items-center gap-2">
                        @csrf @method('put')
                        <label class="sr-only" for="q-{{ $line['variant_id'] }}">{{ __('store.public.quantity') }}</label>
                        <input id="q-{{ $line['variant_id'] }}" name="quantity" type="number" min="0" max="99" value="{{ $line['quantity'] }}" class="field-control w-20">
                        <button class="min-h-11 rounded-md border border-stroke-control px-3 text-label hover:bg-hover">{{ __('store.public.update') }}</button>
                    </form>
                    <p class="w-full text-end font-medium sm:w-auto">{{ ($money)($line['line_minor']) }}</p>
                </li>
            @endforeach
        </ul>
        <p class="mt-4 text-caption text-weak">{{ __('store.public.cart_note') }}</p>
        <a href="{{ route('store.checkout', ['locale' => $locale, 'basket' => 'cart']) }}" class="mt-6 inline-flex min-h-11 items-center rounded-md bg-brand px-6 text-control font-medium text-on-brand hover:bg-brand-hover">{{ __('store.public.to_checkout') }}</a>
    @endif
</section>
@endsection
