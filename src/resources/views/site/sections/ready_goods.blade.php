{{-- Showcase placeholder: the store session fills it with ready goods; it only renders when the license allows ready goods. --}}
<section class="mx-auto max-w-content px-4 py-12 md:px-8" aria-labelledby="{{ $sectionId }}-h" data-slot="ready-goods">
    <h2 id="{{ $sectionId }}-h" class="text-title">{{ $data['heading'] }}</h2>
    @if ($data['text'] !== '')<p class="mt-2 text-body text-weak">{{ $data['text'] }}</p>@endif
    <p class="mt-6 rounded-lg border border-dashed border-stroke bg-surface p-6 text-center text-weak">{{ __('site.public.ready_goods_soon') }}</p>
</section>
