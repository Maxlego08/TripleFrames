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

        {{-- Canonique, description et Open Graph génériques (spec 90 § 11.5,
             D66 du 07/10), rendus ICI parce qu'aucun SSR n'existe : un robot
             ou l'aperçu d'une messagerie ne lit que ce HTML. Hors de
             `<x-inertia::head>`, que le client réécrit. La canonique n'existe
             que sur une route indexable (`RobotsDirectives::routeIndexable()`) ;
             les balises `<meta>` sont les mêmes partout, sans image ni
             `room_code`, ni titre de film, ni pseudo (règle 3). --}}
        @inject('pageMeta', 'App\Support\Http\PageMeta')
        @php($canonical = $pageMeta->canonical(request()))
        @if ($canonical !== null)
            <link rel="canonical" href="{{ $canonical }}">
        @endif
        @foreach ($pageMeta->tags($canonical) as $tag)
            <meta {{ $tag['attribute'] }}="{{ $tag['key'] }}" content="{{ $tag['content'] }}">
        @endforeach

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
