import { Head, router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';

type Ads = { provider?: 'meta' | 'tiktok'; allowed: boolean; pixel_id?: string; preference?: string; retention_days: number; events: { id: string; name: string }[] };
type Pixel = ((...args: unknown[]) => void) & { callMethod?: (...args: unknown[]) => void; queue: unknown[][]; loaded: boolean; version: string; push: Pixel };
type TikTok = { track: (...args: unknown[]) => void; page: () => void; load: (id: string) => void; [key: string]: unknown };
declare global { interface Window { fbq?: Pixel; ttq?: TikTok; adProvider?: string } }
const sent = new Set<string>();
let lastPage = '';
function once(key: string, action: () => void) {
    if (sent.has(key)) return;
    try { if (sessionStorage.getItem(key)) return; } catch { /* storage is optional */ }
    action(); sent.add(key);
    try { sessionStorage.setItem(key, '1'); } catch { /* storage is optional */ }
}
function loadMeta(id: string) {
    if (window.fbq) return;
    const fbq = function (...args: unknown[]) { if (fbq.callMethod) fbq.callMethod(...args); else fbq.queue.push(args); } as Pixel;
    fbq.queue = []; fbq.loaded = true; fbq.version = '2.0'; fbq.push = fbq;
    window.fbq = fbq;
    fbq('set', 'autoConfig', false, id); fbq('init', id); fbq('consent', 'grant');
    const script = document.createElement('script'); script.async = true; script.src = 'https://connect.facebook.net/en_US/fbevents.js'; document.head.append(script);
}
function loadTikTok(id: string) {
    if (window.ttq) return;
    const queue: unknown[][] = [];
    const ttq = queue as unknown as TikTok;
    for (const method of ['page', 'track', 'identify', 'instances', 'debug', 'on', 'off', 'once', 'ready', 'alias', 'group', 'enableCookie', 'disableCookie', 'holdConsent', 'revokeConsent', 'grantConsent']) {
        ttq[method] = (...args: unknown[]) => queue.push([method, ...args]);
    }
    (window as unknown as Record<string, unknown>).TiktokAnalyticsObject = 'ttq';
    const instance: unknown[][] & { _u?: string } = []; instance._u = 'https://analytics.tiktok.com/i18n/pixel/events.js';
    ttq._i = { [id]: instance }; ttq._t = { [id]: Date.now() }; ttq._o = { [id]: { autoConfig: false } };
    window.ttq = ttq;
    const script = document.createElement('script'); script.async = true; script.src = `https://analytics.tiktok.com/i18n/pixel/events.js?sdkid=${encodeURIComponent(id)}&lib=ttq`; document.head.append(script);
}
export default function Advertising() {
    const page = usePage(); const ads = page.props.ads as Ads | null;
    useEffect(() => {
        // A fresh document removes provider runtimes when leaving its authorized Ads routes.
        return router.on('before', event => {
            if (!window.adProvider) return;
            const path = event.detail.visit.url.pathname;
            if (!path.startsWith(`/ads/${window.adProvider}/`) && event.detail.visit.method === 'get') {
                window.location.assign(event.detail.visit.url.href); return false;
            }
        });
    }, []);
    useEffect(() => {
        if (!ads?.allowed || !ads.pixel_id || !ads.provider) {
            if (window.adProvider) { window.fbq?.('consent', 'revoke'); window.location.reload(); }
            return;
        }
        try {
            window.adProvider = ads.provider;
            if (ads.provider === 'meta') loadMeta(ads.pixel_id); else loadTikTok(ads.pixel_id);
            const pageKey = `ads:page:${ads.provider}:${page.url}`;
            if (lastPage !== pageKey) {
                lastPage = pageKey;
                if (ads.provider === 'meta') window.fbq?.('track', 'PageView'); else window.ttq?.page();
            }
            for (const event of ads.events) once(`ads:event:${event.id}`, () => {
                if (ads.provider === 'meta') window.fbq?.('track', event.name, {}, { eventID: event.id });
                else window.ttq?.track(event.name, {}, { event_id: event.id });
            });
        } catch { /* Advertising never interrupts navigation or checkout. */ }
    }, [ads, page.url]);
    const visible = ads?.provider || page.url.split('?')[0] === '/privacidad';
    if (!visible) return null;
    const canonical = ads?.provider ? `${window.location.origin}/${page.url.split('?')[0].endsWith('/membresia') ? 'membresia' : 'activar'}` : null;
    function preference(allow: boolean) {
        const token = document.cookie.split('; ').find(v => v.startsWith('XSRF-TOKEN='))?.slice(11);
        void fetch('/preferencias', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(token || '') }, body: JSON.stringify({ allow_ads: allow }) })
            .then(response => { if (response.ok) window.location.reload(); }).catch(() => {});
    }
    return <>{canonical && <Head><meta head-key="robots" name="robots" content="noindex, nofollow" /><link head-key="canonical" rel="canonical" href={canonical} /></Head>}<section aria-label="Preferencias publicitarias" className="shell py-5"><div className="rounded-card border border-border bg-surface p-5 text-sm"><p>Medición publicitaria opcional: Meta o TikTok, según la ruta de entrada. Con tu permiso compartimos eventos de registro, inicio de compra y compra confirmada, identificadores de campaña y correo/teléfono con hash. Conservación: hasta {ads?.retention_days || 30} días. Puedes retirar el permiso aquí o en Privacidad y cookies.</p><p className="mt-2">Preferencia: {ads?.preference === 'yes' ? 'permitida' : ads?.preference === 'no' ? 'rechazada' : 'sin elegir'}.</p><div className="mt-4 flex flex-wrap gap-3"><button type="button" className="btn btn-secondary" onClick={() => preference(true)}>Permitir medición publicitaria</button><button type="button" className="btn btn-secondary" onClick={() => preference(false)}>Rechazar o retirar permiso publicitario</button></div></div></section></>;
}
