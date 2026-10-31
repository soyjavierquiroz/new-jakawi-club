<!DOCTYPE html>
<html lang="es" data-marketing-theme="light-conversion">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @isset($landingSocial)
            @include('social-meta')
        @else
            <meta name="robots" data-inertia="robots" content="noindex, nofollow">
        @endisset
        <link rel="icon" href="/jakawi-mark.svg" type="image/svg+xml">
        @viteReactRefresh
        @vite('resources/js/app.tsx')
        <x-inertia::head>
            <title>{{ $landingSocial['name'] ?? 'JAKAWI CLUB' }}</title>
        </x-inertia::head>
    </head>
    <body>
        <x-inertia::app />
        <noscript>JAKAWI — VIVE MÁS. GASTA MENOS. Activa JavaScript para acceder a la comunidad. Los registros están en preparación.</noscript>
    </body>
</html>
