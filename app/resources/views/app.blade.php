<!DOCTYPE html>
<html lang="es" data-marketing-theme="light-conversion">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="JAKAWI. VIVE MÁS. GASTA MENOS. Conoce el lanzamiento y sé parte del comienzo de nuestra comunidad.">
        <meta name="robots" content="noindex, nofollow">
        @isset($landingSocial)
            <meta property="og:type" data-inertia="og:type" content="website">
            <meta property="og:site_name" data-inertia="og:site_name" content="JAKAWI CLUB">
            <meta property="og:title" data-inertia="og:title" content="{{ $landingSocial['name'] }}">
            <meta property="og:description" data-inertia="og:description" content="{{ $landingSocial['short_description'] }}">
            <meta property="og:image" data-inertia="og:image" content="{{ url($landingSocial['image_url'] ?? '/marketing/club-card.png') }}">
            <meta name="twitter:card" data-inertia="twitter:card" content="summary_large_image">
            <meta name="twitter:title" data-inertia="twitter:title" content="{{ $landingSocial['name'] }}">
            <meta name="twitter:description" data-inertia="twitter:description" content="{{ $landingSocial['short_description'] }}">
            <meta name="twitter:image" data-inertia="twitter:image" content="{{ url($landingSocial['image_url'] ?? '/marketing/club-card.png') }}">
        @endisset
        <link rel="icon" href="/jakawi-mark.svg" type="image/svg+xml">
        @viteReactRefresh
        @vite('resources/js/app.tsx')
        <x-inertia::head>
            <title>JAKAWI Launch Engine</title>
        </x-inertia::head>
    </head>
    <body>
        <x-inertia::app />
        <noscript>JAKAWI — VIVE MÁS. GASTA MENOS. Activa JavaScript para acceder a la comunidad. Los registros están en preparación.</noscript>
    </body>
</html>
