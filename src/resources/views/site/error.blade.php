{{-- 404 / 410 on the public surface (ADR-0045): the site's own chrome, a way home, never indexed. --}}
@extends('site.layout')

@section('content')
    <section class="mx-auto max-w-reading px-4 py-16 md:px-8" aria-labelledby="error-h">
        <p class="text-caption text-weak">{{ $status }}</p>
        <h1 id="error-h" class="mt-2 text-title">{{ __('site.error.'.$status.'.title', [], $locale) }}</h1>
        <p class="mt-3 text-body text-weak">{{ __('site.error.'.$status.'.text', [], $locale) }}</p>
        <p class="mt-6"><a class="inline-flex min-h-11 items-center rounded-md bg-brand px-6 text-control font-medium text-on-brand hover:bg-brand-hover" href="{{ $homePath }}">{{ __('site.error.home', [], $locale) }}</a></p>
    </section>
@endsection
