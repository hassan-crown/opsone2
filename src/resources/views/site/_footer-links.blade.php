{{-- Footer links of the public surface (ADR-0045): the facility's profiles, Google review, published policy pages and
     the cookie settings (only when tags are configured). --}}
@if ($marketing['profiles'] !== [] || $marketing['review'] || $marketing['policies'] !== [] || $marketing['tracking'])
    <nav aria-label="{{ __('site.public.footer_links') }}" class="flex flex-wrap items-center gap-x-4 gap-y-2">
        @foreach ($marketing['profiles'] as $key => $url)
            <a class="hover:text-link hover:underline" href="{{ $url }}" rel="noopener me" target="_blank">{{ __('marketing.link.'.$key) }}</a>
        @endforeach
        @if ($marketing['review'])
            <a class="hover:text-link hover:underline" href="{{ $marketing['review'] }}" rel="noopener" target="_blank">{{ __('site.public.review_us') }}</a>
        @endif
        @foreach ($marketing['policies'] as $key => $path)
            <a class="hover:text-link hover:underline" href="{{ $path }}">{{ __('site.public.policy_'.$key) }}</a>
        @endforeach
        @if ($marketing['tracking'])
            <button type="button" class="hover:text-link hover:underline" data-consent-open>{{ __('site.consent.settings') }}</button>
        @endif
    </nav>
@endif
