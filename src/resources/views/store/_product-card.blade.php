<article class="flex flex-col rounded-lg border border-stroke-weak bg-surface p-4" aria-labelledby="product-{{ $product['id'] }}">
    @if ($product['images'] !== [])
        <img src="{{ route('site.media', ['attachment' => $product['images'][0]]) }}" alt="{{ $product['name'] }}" width="400" height="300" loading="lazy" decoding="async" class="aspect-[4/3] w-full rounded-md bg-brand-subtle object-cover">
    @else
        <div class="flex aspect-[4/3] items-center justify-center rounded-md bg-brand-subtle text-display text-brand" aria-hidden="true">{{ mb_substr($product['name'], 0, 1) }}</div>
    @endif
    <h3 id="product-{{ $product['id'] }}" class="mt-3 text-subtitle font-semibold">
        <a class="hover:text-link hover:underline underline-offset-4" href="{{ $product['path'] }}">{{ $product['name'] }}</a>
    </h3>
    <p class="mt-1 text-body">{{ count($product['variants']) > 1 ? __('store.public.from', ['price' => ($money)($product['from_minor'])]) : ($money)($product['from_minor']) }}</p>
    <p class="mt-1 text-caption {{ $product['available'] > 0 ? 'text-success-text' : 'text-danger-text' }}">{{ $product['available'] > 0 ? __('store.public.in_stock') : __('store.public.out_of_stock') }}</p>
</article>
