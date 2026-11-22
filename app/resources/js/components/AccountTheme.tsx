import { useEffect, useId, useRef, useState, useSyncExternalStore } from 'react';

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
const modes = [
    { value: 'light', label: 'Claro' },
    { value: 'dark', label: 'Oscuro' },
    { value: 'system', label: 'Sistema' },
] as const;
function AppearanceIcon({ mode }: { mode: string }) {
    return <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" data-appearance-icon={mode}>
        {mode === 'light' ? <><circle cx="12" cy="12" r="4" /><path d="M12 2v2m0 16v2M2 12h2m16 0h2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42" /></> : mode === 'dark' ? <path d="M20.985 12.486a9 9 0 1 1-9.473-9.472c.405-.022.617.44.402.784a6.25 6.25 0 0 0 8.268 8.268c.344-.215.806-.004.783.42" /> : <><rect x="3" y="4" width="18" height="13" rx="2" /><path d="M8 21h8m-4-4v4" /></>}
    </svg>;
}
export default function AccountTheme() {
    const snapshot = useSyncExternalStore(window.appearanceStore.subscribe, window.appearanceStore.snapshot, () => 'system:');
    const [preference, error] = snapshot.split(':');
    const active = modes.find(mode => mode.value === preference) ?? modes[2];
    const [open, setOpen] = useState(false);
    const [focused, setFocused] = useState(0);
    const root = useRef<HTMLDivElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const items = useRef<Array<HTMLButtonElement | null>>([]);
    const menuId = useId();
    const close = (restoreFocus = false) => { setOpen(false); if (restoreFocus) trigger.current?.focus(); };
    const show = (index = modes.findIndex(mode => mode.value === active.value)) => { setFocused(index); setOpen(true); };
    useEffect(() => { if (open) items.current[focused]?.focus(); }, [open, focused]);
    useEffect(() => {
        if (!open) return;
        const outside = (event: PointerEvent) => { if (!root.current?.contains(event.target as Node)) setOpen(false); };
        document.addEventListener('pointerdown', outside);
        return () => document.removeEventListener('pointerdown', outside);
    }, [open]);
    return <div className="appearance-control" ref={root} onBlur={event => { if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setOpen(false); }}>
        <button ref={trigger} type="button" className="account-icon-button appearance-trigger" aria-label={`Apariencia: ${active.label}`} title={`Apariencia: ${active.label}`} aria-haspopup="menu" aria-expanded={open} aria-controls={open ? menuId : undefined} onClick={() => open ? close() : show()} onKeyDown={event => {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') { event.preventDefault(); show(event.key === 'ArrowDown' ? 0 : modes.length - 1); }
        }}><AppearanceIcon mode={active.value} /></button>
        {open && <div id={menuId} role="menu" aria-label="Apariencia" className="appearance-menu" onKeyDown={event => {
            if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(true); }
            else if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
                event.preventDefault();
                setFocused(event.key === 'Home' ? 0 : event.key === 'End' ? modes.length - 1 : (focused + (event.key === 'ArrowDown' ? 1 : -1) + modes.length) % modes.length);
            } else if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && event.key !== ' ') {
                const index = modes.findIndex(mode => mode.label.toLocaleLowerCase('es').startsWith(event.key.toLocaleLowerCase('es')));
                if (index >= 0) { event.preventDefault(); setFocused(index); }
            }
        }}>
            {modes.map((mode, index) => <button key={mode.value} ref={node => { items.current[index] = node; }} type="button" role="menuitemradio" aria-checked={active.value === mode.value} tabIndex={focused === index ? 0 : -1} onFocus={() => setFocused(index)} onClick={() => { window.appearanceStore.set(mode.value); close(true); }}>
                <span className="appearance-check" aria-hidden="true">{active.value === mode.value ? '•' : ''}</span><AppearanceIcon mode={mode.value} /><span>{mode.label}</span>
            </button>)}
        </div>}
        {error && <p role="alert" className="appearance-error">{error}</p>}
    </div>;
}
