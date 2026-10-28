<section class="bg-fill" @if ($data['heading'] !== '') aria-labelledby="{{ $sectionId }}-h" @endif>
    <div class="mx-auto max-w-content px-4 py-12 md:px-8">
        @if ($data['heading'] !== '')<h2 id="{{ $sectionId }}-h" class="text-title">{{ $data['heading'] }}</h2>@endif
        <div class="mt-8 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            @foreach ($data['items'] as $item)
                <figure class="rounded-lg border border-stroke-weak bg-surface p-6">
                    <blockquote class="text-body text-strong">{{ $item['quote'] }}</blockquote>
                    <figcaption class="mt-4 text-label text-weak">{{ $item['author'] }}@if ($item['role'] !== '') — {{ $item['role'] }}@endif</figcaption>
                </figure>
            @endforeach
        </div>
    </div>
</section>
