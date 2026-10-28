@extends('store.layout')

@section('content')
<section class="mx-auto grid max-w-content gap-8 px-4 py-10 md:grid-cols-2 md:px-8" aria-labelledby="product-h">
    <div class="flex aspect-square items-center justify-center rounded-lg bg-brand-subtle text-display text-brand" aria-hidden="true">{{ mb_substr($product['name'], 0, 1) }}</div>
    <div>
        <p class="text-caption"><a class="text-link hover:underline" href="{{ route('store.index', ['locale' => $locale]) }}">{{ __('store.public.back') }}</a></p>
        <h1 id="product-h" class="mt-2 text-title">{{ $product['name'] }}</h1>
        @if ($product['description'] !== '')<p class="mt-3 text-body text-weak">{{ $product['description'] }}</p>@endif

        <form method="post" action="{{ route('store.cart.add', ['locale' => $locale]) }}" class="mt-6 space-y-4">
            @csrf
            <fieldset>
                <legend class="text-label font-medium">{{ __('store.public.choose_variant') }}</legend>
                <div class="mt-2 space-y-2">
                    @foreach ($product['variants'] as $i => $v)
                        <label class="flex min-h-11 cursor-pointer items-center justify-between gap-3 rounded-md border border-stroke px-3 py-2 has-[:checked]:border-brand has-[:disabled]:opacity-60">
                            <span class="flex items-center gap-3">
                                <input type="radio" name="variant_id" value="{{ $v['id'] }}" @checked($i === 0 && $v['available'] > 0) @disabled($v['available'] < 1) required>
                                <span>{{ $v['name'] }}</span>
                            </span>
                            <span class="text-end">
                                <span class="block font-medium">{{ ($money)($v['price_minor']) }}</span>
                                <span class="block text-caption {{ $v['available'] > 0 ? 'text-success-text' : 'text-danger-text' }}">{{ $v['available'] > 0 ? __('store.public.available', ['count' => $v['available']]) : __('store.public.out_of_stock') }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <div>
                <label for="quantity" class="block text-label font-medium">{{ __('store.public.quantity') }}</label>
                <input id="quantity" name="quantity" type="number" min="1" max="99" value="1" inputmode="numeric" class="field-control mt-1 w-28">
            </div>
            @error('variant_id')<p class="text-caption text-danger-text">{{ $message }}</p>@enderror
            <button type="submit" class="inline-flex min-h-11 items-center rounded-md bg-brand px-6 text-control font-medium text-on-brand hover:bg-brand-hover" @disabled($product['available'] < 1)>{{ __('store.public.add_to_cart') }}</button>
        </form>
    </div>
</section>
@endsection
