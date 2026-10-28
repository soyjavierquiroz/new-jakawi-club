<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="JAKAWI Launch Engine. Próximamente en jakawi.club.">
        <meta name="robots" content="noindex, nofollow">
        @viteReactRefresh
        @vite('resources/js/app.tsx')
        <x-inertia::head>
            <title>JAKAWI Launch Engine</title>
        </x-inertia::head>
    </head>
    <body>
        <x-inertia::app />
        <noscript>JAKAWI Launch Engine — Próximamente en jakawi.club</noscript>
    </body>
</html>
