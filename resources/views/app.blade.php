<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Tout le site est sombre, sans aucun choix d'apparence (D56 du
             02/10) : la classe `dark` est posée ici, en dur, rendue par le
             serveur, donc sans flash au chargement. Ni cookie, ni script de
             détection de la préférence système. Aucun SSR en v1
             (`config/inertia.php`). --}}

        {{-- Inline style to set the HTML background color based on our dark theme in app.css --}}
        <style>
            html {
                color-scheme: dark;
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
