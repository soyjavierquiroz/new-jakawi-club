import { Head, router, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { ErrorText, PageFrame } from '../../components/UI';
import { commissionMoney, EconomicHistory, EconomicNav, EconomicPages, economicDate, type Audit, type Pagination } from '../../components/CommissionUI';

type Entry = { id: number; version: number; beneficiary_name?: string; status: string; review_required: boolean; amount: string | null; currency: string | null; history: Audit[]; created_at: string };
const categories: Record<string, string> = { approved: 'Aprobadas', evidence: 'Pendientes de evidencia', review: 'Requieren revisión', pending_payment: 'Pendientes de pago' };
function EntryCard({ entry }: { entry: Entry }) {
    const form = useForm({ action: 'evaluate', version: entry.version, reason: '' });
    function submit(event: FormEvent) { event.preventDefault(); form.post(`/admin/comisiones/${entry.id}/revision`, { preserveScroll: true }); }
    return <article className="feature-card min-w-0" data-ledger-id={entry.id}>
        <div className="flex flex-wrap justify-between gap-4"><div><p className="eyebrow">{entry.review_required ? 'Requiere revisión' : entry.status === 'approved' ? 'Aprobada · Pendiente de liquidación' : entry.status === 'discarded' ? 'Descartada' : 'Pendiente de evidencia'}</p><h2 className="mt-3 text-xl font-bold">{entry.beneficiary_name || 'Beneficiario por verificar'}</h2><p className="mt-2 text-sm text-text-secondary">{economicDate(entry.created_at)}</p></div><p className="text-xl font-semibold">{commissionMoney(entry.amount, entry.currency)}</p></div>
        {entry.review_required && <><p className="message mt-4">La aprobación está bloqueada. La valoración histórica se conserva.</p><details className="mt-4"><summary className="cursor-pointer font-semibold">Herramientas de revisión</summary><form onSubmit={submit} className="mt-4 space-y-3"><label className="label" htmlFor={`action-${entry.id}`}>Acción</label><select id={`action-${entry.id}`} className="input" value={form.data.action} onChange={e => form.setData('action', e.target.value)}><option value="evaluate">Revalidar evidencia</option><option value="discard">Descartar</option></select><label className="label" htmlFor={`reason-${entry.id}`}>Motivo</label><textarea id={`reason-${entry.id}`} className="input w-full" required minLength={5} maxLength={2000} value={form.data.reason} onChange={e => form.setData('reason', e.target.value)} /><ErrorText>{form.errors.reason || (form.errors as Record<string, string>).review}</ErrorText><button className="btn btn-secondary" disabled={form.processing}>Registrar revisión</button></form></details></>}
        <EconomicHistory history={entry.history} />
    </article>;
}
type Evidence = { id: number; status: string; reason: string; requires_review: boolean };
export default function CommissionLedger({ entries, filters, evidence }: { evidence: Evidence[]; entries: Pagination<Entry>; filters: { status?: string } }) {
    const status = filters.status || '';
    return <><Head title="Comisiones" /><PageFrame><p className="eyebrow">ADMINISTRACIÓN CLUB</p><h1 className="section-title mt-4">COMISIONES</h1><EconomicNav /><p className="max-w-3xl text-text-secondary">Las primeras adquisiciones verificadas se aprueban automáticamente. La liquidación y el desembolso se controlan por separado; aprobar no ejecuta un pago.</p>
        <nav aria-label="Estado de comisiones" className="my-6 flex flex-wrap gap-3">{Object.entries({ '': 'Todas', ...categories }).map(([value, label]) => <button key={value} className={`btn ${status === value ? 'btn-primary' : 'btn-secondary'}`} onClick={() => router.get('/admin/comisiones', value ? { status: value } : {})}>{label}</button>)}</nav>
        <p className="mb-5 text-sm">{entries.total} comisiones{status === 'pending_payment' ? ' aprobadas y pendientes de liquidación' : ''}. El modelo actual no registra pagos efectuados.</p>
        {entries.data.length ? <div className="space-y-5">{entries.data.map(entry => <EntryCard key={`${entry.id}:${entry.version}`} entry={entry} />)}</div> : <p className="feature-card">No hay comisiones en esta categoría.</p>}
        <EconomicPages page={entries} />
        {evidence.length > 0 && <section className="my-6 space-y-3"><h2 className="text-xl font-bold">Verificaciones pendientes</h2>{evidence.map(item => <article className="feature-card" key={item.id}><p className="font-semibold">{!item.requires_review ? 'Pendiente de evidencia' : 'Requiere revisión'}</p><p className="mt-2">{item.reason}</p><details className="mt-3"><summary className="cursor-pointer">Revisar evidencia</summary><button className="btn btn-secondary mt-3" onClick={() => router.post(`/admin/comisiones/evidencia/${item.id}/reintentar`, {}, { preserveScroll: true })}>Reconsultar evidencia</button></details></article>)}</section>}
    </PageFrame></>;
}
