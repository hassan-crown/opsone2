{{-- Store showcase (ADR-0023): the store's ready goods; rendered only when the license allows ready goods and the store sells. --}}
<section class="mx-auto max-w-content px-4 py-12 md:px-8" aria-labelledby="{{ $sectionId }}-h" data-slot="ready-goods">
    <h2 id="{{ $sectionId }}-h" class="text-title">{{ $data['heading'] }}</h2>
    @if ($data['text'] !== '')<p class="mt-2 text-body text-weak">{{ $data['text'] }}</p>@endif
    @if (($storeOpen ?? false) && ($goods ?? []) !== [])
        @php($money = fn (int $minor) => \App\Store\StoreView::money($minor, $locale))
        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($goods as $product)
                @include('store._product-card', ['product' => $product])
            @endforeach
        </div>
        <a href="{{ route('store.index', ['locale' => $locale]) }}" class="mt-6 inline-flex min-h-11 items-center rounded-md border border-stroke-control px-5 text-control hover:bg-hover">{{ __('store.public.view_all') }}</a>
    @else
        <p class="mt-6 rounded-lg border border-dashed border-stroke bg-surface p-6 text-center text-weak">{{ __('site.public.ready_goods_soon') }}</p>
    @endif
</section>
