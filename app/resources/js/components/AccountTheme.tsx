import { useSyncExternalStore } from 'react';

type Theme = 'light' | 'dark' | 'system';
const key = 'jakawi.club.appearance';
const media = window.matchMedia('(prefers-color-scheme: dark)');
const valid = (value: string | null): Theme => value === 'light' || value === 'dark' ? value : 'system';
const read = (): Theme => { try { return valid(localStorage.getItem(key)); } catch { return 'system'; } };
let preference = read();
const listeners = new Set<() => void>();
function apply() {
    document.documentElement.dataset.accountTheme = preference === 'system' ? (media.matches ? 'dark' : 'light') : preference;
    listeners.forEach(listener => listener());
}
apply();
media.addEventListener('change', apply);
window.addEventListener('storage', event => { if (event.key === key || event.key === null) { preference = read(); apply(); } });
const subscribe = (listener: () => void) => { listeners.add(listener); return () => { listeners.delete(listener); }; };
export default function AccountTheme() {
    const theme = useSyncExternalStore(subscribe, () => `${preference}:${media.matches}`);
    const isDark = theme.startsWith('dark:') || (theme.startsWith('system:') && media.matches);
    return <button type="button" className="account-icon-button" aria-label={isDark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'} onClick={() => {
        preference = isDark ? 'light' : 'dark';
        try { localStorage.setItem(key, preference); } catch { /* Immediate theme remains usable if storage is blocked. */ }
        apply();
    }}><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{isDark ? <><circle cx="12" cy="12" r="4" /><path d="M12 2v2m0 16v2M2 12h2m16 0h2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42" /></> : <path d="M20.985 12.486a9 9 0 1 1-9.473-9.472c.405-.022.617.44.402.784a6.25 6.25 0 0 0 8.268 8.268c.344-.215.806-.004.783.42" />}</svg></button>;
}
