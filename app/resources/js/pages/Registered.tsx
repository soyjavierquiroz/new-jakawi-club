import { Head, Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Arrow, PageFrame, ReferralLink } from '../components/UI';
import { LandingVideo } from '../components/media/jakawi-vsl-player';
import type { LandingContent } from '../types';

type Confirmation = {
    content: LandingContent['after_registration'];
    activation: 'pending' | 'confirmed';
    preview: boolean;
    landing_slug: string | null;
    next: { url: string; external: boolean; redirect_seconds: number } | null;
    unavailable: boolean;
    video: LandingContent['video'];
    whatsapp_group_url: string | null;
};

function NextStep({ confirmation }: { confirmation: Confirmation }) {
    const { next, preview, content, landing_slug } = confirmation;
    const [remaining, setRemaining] = useState<number | null>(null);
    const [cancelled, setCancelled] = useState(false);
    const started = useRef(false);
    useEffect(() => {
        if (cancelled) return;
        if (preview || !next?.redirect_seconds || !next.external) return;
        const key = `jakawi-next-step:${landing_slug}:${next.url}`;
        try {
            if (!started.current && sessionStorage.getItem(key)) return;
            started.current = true;
            // A refresh/back navigation must never restart automatic navigation.
            sessionStorage.setItem(key, 'shown');
        } catch { return; }
        const end = Date.now() + next.redirect_seconds * 1000;
        setRemaining(next.redirect_seconds);
        const timer = window.setInterval(() => {
            const seconds = Math.max(0, Math.ceil((end - Date.now()) / 1000));
            setRemaining(seconds);
            if (seconds === 0) { window.clearInterval(timer); window.location.assign(next.url); }
        }, 250);
        return () => window.clearInterval(timer);
    }, [next?.url, next?.redirect_seconds, next?.external, preview, landing_slug, cancelled]);
    if (!next) return null;
    return <div className="mt-7 space-y-4">
        {!next.external && (preview ? <button className="btn btn-primary" disabled>{content.cta_label}<Arrow /></button> : <Link className="btn btn-primary" href={next.url}>{content.cta_label}<Arrow /></Link>)}
        {preview && next.redirect_seconds > 0 && <p className="text-sm text-text-secondary">Vista previa: contador de {next.redirect_seconds} segundos. Redirección deshabilitada.</p>}
        {remaining !== null && !cancelled && <div className="rounded-card border border-border bg-background p-4"><p className="text-sm" role="timer" aria-live="off">Accederás al grupo de WhatsApp en {remaining} segundos.</p><button type="button" className="mt-3 text-sm font-semibold underline" onClick={() => { setCancelled(true); setRemaining(null); }}>Cancelar redirección</button></div>}
        {cancelled && <p className="text-sm text-text-secondary" role="status">Redirección cancelada. Puedes continuar con el botón cuando quieras.</p>}
    </div>;
}

export default function Registered({ referral_url, confirmation }: { referral_url: string | null; confirmation: Confirmation }) {
    const { content, activation, preview } = confirmation;
    const activated = activation === 'confirmed';
    return <><Head title="Gracias por tu solicitud"><meta name="robots" content="noindex, nofollow" /></Head><PageFrame narrow>
        {preview && <p className="message mb-7" role="status">Vista previa del borrador · después del registro. No acredita una conversión ni envía datos.</p>}
        <p className="eyebrow">{activated ? 'TU SIGUIENTE PASO EN JAKAWI' : 'REGISTRO RECIBIDO'}</p>
        <h1 className="section-title mt-5 break-words">{content.title}</h1>
        <p className="mt-6 whitespace-pre-line break-words text-lg leading-relaxed text-text-secondary">{content.message}</p>
        {confirmation.video?.youtube_url && <div className="confirmation-video my-8" aria-label="Video explicativo"><LandingVideo video={confirmation.video} url={confirmation.video.youtube_url} poster={confirmation.video.poster} /></div>}
        {preview && !confirmation.video?.youtube_url && <p className="message my-6" role="status">Sin video explicativo configurado. El reproductor público estará oculto.</p>}
        {confirmation.whatsapp_group_url && <div className="mt-7">{preview ? <button className="btn community-whatsapp-cta" disabled>UNIRME AL GRUPO DE WHATSAPP</button> : <a className="btn community-whatsapp-cta" href={confirmation.whatsapp_group_url} target="_blank" rel="noopener noreferrer">UNIRME AL GRUPO DE WHATSAPP</a>}</div>}
        <section className="my-8 rounded-card border border-border bg-surface p-6" aria-labelledby="activation-status" data-activation={activation}>
            <h2 id="activation-status" className="text-xl font-bold">{activated ? 'Cuenta activada' : 'Revisa tu correo'}</h2>
            <p className="mt-3 text-sm leading-7 text-text-secondary">{activated ? 'Tu correo está verificado. Ya puedes ingresar y compartir tu invitación personal.' : 'Esta confirmación no activa tu cuenta. Si tu cuenta es nueva, abre el enlace que recibas por correo y elige una contraseña. Revisa también spam. El enlace vence en 60 minutos.'}</p>
        </section>
        <p className="whitespace-pre-line break-words leading-relaxed text-text-secondary">{content.instructions}</p>
        <NextStep key={`${confirmation.landing_slug}:${confirmation.next?.url}:${preview}`} confirmation={confirmation} />
        {activated && referral_url && <div className="mt-9 rounded-card border border-border bg-surface p-6"><ReferralLink url={referral_url} /><Link className="btn btn-secondary mt-5" href="/dashboard">Ir a mi cuenta<Arrow /></Link></div>}
        {!activated && <p className="mt-7"><Link className="text-sm font-semibold underline underline-offset-4" href="/recuperar-password">Solicitar otro enlace de acceso</Link></p>}
        <p className="mt-7 text-xs text-text-muted">{preview ? 'Preview sin registro, analítica ni navegación automática.' : 'Esta confirmación está disponible durante 15 minutos.'}</p>
    </PageFrame></>;
}
