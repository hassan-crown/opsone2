<section class="mx-auto max-w-content px-4 py-12 md:px-8" @if ($data['heading'] !== '') aria-labelledby="{{ $sectionId }}-h" @endif>
    @if ($data['heading'] !== '')<h2 id="{{ $sectionId }}-h" class="text-title">{{ $data['heading'] }}</h2>@endif
    <ul class="mt-8 grid grid-cols-2 gap-4 md:grid-cols-3">
        @foreach ($data['images'] as $image)
            <li>
                <figure>
                    <img src="{{ ($media)($image['image']) }}" alt="{{ $image['caption'] }}" class="aspect-4/3 w-full rounded-lg object-cover" loading="lazy">
                    @if ($image['caption'] !== '')<figcaption class="mt-2 text-caption text-weak">{{ $image['caption'] }}</figcaption>@endif
                </figure>
            </li>
        @endforeach
    </ul>
</section>
