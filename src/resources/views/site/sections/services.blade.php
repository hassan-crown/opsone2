<section class="mx-auto max-w-content px-4 py-12 md:px-8" aria-labelledby="{{ $sectionId }}-h">
    <h2 id="{{ $sectionId }}-h" class="text-title">{{ $data['heading'] }}</h2>
    <ul class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($data['items'] as $item)
            <li class="rounded-lg border border-stroke-weak bg-surface p-6">
                <h3 class="text-subtitle">{{ $item['title'] }}</h3>
                @if ($item['text'] !== '')<p class="mt-2 text-body text-weak">{{ $item['text'] }}</p>@endif
            </li>
        @endforeach
    </ul>
</section>
