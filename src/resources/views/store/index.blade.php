@extends('store.layout')

@section('content')
<section class="mx-auto max-w-content px-4 py-10 md:px-8" aria-labelledby="store-h">
    <h1 id="store-h" class="text-title">{{ __('store.public.title') }}</h1>
    <p class="mt-2 text-body text-weak">{{ __('store.public.lead') }}</p>
    @if ($products === [])
        <p class="mt-8 rounded-lg border border-dashed border-stroke bg-surface p-6 text-center text-weak">{{ __('store.public.empty') }}</p>
    @else
        <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($products as $product)
                @include('store._product-card', ['product' => $product])
            @endforeach
        </div>
    @endif
</section>
@endsection
