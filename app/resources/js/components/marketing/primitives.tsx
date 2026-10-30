// Selective local adaptation of official marketing/primitives.tsx and offer/blocks.tsx.
import { useContext, type ReactNode } from 'react';
import { Arrow } from '../UI';
import { LandingContext, useLandingTracking } from './LandingContext';
import { focusRegistration } from './registration-navigation';

export const REGISTRATION_CTA = 'QUIERO SER PARTE DE JAKAWI';

export function useRegistrationCTA() {
    const content = useContext(LandingContext)?.content;
    return content?.presentation.template === 'WEBINAR' && content.presentation.webinar ? content.hero.cta : REGISTRATION_CTA;
}

export function MarketingCTA({ location, className = '' }: { location: 'hero' | 'benefits' | 'final'; className?: string }) {
    const trackMarketing = useLandingTracking();
    const label = useRegistrationCTA();
    return <a href="#registro" className={`btn btn-primary ${className}`} data-marketing-cta={location} onClick={event => { if (focusRegistration('registro', event)) trackMarketing('cta_click', location); }}>{label}<Arrow /></a>;
}

export function MarketingHero({ children, open, compact = false, encounter }: { children?: ReactNode; open: boolean; compact?: boolean; encounter?: ReactNode }) {
    const landing = useContext(LandingContext)!.content;
    const content = landing.hero;
    const webinar = landing.presentation.template === 'WEBINAR';
    return <section className={`conversion-hero shell ${webinar ? 'webinar-hero' : ''} ${compact ? 'compact-hero' : ''} ${!children ? 'hero-without-media' : ''}`} aria-labelledby="hero-title">
        <div className="hero-heading">
            <p className="eyebrow hero-eyebrow"><span aria-hidden="true" className="size-2 rounded-chip bg-brand" /> {content.eyebrow}</p>
            <h1 id="hero-title" className="conversion-title">{content.title} <span className="hero-emphasis">{content.emphasis}</span> {content.ending}</h1>
        </div>
        {children && <div id="presentacion" className="hero-media">{children}</div>}
        <div className="hero-copy">
            <p className="text-base leading-relaxed text-text-secondary sm:text-lg">{content.description}</p>
            <p className="mt-4 text-xs font-extrabold tracking-widest">JAKAWI — VIVE MÁS. GASTA MENOS.</p>
            <MarketingCTA location="hero" className="mt-5" />
            {open && <p className="mt-3 text-xs text-text-muted">Registro gratuito en esta etapa.</p>}
            {encounter}
        </div>
    </section>;
}

export function MarketingBenefits({ compact = false }: { compact?: boolean }) {
    const content = useContext(LandingContext)!.content.benefits;
    return <section id="como-funciona" className="shell section-space" aria-labelledby="benefits-title">
        <div className="max-w-2xl"><p className="eyebrow">{content.eyebrow}</p><h2 id="benefits-title" className="section-title mt-4">{content.title}</h2><p className="mt-5 leading-7 text-text-secondary">{content.description}</p></div>
        <div className={`mt-9 grid gap-4 sm:grid-cols-2 ${compact ? 'lg:grid-cols-3' : 'lg:grid-cols-4'}`}>{(compact ? content.items.slice(0, 3) : content.items).map(({title, description}, index) => <article key={title} className="feature-card"><span className="benefit-number" aria-hidden="true">0{index + 1}</span><h3 className="mb-3 mt-5 text-lg font-extrabold tracking-tight">{title}</h3><p className="text-sm leading-7 text-text-secondary">{description}</p></article>)}</div>
    </section>;
}

export function MarketingFAQ() {
    const content = useContext(LandingContext)!.content.faq;
    return <section className="shell section-space" aria-labelledby="faq-title"><div className="grid gap-8 lg:grid-cols-[1fr_1.4fr]"><div><p className="eyebrow">{content.eyebrow}</p><h2 id="faq-title" className="section-title mt-4">{content.title}</h2></div><div>{content.items.map(({question, answer}) => <details key={question} className="faq-item"><summary>{question}<span aria-hidden="true">+</span></summary><p className="pb-6 pr-5 text-sm leading-7 text-text-secondary">{answer}</p></details>)}</div></div></section>;
}

export function MarketingFinalCTA() {
    const content = useContext(LandingContext)!.content.final;
    return <section className="final-cta" aria-labelledby="final-title"><div className="shell py-14 text-center sm:py-18"><p className="eyebrow">{content.eyebrow}</p><h2 id="final-title" className="section-title mx-auto mt-5 max-w-3xl">{content.title}</h2><p className="mx-auto mt-5 max-w-lg leading-7">{content.description}</p><MarketingCTA location="final" className="mt-7" /><p className="mt-4 text-xs">VIVE MÁS. GASTA MENOS.</p></div></section>;
}
