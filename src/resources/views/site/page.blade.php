@extends('site.layout')

@section('content')
    @forelse ($sections as $section)
        @include('site.sections.'.$section['type'], ['data' => $section['data'], 'sectionId' => $section['id']])
    @empty
        <div class="mx-auto max-w-reading px-4 py-16 md:px-8"><h1 class="text-title">{{ $version->title }}</h1></div>
    @endforelse
@endsection
