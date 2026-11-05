{{-- End of every public document (ADR-0045): the floating WhatsApp button and, with tags configured, the PDPL consent
     banner (hidden until the loader finds no stored choice). --}}
@if ($marketing['whatsapp'])
    <a href="{{ $marketing['whatsapp'] }}" target="_blank" rel="noopener" data-track="click_whatsapp"
       class="fixed bottom-4 end-4 z-40 inline-flex size-14 items-center justify-center rounded-full bg-[#25D366] text-white shadow-lg hover:brightness-95 focus-visible:outline-2 focus-visible:outline-offset-2"
       aria-label="{{ __('site.public.whatsapp') }}">
        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-7" fill="currentColor"><path d="M12.04 2a9.9 9.9 0 0 0-8.5 15l-1.4 5.1 5.2-1.4A9.9 9.9 0 1 0 12.04 2Zm0 18.1a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3.1.8.8-3-.2-.3a8.2 8.2 0 1 1 7 3.9Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.1 5.1 0 0 0 1.1 2.7 11.7 11.7 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.2-.2-.5-.3Z"/></svg>
    </a>
@endif
@if ($marketing['tracking'])
    <section id="consent-banner" hidden role="dialog" aria-modal="false" aria-labelledby="consent-h"
             class="fixed inset-x-0 bottom-0 z-50 border-t border-stroke bg-surface p-4 shadow-lg md:inset-x-auto md:bottom-4 md:start-4 md:max-w-md md:rounded-lg md:border">
        <h2 id="consent-h" class="text-subtitle font-semibold text-strong">{{ __('site.consent.title') }}</h2>
        <p class="mt-2 text-caption text-weak">
            {{ __('site.consent.text') }}
            @if (isset($marketing['policies']['privacy']))<a class="text-link underline" href="{{ $marketing['policies']['privacy'] }}">{{ __('site.public.policy_privacy') }}</a>@endif
        </p>
        <div id="consent-choices" hidden class="mt-3 grid gap-2 text-control">
            <label class="flex items-center gap-2"><input type="checkbox" checked disabled> {{ __('site.consent.necessary') }}</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="analytics"> {{ __('site.consent.analytics') }}</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="marketing"> {{ __('site.consent.marketing') }}</label>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            <button type="button" data-consent="all" class="inline-flex min-h-11 items-center rounded-md bg-brand px-4 text-control font-medium text-on-brand hover:bg-brand-hover">{{ __('site.consent.accept_all') }}</button>
            <button type="button" data-consent="necessary" class="inline-flex min-h-11 items-center rounded-md border border-stroke-control px-4 text-control text-strong hover:bg-hover">{{ __('site.consent.necessary_only') }}</button>
            <button type="button" data-consent="customize" class="inline-flex min-h-11 items-center rounded-md px-4 text-control text-link hover:underline">{{ __('site.consent.customize') }}</button>
            <button type="button" data-consent="save" hidden class="inline-flex min-h-11 items-center rounded-md border border-stroke-control px-4 text-control text-strong hover:bg-hover">{{ __('site.consent.save') }}</button>
        </div>
    </section>
@endif
