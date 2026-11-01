import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';

export default function MemberBenefits({ connected }: { connected: boolean }) {
    const busy = useRef(false);
    const [processing, setProcessing] = useState(false);
    const [consent, setConsent] = useState(false);
    const [message, setMessage] = useState('');
    async function enter() {
        if (busy.current) return;
        busy.current = true; setProcessing(true); setMessage('');
        try {
            const csrf = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='))?.slice(11);
            const response = await fetch('/cuenta/beneficios', {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(csrf || '') },
                body: JSON.stringify({ consent }),
            });
            const data = await response.json();
            if (response.ok && typeof data.ticket === 'string' && data.entry_url === 'https://jakawi.com/club/access') {
                window.location.replace(data.entry_url + '#' + data.ticket); return;
            }
            if (response.ok && typeof data.authorization_url === 'string' && /^https:\/\/jakawi\.com\/identity\/club\/link\/[a-f0-9-]+$/.test(data.authorization_url)) {
                window.location.replace(data.authorization_url); return;
            }
            if (response.ok && data.confirm_email) { router.post('/cuenta/jakawi/activar'); return; }
            setMessage(response.status === 401 || response.status === 419 ? 'Tu sesión venció. Actualiza la página e inicia sesión para reintentar.' : response.status === 422 ? 'Confirma tu autorización para conectar el acceso.' : response.status === 429 ? 'Espera unos momentos antes de reintentar el acceso.' : data.message || 'No pudimos abrir tu acceso. Actualiza la página y vuelve a intentarlo.');
        } catch { setMessage('No pudimos conectar. Tu membresía se conserva. Reintenta el acceso.'); }
        finally { busy.current = false; setProcessing(false); }
    }
    return <section className="feature-card mt-8 border-2 border-primary" aria-labelledby="benefits-title">
        <h2 id="benefits-title" className="text-2xl font-extrabold">¡Tu membresía JAKAWI está activa!</h2>
        <p className="mt-3 text-text-secondary">Tu compra está confirmada. Disfruta tus beneficios con tu cuenta JAKAWI.</p>
        {!connected && <label className="mt-5 flex items-start gap-3 text-sm"><input type="checkbox" className="mt-1 shrink-0" checked={consent} onChange={event => setConsent(event.target.checked)} /><span>Autorizo compartir mi nombre, correo verificado y teléfono para crear o conectar mi acceso JAKAWI y vincular mi compra. Si ya tengo una cuenta, confirmaré la conexión desde esa cuenta.</span></label>}
        <button type="button" className="btn btn-primary mt-5 w-full whitespace-normal sm:w-auto" disabled={processing || (!connected && !consent)} aria-busy={processing} onClick={() => void enter()}>{processing ? 'Preparando tu acceso…' : connected ? 'Entrar y disfrutar mis beneficios' : 'Activar mi acceso y disfrutar mis beneficios'}</button>
        {message && <p role="status" className="mt-4 text-sm">{message}</p>}
        <p className="mt-3 text-sm text-text-secondary">Si el acceso vence, vuelve aquí y reinténtalo. Tu compra se conserva.</p>
    </section>;
}
