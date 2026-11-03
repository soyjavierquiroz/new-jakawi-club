<!DOCTYPE html>
<html lang="es" data-marketing-theme="light-conversion" @if(request()->is('mi-cuenta', 'dashboard', 'afiliados')) data-account-context @endif>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @isset($landingSocial)
            @include('social-meta')
        @else
            <meta name="robots" data-inertia="robots" content="noindex, nofollow">
        @endisset
        @if(request()->is('ads/*/activar'))
            <link rel="canonical" data-inertia="canonical" href="{{ rtrim(config('app.url'), '/') }}/activar">
        @endif
        <link rel="icon" href="/jakawi-mark.svg" type="image/svg+xml">
        <script>
            // Resolve CLUB's own preference before styles or content can paint.
            (() => {
                let mode = 'system';
                try { mode = localStorage.getItem('jakawi.club.appearance') || 'system'; } catch {}
                document.documentElement.dataset.accountTheme = mode === 'dark' || (mode !== 'light' && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
            })();
        </script>
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
