<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}" data-theme="{{ in_array($appearance, ['dark', 'contrast'], true) ? $appearance : 'light' }}" @class(['dark' => $appearance === 'dark'])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seo['title'] }}</title>
    <meta name="description" content="{{ $seo['description'] }}">
    <meta name="robots" content="{{ $seo['robots'] }}">
    <link rel="canonical" href="{{ $seo['canonical'] }}">
    @foreach ($seo['alternates'] as $hreflang => $href)
        <link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $href }}">
    @endforeach
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $seo['site_name'] }}">
    <meta property="og:title" content="{{ $seo['title'] }}">
    <meta property="og:description" content="{{ $seo['description'] }}">
    <meta property="og:url" content="{{ $seo['canonical'] }}">
    <meta property="og:locale" content="{{ $locale === 'ar' ? 'ar_SA' : 'en_US' }}">
    @if ($seo['image'])
        <meta property="og:image" content="{{ url($seo['image']) }}">
    @endif
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

    @if ($preview)
        <div role="status" class="bg-highlight text-on-highlight px-4 py-2 text-center text-label">{{ __('site.public.preview') }}</div>
    @endif

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
                @if ($switchUrl)
                    <a class="text-link underline-offset-4 hover:underline" href="{{ $switchUrl }}" hreflang="{{ $otherLocale }}" lang="{{ $otherLocale }}">{{ $otherLocale === 'ar' ? 'العربية' : 'English' }}</a>
                @endif
                <button type="button" id="theme-toggle" class="rounded-md border border-stroke-control px-3 py-1 text-label text-strong hover:bg-hover" data-light="{{ __('site.public.theme_dark') }}" data-dark="{{ __('site.public.theme_light') }}">{{ __('site.public.theme_dark') }}</button>
            </nav>
        </div>
    </header>

    <main id="main" class="flex-1">
        @forelse ($sections as $section)
            @include('site.sections.'.$section['type'], ['data' => $section['data'], 'sectionId' => $section['id']])
        @empty
            <div class="mx-auto max-w-reading px-4 py-16 md:px-8"><h1 class="text-title">{{ $version->title }}</h1></div>
        @endforelse
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

    <script>
        (function () {
            var b = document.getElementById('theme-toggle');
            if (!b) { return; }
            var root = document.documentElement;
            function label() { b.textContent = root.classList.contains('dark') ? b.dataset.dark : b.dataset.light; }
            label();
            b.addEventListener('click', function () {
                var dark = !root.classList.contains('dark');
                root.classList.toggle('dark', dark);
                root.setAttribute('data-theme', dark ? 'dark' : 'light');
                document.cookie = 'appearance=' + (dark ? 'dark' : 'light') + ';path=/;max-age=31536000;samesite=lax';
                label();
            });
        })();
    </script>
</body>
</html>
