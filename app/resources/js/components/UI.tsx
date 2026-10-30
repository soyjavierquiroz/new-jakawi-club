import { Link, usePage } from '@inertiajs/react';
import { useRef, useState, type ReactNode } from 'react';
import type { Launch } from '../types';
import { CommunityWhatsAppIcon } from './CommunityWhatsAppIcon';

export function Arrow() {
    return <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6" /></svg>;
}
export function BrandLogo() {
    return <img src="/jakawi-logo.svg" alt="JAKAWI" width={1080} height={431} className="brand-logo" />;
}
export function Header({ capture = false, compact = false }: { capture?: boolean; compact?: boolean }) {
    const { signed_in, can_manage_landings } = usePage<{ signed_in: boolean; can_manage_landings: boolean }>().props;
    return <header className={`site-header ${compact ? 'webinar-mobile-header' : ''}`}><div className="shell flex min-h-20 items-center justify-between gap-5">{capture ? <BrandLogo /> : <Link href="/" aria-label="JAKAWI, inicio"><BrandLogo /></Link>}<nav aria-label="Navegación principal" className="flex items-center gap-5 text-sm font-semibold sm:gap-9">{!capture && <>{can_manage_landings && <Link href="/admin/landings">Landings</Link>}<a className="hidden text-text-secondary hover:underline md:block" href="/#como-funciona">La comunidad</a><a className="hidden text-text-secondary hover:underline sm:block" href="/#en-vivo">En vivo</a></>}<Link className={capture ? 'text-xs text-text-secondary underline underline-offset-4' : 'nav-access'} href={signed_in ? '/dashboard' : '/login'}>{signed_in ? 'Mi cuenta' : 'Ingresar'}{!capture && <Arrow />}</Link></nav></div></header>;
}
export function Footer({ capture = false }: { capture?: boolean }) {
    return <footer className="border-t border-border bg-background"><div className="shell flex flex-col justify-between gap-6 py-10 text-sm sm:flex-row sm:items-center"><div>{capture ? <BrandLogo /> : <Link href="/" aria-label="JAKAWI, inicio"><BrandLogo /></Link>}<p className="mt-2 text-text-secondary">VIVE MÁS. GASTA MENOS.</p></div><div className="flex flex-wrap gap-6"><Link href="/privacidad">Privacidad y cookies</Link>{!capture && <Link href="/login">Mi cuenta</Link>}<span className="text-text-muted">jakawi.club</span></div></div></footer>;
}
export function Flash() {
    const { status } = usePage<{ status?: string }>().props;
    return status ? <div role="status" className="message message-success mb-6">{status}</div> : null;
}
export function ErrorText({ children, id }: { children?: string; id?: string }) {
    return children ? <p id={id} role="alert" className="mt-2 text-sm text-danger">{children}</p> : null;
}
export function ReferralLink({ url }: { url: string }) {
    const [message, setMessage] = useState('');
    const input = useRef<HTMLInputElement>(null);
    async function copy() {
        try { await navigator.clipboard.writeText(url); setMessage('¡Enlace copiado!'); }
        catch { input.current?.select(); setMessage('Selecciona y copia el enlace.'); }
    }
    return <div><label htmlFor="personal-link" className="mb-3 block text-sm font-semibold">Tu enlace personal</label><div className="flex flex-col gap-3 sm:flex-row"><input ref={input} id="personal-link" readOnly value={url} className="input min-w-0 flex-1 font-sans" onFocus={(e) => e.target.select()} /><button type="button" className="btn btn-primary shrink-0" onClick={() => void copy()}>Copiar enlace <Arrow /></button></div><p role="status" className="mt-2 min-h-5 text-sm text-text-secondary">{message || 'Compártelo con las personas que quieras invitar.'}</p></div>;
}
export function EventCard({ launch }: { launch: Launch }) {
    const event = launch.event;
    return <div><p className="eyebrow">PRÓXIMO ENCUENTRO</p><h3 className="mt-4 text-2xl font-extrabold tracking-tight">{event?.title || 'Lo que viene, en primera fila.'}</h3>{event ? <><p className="mt-4 text-text-secondary"><time dateTime={event.starts_at}>{new Intl.DateTimeFormat('es-BO', { dateStyle: 'long', timeStyle: 'short', timeZone: 'America/La_Paz' }).format(new Date(event.starts_at))} (Bolivia)</time></p>{event.zoom_url && <a className="btn btn-primary mt-6" href={event.zoom_url} target="_blank" rel="noopener noreferrer">Entrar al encuentro <Arrow /></a>}</> : <p className="mt-4 leading-relaxed text-text-secondary">Estamos preparando nuestro próximo lanzamiento en vivo. Aquí encontrarás la fecha y los detalles cuando estén confirmados.</p>}</div>;
}
export function Community({ launch }: { launch: Launch }) {
    return <div><p className="eyebrow">SIGAMOS EN CONTACTO</p><h3 className="mt-4 text-2xl font-extrabold tracking-tight">La conversación continúa.</h3><p className="mt-4 leading-relaxed text-text-secondary">Un espacio para acompañar el lanzamiento de JAKAWI y conocer las novedades.</p>{launch.whatsapp_url ? <a href={launch.whatsapp_url} target="_blank" rel="noopener noreferrer" className="btn community-whatsapp-cta mt-6"><CommunityWhatsAppIcon /> Unirme a WhatsApp <Arrow /></a> : <p className="mt-6 inline-flex rounded-chip border border-border px-4 py-2 text-xs font-semibold">Comunidad de WhatsApp · Próximamente</p>}</div>;
}
export function PageFrame({ children, narrow = false, wide = false }: { children: ReactNode; narrow?: boolean; wide?: boolean }) {
    return <><Header /><main className={`shell min-h-[65vh] py-12 sm:py-20 ${narrow ? 'max-w-2xl!' : ''} ${wide ? 'landing-editor-page' : ''}`}><Flash />{children}</main><Footer /></>;
}
