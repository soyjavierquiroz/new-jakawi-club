export type MarketingPlacement = 'landing' | 'hero' | 'benefits' | 'final' | 'video';

/** Same-origin only, finite event vocabulary, no field values or visitor identifiers. */
export function trackMarketing(event: 'landing_visit' | 'cta_click' | 'video_start', placement: MarketingPlacement) {
    const token = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
    if (!token) return;
    void fetch('/medicion', {
        method: 'POST', credentials: 'same-origin', keepalive: true,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(token) },
        body: JSON.stringify({ event, placement }),
    }).catch(() => undefined);
}
