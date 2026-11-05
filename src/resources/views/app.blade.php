@php
    $locale = app()->getLocale();
    $appearance = $appearance ?? 'system';
    $theme = $appearance === 'system' ? 'light' : $appearance;
    // Q-03 (a, ADR-0035): facility colours on the customer portal only; staff screens keep OpsOne's identity.
    $facilityBrand = \App\Site\Theme\PortalBrand::css(request());
    $fieldApp = request()->routeIs('field.*');
    // Status-bar colour follows the header surface (tokens.css `--surface`) in each scheme, not only the light one.
    $themeLight = $fieldApp ? \App\Http\Controllers\Field\FieldController::THEME : '#ffffff';
    $themeDark = '#0f1d31';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ \App\Http\Middleware\SetLocale::direction($locale) }}" data-theme="{{ $theme }}" @if ($fieldApp) data-app="field" @endif @class(['dark' => $theme === 'dark'])>
    <head>
        <meta charset="utf-8">
        {{-- UI batch D (portal): edge to edge on notched phones; the portal pads its bottom bar by the safe area. --}}
        <meta name="viewport" content="width=device-width, initial-scale=1{{ request()->routeIs('customer.*') ? ', viewport-fit=cover' : '' }}">

        {{-- Resolve "system" (and prefers-contrast) before first paint so there is no flash. --}}
        <script>
            (function () {
                var appearance = '{{ $appearance }}';

                if (appearance !== 'system') {
                    return;
                }

                var dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                var more = window.matchMedia('(prefers-contrast: more)').matches;
                var theme = more && !dark ? 'contrast' : dark ? 'dark' : 'light';
                var root = document.documentElement;

                root.setAttribute('data-theme', theme);
                root.classList.toggle('dark', theme === 'dark');
            })();
        </script>

        {{-- Page background before the stylesheet loads (design-system `background`). --}}
        <style>
            html { background-color: #ffffff; }
            html.dark { background-color: #08121f; }
        </style>

        @if ($appearance === 'dark')
            <meta name="theme-color" content="{{ $themeDark }}">
        @elseif ($appearance !== 'system')
            <meta name="theme-color" content="{{ $themeLight }}">
        @else
            <meta name="theme-color" media="(prefers-color-scheme: light)" content="{{ $themeLight }}">
            <meta name="theme-color" media="(prefers-color-scheme: dark)" content="{{ $themeDark }}">
        @endif

        @if ($fieldApp)
            {{-- Installation field app (ADR-0036): installable, always OpsOne's identity, never the tenant's. --}}
            <link rel="manifest" href="/field/manifest.webmanifest">
            <meta name="mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-title" content="OpsOne">
            <meta name="apple-mobile-web-app-status-bar-style" content="default">
            <link rel="icon" href="/field-icons/icon-192.png" type="image/png">
            <link rel="apple-touch-icon" href="/field-icons/apple-touch-icon.png">
        @else
            <link rel="icon" href="/favicon.ico" sizes="any">
            <link rel="icon" href="/favicon.svg" type="image/svg+xml">
            <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        @endif

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @if ($facilityBrand !== '')
            <style>{!! $facilityBrand !!}</style>
        @endif
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body data-surface="panel" @if ($facilityBrand !== '') data-brand="facility" @endif>
        <x-inertia::app />
    </body>
</html>
