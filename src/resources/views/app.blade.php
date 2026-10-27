@php
    $locale = app()->getLocale();
    $appearance = $appearance ?? 'system';
    $theme = $appearance === 'system' ? 'light' : $appearance;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ \App\Http\Middleware\SetLocale::direction($locale) }}" data-theme="{{ $theme }}" @class(['dark' => $theme === 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

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

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body data-surface="panel">
        <x-inertia::app />
    </body>
</html>
