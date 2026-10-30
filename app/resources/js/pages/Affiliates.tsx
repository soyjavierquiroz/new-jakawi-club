import { Head, Link } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { commissionStatus, EconomicCutoff, EconomicPages, economicDate, type Pagination } from '../components/CommissionUI';
import { Arrow, PageFrame } from '../components/UI';
import { CommunityWhatsAppIcon } from '../components/CommunityWhatsAppIcon';

type Conversion = {
    occurred_at: string;
    partner_name: string | null;
    partner_id: string | null;
    event_type: string;
    conversion_status: 'confirmed';
    com_reference_type: string;
    com_reference_id: string;
};
type Props = {
    economic_cutoff?: string;
    commission_entries?: Pagination<{ id: number; com_reference_type: string; com_reference_id: string; status: string; amount: string | null; currency: string | null; review_required: boolean; eligible: boolean; rule_id: number | null; created_at: string }>;
    economics?: { pending: number; approved: number; review: number; currencies: { currency: string; pending: string; approved: string; review: string }[] };
    campaign: { name: string; personal_url: string; long_url: string; status: 'active' | 'paused' | 'finished' };
    conversions: { data: Conversion[]; total: number; current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null };
};
const dates = new Intl.DateTimeFormat('es-BO', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/La_Paz' });

export default function Affiliates({ campaign, conversions, economics, commission_entries, economic_cutoff }: Props) {
    const input = useRef<HTMLTextAreaElement>(null);
    const [message, setMessage] = useState('');
    async function copy() {
        try {
            await navigator.clipboard.writeText(campaign.personal_url);
            setMessage('¡Enlace copiado!');
        } catch {
            input.current?.focus();
            input.current?.select();
            setMessage('Selecciona y copia el enlace.');
        }
    }
    const whatsapp = `https://wa.me/?text=${encodeURIComponent(`${campaign.name}\n${campaign.personal_url}`)}`;

    return <><Head title="Mis enlaces y conversiones" /><PageFrame>
        <Link href="/dashboard" className="text-sm font-semibold underline underline-offset-4">Volver a mi cuenta</Link>
        <div className="mt-8 flex flex-wrap items-end justify-between gap-6">
            <div><p className="eyebrow">MI ESPACIO DE AFILIADO</p><h1 className="mt-3 text-3xl font-extrabold tracking-tight sm:text-5xl">MIS ENLACES</h1><p className="mt-4 max-w-xl text-text-secondary">Comparte tu enlace personal y consulta las conversiones verificadas de tus recomendaciones.</p></div>
            <a href="#mis-conversiones" className="text-sm font-semibold underline underline-offset-4">Mis conversiones ↓</a>
        </div>
        <div className="my-8 grid gap-5 lg:grid-cols-[2fr_1fr]">
            <article className="feature-card min-w-0">
                <p className="eyebrow">{campaign.status === 'finished' ? 'HISTORIAL DE ENLACES' : 'PARTNER'}</p><h2 className="mt-3 text-2xl font-extrabold tracking-tight">{campaign.name}</h2>
                <p className="mt-3 text-sm font-semibold">Estado: {campaign.status === 'active' ? 'Activa' : campaign.status === 'paused' ? 'Pausada' : 'Finalizada'}</p>
                {campaign.status !== 'active' && <p className="mt-2 text-sm text-text-secondary">{campaign.status === 'finished' ? 'Esta oferta ha finalizado. Tu enlace se conserva en el historial.' : 'Esta oferta no está disponible por ahora. Tu enlace se conserva.'}</p>}
                <label htmlFor="affiliate-link" className="mb-3 mt-6 block text-sm font-semibold">Tu enlace corto permanente</label>
                <textarea ref={input} id="affiliate-link" readOnly rows={3} value={campaign.personal_url} className="input w-full resize-none break-all text-sm" onFocus={event => event.target.select()} />
                <div className="mt-4 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                    <button type="button" className="btn btn-primary" onClick={() => void copy()}>COPIAR ENLACE <Arrow /></button>
                    <a href={whatsapp} target="_blank" rel="noopener noreferrer" className="btn community-whatsapp-cta text-center"><CommunityWhatsAppIcon /> COMPARTIR POR WHATSAPP</a>
                </div>
                <details className="mt-5 text-sm"><summary className="font-semibold">Ver destino comercial vigente</summary><p className="mt-3 break-all text-text-secondary">{campaign.long_url}</p></details>
                <p role="status" aria-live="polite" className="mt-3 min-h-5 text-sm text-text-secondary">{message}</p>
            </article>
            <aside className="rounded-card border border-border bg-surface-subtle p-7">
                <p className="text-xs font-semibold tracking-widest text-text-secondary">CONVERSIONES CONFIRMADAS</p>
                <p data-conversion-count className="my-4 text-6xl font-extrabold">{conversions.total}</p>
                <p className="text-sm text-text-secondary">Total de tus operaciones COM confirmadas. Cada referencia comercial se cuenta una sola vez.</p>
                <p className="mt-5 border-t border-border pt-4 text-sm text-text-secondary">Las comisiones requieren una operación posterior al corte, referente verificado, regla activa y aprobación manual. No hay pagos automáticos.</p>
            </aside>
        </div>
        {economics && <section id="mis-comisiones" className="my-10">
            <h2 className="text-2xl font-extrabold tracking-tight">MIS COMISIONES</h2>{economic_cutoff && <EconomicCutoff value={economic_cutoff} />}
            <div className="my-5 grid gap-4 sm:grid-cols-3"><article className="feature-card"><p className="text-sm text-text-secondary">Pendientes de evaluación</p><p data-pending-commissions className="mt-3 text-3xl font-bold">{economics.pending}</p></article><article className="feature-card"><p className="text-sm text-text-secondary">Comisiones aprobadas</p><p data-approved-commissions className="mt-3 text-3xl font-bold">{economics.approved}</p></article><article className="feature-card"><p className="text-sm text-text-secondary">Requieren revisión</p><p className="mt-3 text-3xl font-bold">{economics.review}</p></article></div>
            <p className="text-sm text-text-secondary">Los candidatos sin regla se conservan sin importe. Las valoraciones pendientes son estimaciones sujetas a revisión; estos totales no son saldos disponibles para retirar.</p>
            {economics.currencies.length ? <div className="mt-5 grid gap-4 sm:grid-cols-2">{economics.currencies.map(total => <article key={total.currency} className="feature-card" data-commission-currency={total.currency}><h3 className="font-bold">{total.currency}</h3><dl className="mt-3 space-y-2 text-sm"><div><dt className="text-text-secondary">Valoración pendiente</dt><dd className="break-all font-semibold">{total.pending} {total.currency}</dd></div><div><dt className="text-text-secondary">Aprobado manualmente</dt><dd className="break-all font-semibold">{total.approved} {total.currency}</dd></div><div><dt className="text-text-secondary">Importes en revisión</dt><dd className="break-all font-semibold">{total.review} {total.currency}</dd></div></dl></article>)}</div> : <p className="mt-4 text-sm">Todavía no hay importes asignados.</p>}
            {commission_entries && commission_entries.data.length > 0 && <><h3 className="mb-4 mt-8 text-lg font-bold">Detalle de comisiones</h3><div className="space-y-3">{commission_entries.data.map(entry => <article key={entry.id} className="feature-card flex flex-wrap justify-between gap-4 text-sm" data-affiliate-commission={entry.id}><div><p className="break-all font-semibold">{entry.com_reference_type}: {entry.com_reference_id}</p><p className="mt-2 text-text-secondary">{economicDate(entry.created_at)} · {commissionStatus[entry.status]}</p>{entry.review_required && <p className="mt-2">Requiere revisión</p>}{entry.status === 'candidate' && !entry.review_required && <p className="mt-2 text-text-secondary">{entry.rule_id === null ? 'Sin regla activa aplicable · Sin obligación económica' : 'Pendiente de aprobación manual'}</p>}</div><p className="break-all font-semibold">{entry.amount === null ? 'Sin importe asignado' : `${entry.amount} ${entry.currency}${entry.status === 'candidate' ? ' · Valoración pendiente' : ''}`}</p></article>)}</div><EconomicPages page={commission_entries} /></>}
        </section>}
        <section id="mis-conversiones" className="mt-12 scroll-mt-8">
            <h2 className="text-2xl font-extrabold tracking-tight">MIS CONVERSIONES</h2>
            <p className="mb-6 mt-3 text-sm text-text-secondary">Operaciones vinculadas a tu código CLUB verificado. Fecha y hora de Bolivia.</p>
            {conversions.data.length === 0 ? <div className="feature-card"><p className="font-semibold">Todavía no tienes conversiones confirmadas.</p><p className="mt-2 text-sm text-text-secondary">Cuando COM confirme una operación con tu enlace, aparecerá aquí.</p></div> : <>
                <div className="overflow-hidden rounded-card border border-border bg-surface">
                    <table className="block w-full table-fixed text-left text-sm md:table">
                        <thead className="hidden bg-surface-subtle text-xs text-text-secondary md:table-header-group"><tr>{['Fecha (Bolivia)', 'Partner', 'Tipo de operación', 'Estado', 'Referencia comercial'].map(label => <th key={label} scope="col" className="p-4 font-semibold">{label}</th>)}</tr></thead>
                        <tbody className="block md:table-row-group">{conversions.data.map(row => <tr key={`${row.com_reference_type}:${row.com_reference_id}`} className="block border-t border-border first:border-t-0 md:table-row">
                            <td className="block break-words p-4 pb-2 md:table-cell md:pb-4"><span className="mb-1 block text-xs text-text-secondary md:hidden">Fecha (Bolivia)</span><time dateTime={row.occurred_at}>{dates.format(new Date(row.occurred_at))}</time></td>
                            <td className="block break-words p-4 py-2 md:table-cell md:py-4"><span className="mb-1 block text-xs text-text-secondary md:hidden">Partner</span>{row.partner_name || row.partner_id || 'No informado por COM'}</td>
                            <td className="block break-all p-4 py-2 md:table-cell md:py-4"><span className="mb-1 block text-xs text-text-secondary md:hidden">Tipo de operación</span>{row.com_reference_type}<span className="mt-1 block text-xs text-text-secondary">{row.event_type}</span></td>
                            <td className="block p-4 py-2 md:table-cell md:py-4"><span className="mb-1 block text-xs text-text-secondary md:hidden">Estado</span><span className="font-semibold">Confirmado</span></td>
                            <td className="block break-all p-4 pt-2 md:table-cell md:pt-4"><span className="mb-1 block text-xs text-text-secondary md:hidden">Referencia comercial</span>{row.com_reference_id}</td>
                        </tr>)}</tbody>
                    </table>
                </div>
                {conversions.last_page > 1 && <nav aria-label="Páginas de conversiones" className="mt-6 flex flex-wrap items-center justify-between gap-4 text-sm">
                    {conversions.prev_page_url ? <Link href={conversions.prev_page_url} className="font-semibold underline underline-offset-4">Anterior</Link> : <span />}
                    <span>Página {conversions.current_page} de {conversions.last_page}</span>
                    {conversions.next_page_url ? <Link href={conversions.next_page_url} className="font-semibold underline underline-offset-4">Siguiente</Link> : <span />}
                </nav>}
            </>}
        </section>
    </PageFrame></>;
}
