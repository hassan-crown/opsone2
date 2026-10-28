@php($img = ($media)($data['image'] ?? null))
@php($href = ($linkUrl)($data['cta_link'] ?? null))
<section class="bg-brand-subtle" aria-labelledby="{{ $sectionId }}-h">
    <div class="mx-auto grid max-w-content items-center gap-8 px-4 py-16 md:grid-cols-2 md:px-8 md:py-24">
        <div>
            <h1 id="{{ $sectionId }}-h" class="text-display text-strong max-md:text-title">{{ $data['heading'] !== '' ? $data['heading'] : $siteName }}</h1>
            @if ($data['subheading'] !== '')<p class="mt-4 text-subtitle text-weak">{{ $data['subheading'] }}</p>@endif
            @if ($href && $data['cta_label'] !== '')
                <a href="{{ $href }}" class="mt-8 inline-flex min-h-11 items-center rounded-md bg-brand px-6 text-control font-medium text-on-brand hover:bg-brand-hover active:bg-brand-active">{{ $data['cta_label'] }}</a>
            @endif
        </div>
        @if ($img)
            <img src="{{ $img }}" alt="" class="w-full rounded-lg object-cover" loading="eager">
        @endif
    </div>
</section>
