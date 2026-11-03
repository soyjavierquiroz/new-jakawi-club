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
    const theme = useSyncExternalStore(subscribe, () => preference);
    return <label className="account-theme-control"><span>Tema</span><select aria-label="Tema de cuenta" value={theme} onChange={event => {
        preference = valid(event.target.value);
        try { localStorage.setItem(key, preference); } catch { /* Keep the control usable when storage is blocked. */ }
        apply();
    }}><option value="light">Claro</option><option value="dark">Oscuro</option><option value="system">Automático</option></select></label>;
}
