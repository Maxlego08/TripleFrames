<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark']) @if($appearanceForced ?? false) data-appearance-forced="{{ $appearance }}" @endif>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- `data-appearance-forced` ci-dessus n'est posé que par la moitié serveur
             d'un forçage d'apparence, c'est-à-dire un middleware de route qui
             partage `appearanceForced`. Depuis D8 du 23/09, le back-office n'en a
             plus : il suit la préférence du visiteur. Le seul forçage prévu est
             celui des pages `game/*`, en sombre (spec 90 § 2.2). L'attribut dit au
             boot client que la page force son apparence, pour
             qu'`initializeTheme()` n'aille pas reposer la préférence stockée
             par-dessus. Le sélecteur d'apparence du site n'est pas concerné : la
             valeur du cookie continue d'arriver par `$appearance`. --}}

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'TripleFrames') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
