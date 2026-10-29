import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { Footer, Header } from '../components/UI';
import { RegistrationForm } from '../components/forms/RegistrationForm';
import { JakawiVslPlayer } from '../components/media/jakawi-vsl-player';
import { MarketingBenefits, MarketingFAQ, MarketingFinalCTA, MarketingHero } from '../components/marketing/primitives';
import { trackMarketing } from '../components/marketing/analytics';
import { LandingContext } from '../components/marketing/LandingContext';
import { MobileStickyCTA } from '../components/marketing/MobileStickyCTA';
import type { Launch, Landing } from '../types';

type Props = { landing: Landing; launch: Launch; referral_code: string | null; referral_preference: string | null };
export default function Welcome({ landing, launch, referral_code, referral_preference }: Props) {
    const preferences = useForm({ allow_referral_cookie: false });
    const content = landing.content;
    useEffect(() => { if (!landing.preview) trackMarketing('landing_visit', 'landing', landing.id); }, [landing.id, landing.preview]);
    return <LandingContext.Provider value={landing}><Head title={content.seo.title}><meta name="description" content={content.seo.description} />{landing.preview && <meta name="robots" content="noindex, nofollow" />}</Head>{landing.preview && <div className="message text-center" role="status">Vista previa del borrador · revisión {landing.revision} · registro deshabilitado</div>}<Header /><main>
        <MarketingHero open={launch.registration_open}><JakawiVslPlayer key={launch.video_url} url={launch.video_url} poster={launch.video_poster} aspectRatio={content.video.aspect_ratio} sourceAspectRatio={content.video.source_aspect_ratio} onEvent={event => { if (event.type === 'playing' && !landing.preview) trackMarketing('video_start', 'video', landing.id); }} /></MarketingHero>
        <MarketingBenefits />
        <section className="registration-section" aria-labelledby="registration-title"><div className="shell grid items-start gap-9 py-14 lg:grid-cols-2 lg:gap-20 lg:py-20">
            <div className="lg:pt-6"><p className="eyebrow">{content.registration.eyebrow}</p><h2 id="registration-title" className="section-title mt-5">{content.registration.title}</h2><p className="mt-6 max-w-md text-lg leading-relaxed text-text-secondary">{content.registration.description}</p><div className="mt-8 space-y-4 border-t border-border pt-6 text-sm leading-7"><p><strong>Registro gratuito en esta etapa.</strong><br />Cuando abramos, podrás activar tu cuenta desde tu correo.</p><p className="text-text-secondary">Tu invitación personal estará disponible después de activar la cuenta.</p></div><div id="en-vivo" className="mt-8 border-t border-border pt-6"><p className="eyebrow">PRÓXIMOS ENCUENTROS</p>{launch.event ? <p className="mt-3 text-sm leading-7"><strong>{launch.event.title}</strong><br /><time dateTime={launch.event.starts_at}>{new Intl.DateTimeFormat('es-BO', { dateStyle: 'long', timeStyle: 'short', timeZone: 'America/La_Paz' }).format(new Date(launch.event.starts_at))} (Bolivia)</time></p> : <p className="mt-3 text-sm leading-7 text-text-secondary">Publicaremos aquí la fecha y los detalles del próximo encuentro cuando estén confirmados.</p>}</div></div>
            <RegistrationForm open={launch.registration_open} referralCode={referral_code} landingId={landing.id} />
        </div></section>
        <MarketingFAQ />
        <MarketingFinalCTA />
        {referral_code && referral_preference !== 'yes' && <section className="shell py-8"><div className="rounded-card border border-border p-5 text-sm"><p>Tu invitación se conserva durante esta sesión. Puedes permitir una cookie opcional para recordarla hasta 30 días. <Link href="/privacidad" className="underline">Privacidad</Link></p><div className="mt-4 flex flex-wrap gap-3"><button className="btn btn-secondary" aria-busy={preferences.processing} disabled={preferences.processing} onClick={() => { preferences.transform(() => ({ allow_referral_cookie: true })); preferences.post('/preferencias', { preserveScroll: true }); }}>Recordar 30 días</button><button className="btn btn-secondary" aria-busy={preferences.processing} disabled={preferences.processing} onClick={() => { preferences.transform(() => ({ allow_referral_cookie: false })); preferences.post('/preferencias', { preserveScroll: true }); }}>No recordar invitación</button></div></div></section>}
    </main><Footer /><MobileStickyCTA label={content.hero.cta} /></LandingContext.Provider>;
}
