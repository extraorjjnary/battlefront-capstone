<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    data-authenticated="{{ auth()->check() ? 'true' : 'false' }}"
    data-appearance="{{ $appearance ?? 'light' }}"
    @class(['dark' => ($appearance ?? 'light') === 'dark'])
>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <script data-guest-appearance>
            if (document.documentElement.dataset.authenticated !== 'true') {
                let appearance = 'light';
                try {
                    appearance = localStorage.getItem('battlefront_guest_appearance') === 'dark' ? 'dark' : 'light';
                } catch {}
                document.documentElement.classList.toggle('dark', appearance === 'dark');
                document.documentElement.dataset.appearance = appearance;
            }
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: #faf7f4;
                color-scheme: light;
            }

            html.dark {
                background-color: #090b10;
                color-scheme: dark;
            }
        </style>

        <link rel="icon" href="/favicon.ico?v=battlefront-bct" sizes="16x16 32x32" type="image/x-icon">
        <link rel="icon" href="/favicon.svg?v=battlefront-bct" sizes="any" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=battlefront-bct" sizes="180x180">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.js', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
