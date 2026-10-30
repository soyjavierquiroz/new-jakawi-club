import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { ErrorText, PageFrame } from '../../components/UI';
import { commissionStatus, EconomicCutoff, EconomicHistory, EconomicNav, EconomicPages, economicDate, type Audit, type Pagination } from '../../components/CommissionUI';

type Entry = { id: number; version: number; event_id: string; partner_id: string | null; partner_name: string | null; event_type: string; com_reference_type: string; com_reference_id: string; affiliate_code: string | null; rule_id: number | null; commission_event_id: number | null; original_event_id: number; latest_event_id: number; amount: string | null; currency: string | null; status: string; eligible: boolean; review_required: boolean; after_cutoff: boolean; economic_occurred_at: string | null; rule_active: boolean; history: Audit[]; created_at: string };
function EntryCard({ entry }: { entry: Entry }) {
    const form = useForm({ action: 'evaluate', version: entry.version, reason: '' });
    function submit(event: FormEvent) { event.preventDefault(); form.post(`/admin/comisiones/${entry.id}/revision`, { preserveScroll: true }); }
    const canApprove = entry.status === 'candidate' && entry.eligible && !entry.review_required && entry.rule_id !== null && entry.rule_active && entry.after_cutoff && entry.amount !== null;
    return <article className="feature-card min-w-0" data-ledger-id={entry.id}>
        <div className="flex flex-wrap justify-between gap-4"><div><p className="eyebrow">#{entry.id} · {commissionStatus[entry.status]}</p><h2 className="mt-3 break-all text-xl font-bold">{entry.com_reference_type}: {entry.com_reference_id}</h2></div><p className="font-semibold">{entry.amount !== null ? `${entry.amount} ${entry.currency}` : 'Sin importe asignado'}</p></div>
        <div className="mt-4 grid gap-3 text-sm sm:grid-cols-2"><p className="break-words">Partner original: {entry.partner_name || entry.partner_id || 'No informado'}<span className="mt-1 block text-text-secondary">{entry.event_type}</span></p><p>Afiliado verificado: <strong>{entry.affiliate_code || 'Sin referente CLUB'}</strong></p><p className="break-all">Evento original: {entry.event_id}</p><p>Regla aplicada: {entry.rule_id ? `#${entry.rule_id} · evento interno #${entry.commission_event_id}` : 'Sin regla activa aplicable'}</p><p>Hecho económico COM: {entry.economic_occurred_at ? `${economicDate(entry.economic_occurred_at)} (Bolivia)` : 'Sin confirmación'}</p><p className="font-semibold">{!entry.after_cutoff ? 'Fuera del período económico CLUB' : !entry.eligible ? 'Sin referente verificado o confirmación vigente' : entry.rule_id === null ? 'Sin regla activa aplicable · Sin obligación económica' : !entry.rule_active ? 'Regla aplicada inactiva · Aprobación bloqueada' : entry.status === 'candidate' ? 'Pendiente de aprobación manual' : commissionStatus[entry.status]}</p></div>
        {entry.review_required && <p role="status" className="message mt-4">Requiere revisión por un cambio de estado o atribución en COM. La valoración histórica se conserva.</p>}
        {entry.status !== 'discarded' && <form onSubmit={submit} className="mt-5 grid gap-4 border-t border-border pt-5 md:grid-cols-2">
            <div><label className="label" htmlFor={`action-${entry.id}`}>Decisión administrativa</label><select id={`action-${entry.id}`} className="input w-full" value={form.data.action} onChange={e => form.setData('action', e.target.value)}><option value="evaluate">Evaluar / revisar candidato</option><option value="approve" disabled={!canApprove}>Aprobar comisión</option><option value="discard">Descartar comisión</option></select></div>
            <div><label className="label" htmlFor={`reason-${entry.id}`}>Motivo / evidencia revisada</label><textarea id={`reason-${entry.id}`} className="input w-full" required minLength={5} maxLength={2000} rows={2} value={form.data.reason} onChange={e => form.setData('reason', e.target.value)} /><ErrorText>{form.errors.reason}</ErrorText></div>
            <div className="md:col-span-2"><ErrorText>{(form.errors as Record<string, string>).review || form.errors.action || form.errors.version}</ErrorText><button className="btn btn-secondary" disabled={form.processing} type="submit">REGISTRAR DECISIÓN</button><p className="mt-3 text-xs text-text-secondary">Aprobar exige operación posterior al corte, referente CLUB verificado, regla activa e importe exacto. La decisión es manual y no ejecuta pagos.</p></div>
        </form>}
        <EconomicHistory history={entry.history} />
    </article>;
}
export default function CommissionLedger({ entries, filters, economic_cutoff }: { entries: Pagination<Entry>; filters: { status?: string }; economic_cutoff: string }) {
    const [status, setStatus] = useState(filters.status || '');
    function filter(event: FormEvent) { event.preventDefault(); router.get('/admin/comisiones', { ...(status ? { status } : {}) }); }
    return <><Head title="Libro de comisiones" /><PageFrame><p className="eyebrow">ADMINISTRACIÓN CLUB</p><h1 className="section-title mt-4">LIBRO DE COMISIONES</h1><EconomicNav /><EconomicCutoff value={economic_cutoff} /><p className="max-w-3xl text-text-secondary">Una entrada por operación COM. Las conversiones sin regla se conservan sin importe; las operaciones elegibles con regla activa requieren aprobación administrativa manual.</p>
        <form onSubmit={filter} className="my-8 flex flex-wrap items-end gap-4"><div><label className="label" htmlFor="ledger-status">Estado</label><select id="ledger-status" className="input" value={status} onChange={e => setStatus(e.target.value)}><option value="">Todos</option>{['registered','candidate','approved','discarded'].map(v => <option key={v} value={v}>{commissionStatus[v]}</option>)}</select></div><button type="submit" className="btn btn-secondary">Filtrar</button></form>
        <p className="mb-5 text-sm">{entries.total} operaciones. No hay pagos ni desembolsos automáticos.</p>
        {entries.data.length ? <div className="space-y-5">{entries.data.map(entry => <EntryCard key={`${entry.id}:${entry.version}`} entry={entry} />)}</div> : <p className="feature-card">No hay operaciones para mostrar con estos filtros.</p>}
        <EconomicPages page={entries} />
    </PageFrame></>;
}
