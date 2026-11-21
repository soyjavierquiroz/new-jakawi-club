import { useSyncExternalStore } from 'react';

type Appearance = 'light' | 'dark' | 'system';
declare global {
    interface Window {
        appearanceStore: {
            subscribe: (listener: () => void) => () => void;
            snapshot: () => string;
            sync: (identity: string | null, preference: string) => void;
            set: (value: Appearance) => void;
        };
    }
}
export default function AccountTheme() {
    const snapshot = useSyncExternalStore(window.appearanceStore.subscribe, window.appearanceStore.snapshot, () => 'system:');
    const [preference, error] = snapshot.split(':');
    return <div className="appearance-control"><label><span className="sr-only">Apariencia</span><select aria-label="Apariencia" value={preference} onChange={event => window.appearanceStore.set(event.target.value as Appearance)}><option value="light">Claro</option><option value="dark">Oscuro</option><option value="system">Sistema</option></select></label>{error && <p role="alert" className="appearance-error">{error}</p>}</div>;
}
