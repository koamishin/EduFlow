<!DOCTYPE html>
@php
    // The palette class is rendered server-side so a hard refresh does not flash
    // the default palette before hydration. 'default' adds no class at all.
    $colorTheme = $colorTheme ?? 'default';
    $htmlClasses = ['dark' => ($appearance ?? 'system') == 'dark'];

    if ($colorTheme !== 'default') {
        $htmlClasses['theme-'.$colorTheme] = true;
    }
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class($htmlClasses)>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Inline script to detect appearance and color theme immediately --}}
    <script>
        (function () {
            try {
                const storedAppearance = localStorage.getItem('appearance');
                const appearance = storedAppearance || '{{ $appearance ?? "system" }}';

                if (appearance === 'dark' || (appearance === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.classList.add('dark');
                    document.documentElement.style.colorScheme = 'dark';
                } else {
                    document.documentElement.classList.remove('dark');
                    document.documentElement.style.colorScheme = 'light';
                }

                const storedTheme = localStorage.getItem('color-theme');
                const serverTheme = '{{ $colorTheme ?? "default" }}';
                const activeTheme = (storedTheme && storedTheme !== 'default') ? storedTheme : (serverTheme !== 'default' ? serverTheme : '');

                const themeClasses = Array.from(document.documentElement.classList).filter(function (c) {
                    return c.startsWith('theme-');
                });
                themeClasses.forEach(function (c) {
                    document.documentElement.classList.remove(c);
                });

                if (activeTheme) {
                    document.documentElement.classList.add('theme-' + activeTheme);
                }
            } catch (e) {}
        })();
    </script>

    {{-- Inline style to set the HTML background color based on active theme --}}
    <style>
        html {
            background-color: var(--background, oklch(1 0 0));
            color: var(--foreground, oklch(0.145 0 0));
        }

        html.dark {
            background-color: var(--background, oklch(0.145 0 0));
            color: var(--foreground, oklch(0.98 0 0));
        }
    </style>

    <title inertia>{{ config('app.name', 'Laravel') }}</title>

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />


    @viteReactRefresh
    @vite(array_values(array_filter([
        'resources/css/app.css',
        'resources/js/app.tsx',
        file_exists(resource_path("js/pages/{$page['component']}.tsx")) ? "resources/js/pages/{$page['component']}.tsx" : null,
    ])))
    @inertiaHead
</head>

<body class="font-sans antialiased">
    @inertia
</body>

</html>
