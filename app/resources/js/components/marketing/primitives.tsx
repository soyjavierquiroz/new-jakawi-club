// Selective local adaptation of official marketing/primitives.tsx and offer/blocks.tsx.
import type { ReactNode } from 'react';
import { Arrow } from '../UI';
import { trackMarketing } from './analytics';
import { focusRegistration } from './registration-navigation';

export function MarketingCTA({ location, children = 'QUIERO SER PARTE', className = '' }: { location: 'hero' | 'benefits' | 'final'; children?: ReactNode; className?: string }) {
    return <a href="#registro" className={`btn btn-primary ${className}`} data-marketing-cta={location} onClick={event => { if (focusRegistration('registro', event)) trackMarketing('cta_click', location); }}>{children}<Arrow /></a>;
}

export function MarketingHero({ children, open }: { children: ReactNode; open: boolean }) {
    return <section className="conversion-hero shell" aria-labelledby="hero-title">
        <div className="hero-heading">
            <p className="eyebrow hero-eyebrow"><span aria-hidden="true" className="size-2 rounded-chip bg-brand" /> COCHABAMBA / PRELANZAMIENTO</p>
            <h1 id="hero-title" className="conversion-title">Tu ciudad tiene <span className="hero-emphasis">mucho más</span> para vivir.</h1>
        </div>
        <div id="presentacion" className="hero-media">{children}</div>
        <div className="hero-copy">
            <p className="text-base leading-relaxed text-text-secondary sm:text-lg">Estamos preparando JAKAWI, una nueva forma de descubrir experiencias, lugares y oportunidades para disfrutar más de Cochabamba.</p>
            <p className="mt-4 text-xs font-extrabold tracking-widest">JAKAWI — VIVE MÁS. GASTA MENOS.</p>
            <MarketingCTA location="hero" className="mt-5" />
            <p className="mt-3 text-xs text-text-muted">{open ? 'Registro gratuito en esta etapa.' : 'Estamos preparando la apertura. Los registros aún están cerrados.'}</p>
        </div>
    </section>;
}

export function MarketingBenefits() {
    const items = [
        ['Estar desde el comienzo', 'Conoce JAKAWI desde sus primeras etapas y descubre lo que estamos preparando para tu ciudad.'],
        ['Saber cuándo encontrarnos', 'Encuentra aquí la información de los próximos encuentros cuando sus fechas estén confirmadas.'],
        ['Una comunidad por descubrir', 'Participa en la comunidad cuando esté disponible. Un espacio para acompañar juntos el comienzo de JAKAWI.'],
        ['Invitar a alguien más', 'Una vez activada tu cuenta, tendrás un enlace personal para compartir tu invitación.'],
    ];
    return <section id="como-funciona" className="shell section-space" aria-labelledby="benefits-title">
        <div className="max-w-2xl"><p className="eyebrow">EL COMIENZO ES CONTIGO</p><h2 id="benefits-title" className="section-title mt-4">Las mejores historias<br />empiezan siendo parte.</h2><p className="mt-5 leading-7 text-text-secondary">Esto es lo que podrás hacer al sumarte, cuando abramos los registros.</p></div>
        <div className="mt-9 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">{items.map(([title, description], index) => <article key={title} className="feature-card"><span className="benefit-number" aria-hidden="true">0{index + 1}</span><h3 className="mb-3 mt-5 text-lg font-extrabold tracking-tight">{title}</h3><p className="text-sm leading-7 text-text-secondary">{description}</p></article>)}</div>
    </section>;
}

export function MarketingFAQ() {
    const items = [
        ['¿Qué es JAKAWI?', 'JAKAWI es una comunidad en preparación que empieza en Cochabamba. Este espacio permite conocer el lanzamiento, crear tu cuenta cuando abramos los registros e invitar a otras personas.'],
        ['¿Registrarse tiene costo?', 'El registro y la membresía de esta etapa son gratuitos. No hay pagos, compras ni comisiones disponibles en esta etapa.'],
        ['¿Qué pasa después de registrarme?', 'Cuando los registros estén abiertos, podrás completar este formulario. Si tu cuenta es nueva, recibirás un correo para activarla y establecer tu contraseña. Después podrás ingresar a tu cuenta. Por ahora, los registros están cerrados.'],
        ['¿Cómo funcionan las invitaciones?', 'Al activar tu cuenta tendrás un enlace personal para compartir. Si alguien se registra con una invitación válida, se conserva su primer referente. En tu cuenta podrás ver el número de personas que invitaste, sin sus datos personales.'],
        ['¿Qué información recibiré?', 'El correo se utiliza para activar la cuenta y recuperar el acceso. Aquí publicaremos las novedades del lanzamiento, los próximos encuentros y el acceso a la comunidad cuando estén confirmados.'],
    ];
    return <section className="shell section-space" aria-labelledby="faq-title"><div className="grid gap-8 lg:grid-cols-[1fr_1.4fr]"><div><p className="eyebrow">TODO CLARO, DESDE EL INICIO</p><h2 id="faq-title" className="section-title mt-4">Antes de<br />dar el paso.</h2></div><div>{items.map(([question, answer]) => <details key={question} className="faq-item"><summary>{question}<span aria-hidden="true">+</span></summary><p className="pb-6 pr-5 text-sm leading-7 text-text-secondary">{answer}</p></details>)}</div></div></section>;
}

export function MarketingFinalCTA() {
    return <section className="final-cta" aria-labelledby="final-title"><div className="shell py-14 text-center sm:py-18"><p className="eyebrow">TU CIUDAD. TU PRÓXIMO CAPÍTULO.</p><h2 id="final-title" className="section-title mx-auto mt-5 max-w-3xl">Hay mucho por vivir.<br />Empecemos juntos.</h2><p className="mx-auto mt-5 max-w-lg leading-7">Sé parte del comienzo de JAKAWI en Cochabamba.</p><MarketingCTA location="final" className="mt-7" /><p className="mt-4 text-xs">VIVE MÁS. GASTA MENOS.</p></div></section>;
}
