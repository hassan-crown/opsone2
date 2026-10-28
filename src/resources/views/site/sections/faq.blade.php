<section class="mx-auto max-w-reading px-4 py-12 md:px-8" @if ($data['heading'] !== '') aria-labelledby="{{ $sectionId }}-h" @endif>
    @if ($data['heading'] !== '')<h2 id="{{ $sectionId }}-h" class="text-title">{{ $data['heading'] }}</h2>@endif
    <div class="mt-6 divide-y divide-stroke-weak rounded-lg border border-stroke-weak bg-surface">
        @foreach ($data['items'] as $item)
            <details class="group p-4">
                <summary class="cursor-pointer list-none text-subtitle font-medium text-strong">{{ $item['question'] }}</summary>
                <p class="mt-2 text-body text-weak">{{ $item['answer'] }}</p>
            </details>
        @endforeach
    </div>
</section>
