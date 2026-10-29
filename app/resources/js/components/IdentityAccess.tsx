import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export type IdentityState = {
    status: string;
    connected: boolean;
    can_activate?: boolean;
    needs_email_confirmation?: boolean;
    authorization_url?: string | null;
    retry_at?: string | null;
    message?: string;
};

export default function IdentityAccess({ initial }: { initial?: IdentityState }) {
    const [state, setState] = useState<IdentityState>(initial ?? { status: 'DISABLED', connected: false });
    const [unavailable, setUnavailable] = useState(false);
    const [clock, setClock] = useState(Date.now());
    const form = useForm({});
    useEffect(() => { if (initial) setState(initial); }, [initial]);
    useEffect(() => {
        if (state.status === 'DISABLED') return;
        const controller = new AbortController();
        async function refresh() {
            if (document.visibilityState === 'hidden') return;
            try {
                const response = await fetch('/cuenta/jakawi/estado', {
                    credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal,
                });
                if (!response.ok) { setUnavailable(true); return; }
                const next: IdentityState = await response.json();
                if (controller.signal.aborted) return;
                setState(next); setUnavailable(false); setClock(Date.now());
            } catch { if (!controller.signal.aborted) setUnavailable(true); }
        }
        void refresh();
        window.addEventListener('focus', refresh);
        const timer = ['AUTH_REQUIRED', 'QUEUED', 'SENDING', 'RETRY'].includes(state.status)
            ? window.setInterval(refresh, 15000) : undefined;
        return () => { controller.abort(); window.removeEventListener('focus', refresh); if (timer) window.clearInterval(timer); };
    }, [state.status]);
    const retryAt = state.retry_at ? Date.parse(state.retry_at) : 0;
    useEffect(() => {
        if (!retryAt || retryAt <= Date.now()) return;
        const timer = window.setTimeout(() => setClock(Date.now()), Math.min(retryAt - Date.now() + 100, 86400000));
        return () => window.clearTimeout(timer);
    }, [retryAt]);
    if (state.status === 'DISABLED') return null;
    return <section className="feature-card mt-10" aria-labelledby="com-access-title">
        <h2 id="com-access-title" className="text-2xl font-extrabold">Tu acceso a JAKAWI.COM</h2>
        <p className="mt-3 text-sm text-text-secondary" role="status">{state.message}</p>
        {state.connected ? <><a className="btn btn-primary mt-5" href="https://jakawi.com/login" rel="noreferrer">Entrar a JAKAWI.COM</a><p className="mt-3 text-sm text-text-secondary">Usa el inicio de sesión o la recuperación de acceso propios de JAKAWI.COM.</p></>
            : state.authorization_url ? <a className="btn btn-primary mt-5" href={state.authorization_url} rel="noreferrer">CONTINUAR EN JAKAWI.COM</a>
                : state.can_activate ? <form className="mt-5" onSubmit={(event) => { event.preventDefault(); form.post('/cuenta/jakawi/activar', { preserveScroll: true }); }}>
                    <p className="mb-4 text-sm text-text-secondary">Al activar, autorizas compartir tu nombre, correo verificado y teléfono con JAKAWI.COM para crear o conectar tu acceso.</p>
                    <button type="submit" className="btn btn-primary" disabled={form.processing || retryAt > clock} aria-busy={form.processing}>ACTIVAR MI ACCESO A JAKAWI.COM</button>
                    {retryAt > clock && <p className="mt-3 text-sm text-text-secondary">Espera unos momentos antes de reintentar.</p>}
                </form> : null}
        {unavailable && <p className="mt-3 text-sm text-text-secondary" role="status">No pudimos actualizar la conexión. Tu cuenta CLUB sigue disponible.</p>}
    </section>;
}
