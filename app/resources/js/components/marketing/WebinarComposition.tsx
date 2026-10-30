import { useContext, useEffect, useState, type ReactNode } from 'react';
import { LandingContext, useLandingTracking } from './LandingContext';
import { focusRegistration } from './registration-navigation';
import { Arrow } from '../UI';
import { MarketingBenefits, MarketingFAQ, MarketingFinalCTA, MarketingHero } from './primitives';

function EventIcon({ kind = 'calendar' }: { kind?: 'calendar' | 'clock' | 'online' }) {
    return <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{kind === 'calendar' ? <><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M16 3v4M8 3v4M3 11h18" /></> : kind === 'clock' ? <><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></> : <><rect x="3" y="4" width="18" height="13" rx="2" /><path d="M8 21h8M12 17v4" /></>}</svg>;
}

const boliviaDate = (value: string, compact = false) => new Intl.DateTimeFormat('es-BO', {
    ...(compact ? { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' } : { dateStyle: 'full', timeStyle: 'short' }),
    hourCycle: 'h23', timeZone: 'America/La_Paz',
}).format(new Date(value));

export function WebinarEventBar({ startsAt }: { startsAt: string | null }) {
    const track = useLandingTracking();
    const dated = startsAt && Number.isFinite(Date.parse(startsAt));
    return <div className="webinar-event-bar" data-webinar-event-bar><div className="shell">
        <p><EventIcon />{dated ? <time dateTime={startsAt}>{boliviaDate(startsAt, true)} · Bolivia</time> : <span>WEBINAR GRATUITO · PRÓXIMAMENTE</span>}</p>
        <a href="#registro" onClick={event => { if (focusRegistration('registro', event)) track('cta_click', 'hero'); }}>Reservar lugar<Arrow /></a>
    </div></div>;
}

function WebinarEvent({ title, startsAt }: { title: string; startsAt: string | null }) {
    const [now, setNow] = useState(Date.now);
    const target = startsAt ? Date.parse(startsAt) : NaN;
    const seconds = Math.max(0, Math.ceil((target - now) / 1000));
    useEffect(() => {
        if (!Number.isFinite(target) || target <= Date.now()) return;
        const timer = window.setInterval(() => {
            const current = Date.now();
            setNow(current);
            if (current >= target) window.clearInterval(timer);
        }, 1000);
        return () => window.clearInterval(timer);
    }, [target]);
    const dated = Number.isFinite(target);
    return <section className="shell section-space" aria-labelledby="webinar-event-title" data-webinar-schedule>
        <div className="feature-card webinar-event-card"><p className="eyebrow">WEBINAR GRATUITO · EN VIVO</p><h2 id="webinar-event-title" className="section-title mt-4">{title}</h2>
            {dated ? <><div className="webinar-event-details"><p><EventIcon /><time dateTime={startsAt!}>{boliviaDate(startsAt!)} (Bolivia)</time></p><p><EventIcon kind="clock" />Hora de Bolivia · America/La_Paz</p><p><EventIcon kind="online" />Formato online</p></div>
                {seconds > 0 ? <div className="webinar-countdown" role="timer" aria-label="Tiempo hasta el webinar" data-webinar-countdown>{[
                    ['Días', Math.floor(seconds / 86400)], ['Horas', Math.floor(seconds / 3600) % 24], ['Minutos', Math.floor(seconds / 60) % 60], ['Segundos', seconds % 60],
                ].map(([label, value]) => <div key={label}><span className="block text-3xl font-extrabold tabular-nums">{String(value).padStart(2, '0')}</span><span className="text-sm text-text-secondary">{label}</span></div>)}</div> : <p className="mt-5" data-webinar-elapsed>La fecha programada ya llegó. Consulta las indicaciones del encuentro.</p>}
            </> : <div className="webinar-date-pending"><p><EventIcon />Fecha por confirmar</p><p>Publicaremos la fecha y la hora cuando estén confirmadas.</p></div>}
        </div>
    </section>;
}

export function WebinarComposition({ open, video, registration }: { open: boolean; video: ReactNode; registration: ReactNode }) {
    const content = useContext(LandingContext)!.content;
    const settings = content.presentation.webinar!;
    const webinar = content.webinar!;
    return <>{settings.sections.filter(section => section.visible || section.id === 'registration').map(({ id }) => {
        let block: ReactNode;
        switch (id) {
            case 'hero': block = <MarketingHero open={open} compact encounter={<p className="mt-5 font-bold">Webinar gratuito de prelanzamiento</p>}>{video}</MarketingHero>; break;
            case 'event': block = <WebinarEvent title={settings.title} startsAt={settings.starts_at} />; break;
            case 'benefits': block = <MarketingBenefits compact />; break;
            case 'registration': block = registration; break;
            case 'faq': block = <MarketingFAQ />; break;
            case 'final': block = <MarketingFinalCTA />; break;
            default: {
                const item = webinar[id];
                block = <section className="shell section-space" aria-labelledby={`webinar-${id}-title`}><h2 id={`webinar-${id}-title`} className="section-title">{item.title}</h2>{'description' in item && <p className="mt-5 max-w-2xl leading-7 text-text-secondary">{item.description}</p>}{'items' in item && <ol className={id === 'steps' ? 'webinar-steps' : 'mt-8 grid gap-5 md:grid-cols-3'}>{item.items.map((entry, index) => <li className="feature-card" key={index}><span className="benefit-number" aria-hidden="true">0{index + 1}</span>{id === 'steps' && <Arrow />}<h3 className="mt-4 text-lg font-bold">{entry.title}</h3><p className="mt-3 text-sm leading-7 text-text-secondary">{entry.description}</p></li>)}</ol>}</section>;
            }
        }
        return <div key={id} data-webinar-section={id}>{block}</div>;
    })}</>;
}
