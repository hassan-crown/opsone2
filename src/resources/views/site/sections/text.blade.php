<section class="mx-auto max-w-reading px-4 py-12 md:px-8" @if ($data['heading'] !== '') aria-labelledby="{{ $sectionId }}-h" @endif>
    @if ($data['heading'] !== '')<h2 id="{{ $sectionId }}-h" class="text-title">{{ $data['heading'] }}</h2>@endif
    <div class="mt-4 space-y-4 text-body text-strong">
        @foreach (preg_split('/\R{2,}/', $data['body']) as $para)
            <p>{!! nl2br(e($para)) !!}</p>
        @endforeach
    </div>
</section>
