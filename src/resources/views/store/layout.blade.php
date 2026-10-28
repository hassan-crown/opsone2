{{-- Store surface (ADR-0023): same document, header and theme as the public site (site/page.blade.php), plus the cart and account links. --}}
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}" data-theme="{{ in_array($appearance, ['dark', 'contrast'], true) ? $appearance : 'light' }}" @class(['dark' => $appearance === 'dark'])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <meta name="robots" content="{{ $robots }}">
    @stack('head')
    @if ($favicon)
        <link rel="icon" href="{{ $favicon }}" type="image/png">
    @else
        <link rel="icon" href="/favicon.ico" sizes="any">
    @endif
    <script>
        (function () {
            var a = '{{ $appearance }}';
            if (a !== 'system') { return; }
            var dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            var more = window.matchMedia('(prefers-contrast: more)').matches;
            var t = more && !dark ? 'contrast' : dark ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', t);
            document.documentElement.classList.toggle('dark', t === 'dark');
        })();
    </script>
    <style>html{background-color:#fff}html.dark{background-color:#08121f}</style>
    @vite(['resources/css/site.css'])
    @if ($themeCss !== '')
        <style>{!! $themeCss !!}</style>
    @endif
</head>
<body data-surface="site" class="bg-background text-strong min-h-dvh flex flex-col">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:start-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-brand focus:px-4 focus:py-2 focus:text-on-brand">{{ __('site.public.skip') }}</a>

    <header class="border-b border-stroke-weak bg-surface">
        <div class="mx-auto flex max-w-content flex-wrap items-center justify-between gap-4 px-4 py-3 md:px-8">
            <a href="{{ $homePath }}" class="flex items-center gap-3 text-subtitle font-semibold text-strong">
                @if ($logo)
                    @if ($logoDark)
                        <img src="{{ $logo }}" alt="{{ $siteName }}" class="h-10 w-auto dark:hidden">
                        <img src="{{ $logoDark }}" alt="{{ $siteName }}" class="hidden h-10 w-auto dark:block">
                    @else
                        <img src="{{ $logo }}" alt="{{ $siteName }}" class="h-10 w-auto dark:rounded-md dark:bg-white dark:p-1">
                    @endif
                @else
                    <span>{{ $siteName }}</span>
                @endif
            </a>
            <nav aria-label="{{ __('site.public.nav') }}" class="flex flex-wrap items-center gap-x-6 gap-y-2 text-control">
                @foreach ($nav as $item)
                    <a class="text-strong hover:text-link underline-offset-4 hover:underline" href="{{ $item['path'] }}">{{ $item['title'] }}</a>
                @endforeach
                <a class="text-strong hover:text-link underline-offset-4 hover:underline" href="{{ route('store.index', ['locale' => $locale]) }}">{{ __('store.public.title') }}</a>
                <a class="inline-flex items-center gap-2 text-strong hover:text-link underline-offset-4 hover:underline" href="{{ route('store.cart', ['locale' => $locale]) }}">
                    {{ __('store.public.cart') }}
                    <span class="inline-flex min-w-6 justify-center rounded-full bg-brand px-2 text-caption text-on-brand" aria-label="{{ __('store.public.cart_count', ['count' => $cartCount]) }}">{{ $cartCount }}</span>
                </a>
                <a class="text-strong hover:text-link underline-offset-4 hover:underline" href="{{ url('/portal') }}">{{ $customerName ? __('store.public.my_account') : __('store.public.sign_in') }}</a>
                <a class="text-link underline-offset-4 hover:underline" href="{{ $switchUrl }}" hreflang="{{ $otherLocale }}" lang="{{ $otherLocale }}">{{ $otherLocale === 'ar' ? 'العربية' : 'English' }}</a>
            </nav>
        </div>
    </header>

    <main id="main" class="flex-1">
        @if (session('status'))
            <div role="status" class="mx-auto mt-4 max-w-content px-4 md:px-8"><p class="rounded-md border border-stroke bg-success-bg px-4 py-3 text-success-text">{{ session('status') }}</p></div>
        @endif
        @yield('content')
    </main>

    <footer class="border-t border-stroke-weak bg-surface">
        <div class="mx-auto flex max-w-content flex-wrap items-center justify-between gap-4 px-4 py-6 text-caption text-weak md:px-8">
            <div>
                <p class="font-medium text-strong">{{ $siteName }}</p>
                @if ($contact['phone'])<p dir="ltr" class="text-start">{{ $contact['phone'] }}</p>@endif
                @if ($contact['email'])<p>{{ $contact['email'] }}</p>@endif
            </div>
            <p>{{ __('site.public.by_opsone') }}</p>
        </div>
    </footer>
</body>
</html>
