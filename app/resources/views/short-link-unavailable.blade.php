<!DOCTYPE html>
<html lang="es" data-marketing-theme="light-conversion">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Oferta {{ $state === 'finished' ? 'finalizada' : 'no disponible' }} | JAKAWI</title>
    <link rel="icon" href="/jakawi-mark.svg" type="image/svg+xml">
    @vite('resources/css/app.css')
</head>
<body>
    <header class="site-header"><div class="shell flex min-h-20 items-center"><a href="/" aria-label="JAKAWI, inicio"><img src="/jakawi-logo.svg" alt="JAKAWI" width="1080" height="431" class="brand-logo"></a></div></header>
    <main class="shell min-h-[65vh] py-12 sm:py-20">
        <article class="feature-card mx-auto max-w-2xl" data-offer-state="{{ $state }}">
            <p class="eyebrow">JAKAWI · OPORTUNIDADES</p>
            <h1 class="mt-4 text-3xl font-extrabold tracking-tight sm:text-5xl">{{ $state === 'finished' ? 'Esta oferta ha finalizado' : ($state === 'missing' ? 'No encontramos este enlace' : ($state === 'upcoming' ? 'Esta oferta estará disponible próximamente' : ($state === 'retired' ? 'Esta oferta fue retirada del catálogo' : 'Esta oferta no está disponible por ahora'))) }}</h1>
            @if($name)<p class="mt-5 font-semibold">{{ $name }}</p>@endif
            @if(!empty($description))<p class="mt-4 text-text-secondary">{{ $description }}</p>@endif
            <p class="mt-4 text-text-secondary">Este enlace conserva su oferta original y su historial.</p>
            <p class="mt-4 text-text-secondary">Puedes explorar otras oportunidades en JAKAWI.COM.</p>
            <a class="btn btn-primary mt-8 w-full sm:w-auto" href="https://jakawi.com/" rel="noreferrer">EXPLORAR OPORTUNIDADES</a>
        </article>
    </main>
    <footer class="border-t border-border"><div class="shell py-8 text-sm text-text-secondary">JAKAWI · VIVE MÁS. GASTA MENOS.</div></footer>
</body>
</html>
