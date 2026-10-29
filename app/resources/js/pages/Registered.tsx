import { Head, usePage } from '@inertiajs/react';
import { useRef } from 'react';
import { CommunityWhatsAppIcon } from '../components/CommunityWhatsAppIcon';
import { LandingVideo, type VslEvent } from '../components/media/jakawi-vsl-player';
import type { LandingContent } from '../types';

type Confirmation = {
    content: LandingContent['after_registration'];
    activation: 'pending' | 'confirmed';
    preview: boolean;
    video: LandingContent['video'];
    whatsapp_group_url: string | null;
};

export default function Registered({ confirmation }: { confirmation: Confirmation }) {
    const { content, activation, preview, whatsapp_group_url } = confirmation;
    const { can_manage_landings } = usePage<{ can_manage_landings: boolean }>().props;
    const redirected = useRef(false);
    const activated = activation === 'confirmed';
    // Upgrade only the previous default; campaign copy and receipt media stay intact.
    const title = content.title === 'Gracias por dar el siguiente paso.'
        ? '¡Felicidades por dar el siguiente paso!' : content.title;
    const instructions = content.instructions === 'Continúa con el siguiente paso. Puedes solicitar otro enlace de acceso si lo necesitas.'
        ? null : content.instructions;

    function onVideoEvent(event: VslEvent) {
        if (event.type !== 'ended' || event.state.status !== 'ended' || !event.state.hasPlayed || event.state.error
            || preview || can_manage_landings || !whatsapp_group_url || redirected.current) return;
        redirected.current = true;
        // Same-tab browser navigation needs no popup. Keep the manual link if blocked.
        try { window.location.assign(whatsapp_group_url); } catch { /* Manual CTA remains available. */ }
    }

    return <><Head title="Felicidades por tu registro"><meta name="robots" content="noindex, nofollow" /></Head>
        <main className="shell confirmation-page">
            {preview && <p className="message confirmation-preview" role="status">Vista previa del borrador · después del registro. Sin altas ni navegación automática.</p>}
            <div className="confirmation-intro">
                <p className="eyebrow">REGISTRO RECIBIDO</p>
                <h1 className="confirmation-title">{title}</h1>
                <p className="confirmation-message">{content.message}</p>
            </div>
            {confirmation.video?.youtube_url && <div className="confirmation-video" aria-label="Video explicativo"><LandingVideo video={confirmation.video} url={confirmation.video.youtube_url} poster={confirmation.video.poster} onEvent={onVideoEvent} /></div>}
            {preview && !confirmation.video?.youtube_url && <p className="message" role="status">Sin video explicativo configurado. El reproductor público estará oculto.</p>}
            {whatsapp_group_url && <div className="confirmation-action">
                {preview ? <><button className="btn community-whatsapp-cta confirmation-whatsapp-cta" disabled><CommunityWhatsAppIcon />UNIRME AL GRUPO DE WHATSAPP</button><p className="mt-4 break-all text-xs text-text-secondary">{whatsapp_group_url}</p></>
                    : <a className="btn community-whatsapp-cta confirmation-whatsapp-cta" href={whatsapp_group_url} target="_blank" rel="noopener noreferrer"><CommunityWhatsAppIcon />UNIRME AL GRUPO DE WHATSAPP</a>}
            </div>}
            <section className="confirmation-email" aria-labelledby="activation-status" data-activation={activation}>
                <svg className="confirmation-email-icon" width="64" height="64" viewBox="0 0 64 64" fill="none" aria-hidden="true">
                    <rect x="5" y="13" width="54" height="39" rx="8" fill="var(--background)" stroke="currentColor" strokeWidth="2" />
                    <path d="m8 18 20 16a6 6 0 0 0 8 0l20-16M8 47l14-13m34 13L42 34" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                    <circle cx="51" cy="14" r="11" fill="var(--brand)" />
                    <path d="m46 14 3 3 6-6" stroke="var(--ink)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
                <h2 id="activation-status">Revisa tu correo</h2>
                <p>{activated ? 'Tu cuenta ya está activada y tu correo está verificado.' : 'Si tu cuenta es nueva, abre el enlace de activación y elige una contraseña. Revisa también spam; el enlace vence en 60 minutos.'}</p>
                {instructions && <p className="whitespace-pre-line">{instructions}</p>}
            </section>
            <p className="confirmation-note">{preview ? 'Preview sin registro, analítica ni navegación automática.' : 'Esta confirmación está disponible durante 15 minutos.'}</p>
        </main>
    </>;
}
