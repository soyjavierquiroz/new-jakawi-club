import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { ErrorText, PageFrame } from '../../components/UI';
import { commissionStatus, EconomicCutoff, EconomicHistory, EconomicNav, EconomicPages, economicDate, type Audit, type Pagination } from '../../components/CommissionUI';

type Rule = { id: number; partner_id: string; beneficiary_type: string; membership_status: string; nature: string; rule_version: string | null; event_type: string; fixed_amount: string; currency: string; effective_at: string; status: string; history: Audit[] };
function RuleCard({ rule }: { rule: Rule }) {
    const form = useForm({ status: rule.status === 'active' ? 'inactive' : 'active', reason: '' });
    function submit(event: FormEvent) { event.preventDefault(); form.post(`/admin/reglas-comisiones/${rule.id}/estado`, { preserveScroll: true }); }
    return <article className="feature-card min-w-0" data-rule-id={rule.id}>
        <p className="eyebrow">Regla #{rule.id} · {commissionStatus[rule.status]}</p><h2 className="mt-3 break-all text-xl font-bold">{rule.partner_id}</h2>
        <p className="mt-3 break-all text-sm">Beneficiario: {rule.beneficiary_type} · Membresía: {rule.membership_status} · {rule.nature} · Versión: {rule.rule_version || rule.id} · Evento: {rule.event_type}</p><p className="mt-2 font-semibold">{rule.fixed_amount} {rule.currency} por conversión</p><p className="mt-2 text-sm text-text-secondary">Vigente desde {economicDate(rule.effective_at)} (Bolivia)</p>
        <form onSubmit={submit} className="mt-5 space-y-3"><label className="label" htmlFor={`rule-reason-${rule.id}`}>Motivo de {form.data.status === 'active' ? 'activación' : 'inactivación'}</label><input className="input w-full" id={`rule-reason-${rule.id}`} required minLength={5} maxLength={2000} value={form.data.reason} onChange={e => form.setData('reason', e.target.value)} /><ErrorText>{form.errors.reason || (form.errors as Record<string, string>).rule}</ErrorText><button className="btn btn-secondary" disabled={form.processing}>{form.data.status === 'active' ? 'ACTIVAR REGLA' : 'INACTIVAR REGLA'}</button></form>
        <EconomicHistory history={rule.history} />
    </article>;
}
export default function CommissionRules({ rules, economic_cutoff, acquisition_contract_ready }: { rules: Pagination<Rule>; economic_cutoff: string; acquisition_contract_ready: boolean }) {
    const form = useForm({ beneficiary_type: 'INDIVIDUAL', membership_status: 'FREE', nature: 'ONE_TIME_ACQUISITION', partner_id: '*', event_type: 'membership.activated', fixed_amount: '', currency: 'BOB', effective_at: '' });
    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform(data => ({ ...data, effective_at: `${data.effective_at}:00-04:00` }));
        form.post('/admin/reglas-comisiones', { onSuccess: () => form.reset(), preserveScroll: true });
    }
    return <><Head title="Reglas de comisiones" /><PageFrame>
        <p className="eyebrow">ADMINISTRACIÓN CLUB</p><h1 className="section-title mt-4">REGLAS DE COMISIONES</h1><EconomicNav /><a href="/admin/partners-afiliados" className="my-4 inline-block underline">Administrar Partners afiliados</a><EconomicCutoff value={economic_cutoff} />
        <p className="max-w-3xl text-text-secondary">Solo montos fijos. Cada cambio económico requiere una nueva versión; las valoraciones existentes se conservan. Activar una regla permite evaluar candidatos, sin aprobar comisiones automáticamente.</p>
        {!acquisition_contract_ready && <p role="status" className="feature-card my-5">Tarifas de adquisición configurables. Emisión bloqueada: COM aún no certifica primera compra histórica ni estado FREE/PAID vigente a la fecha de adquisición. Una activación previa o un correo verificado no acreditan membresía de pago vigente.</p>}
        <form onSubmit={submit} className="feature-card my-8 grid gap-5 md:grid-cols-2" id="commission-rule-form">
            <div className="md:col-span-2"><h2 className="text-xl font-bold">Nueva regla en borrador</h2><p className="mt-2 text-sm text-text-secondary">Introduce el ID original del partner y el tipo real de evento COM. Moneda: código de 3 letras. Importe exacto con punto y hasta 4 decimales.</p></div>
            <div><label className="label" htmlFor="rule-beneficiary">Tipo de beneficiario</label><select id="rule-beneficiary" className="input w-full" value={form.data.beneficiary_type} onChange={e=>{ form.setData('beneficiary_type',e.target.value); form.setData('membership_status',e.target.value === 'PARTNER' ? 'ANY' : 'FREE'); }}><option value="INDIVIDUAL">INDIVIDUAL</option><option value="PARTNER">PARTNER</option></select></div>
            <div><label className="label" htmlFor="rule-membership">Membresía al adquirir</label><select id="rule-membership" className="input w-full" value={form.data.membership_status} onChange={e=>form.setData('membership_status',e.target.value)}>{form.data.beneficiary_type === 'PARTNER' ? <option value="ANY">No aplica (establecimiento)</option> : <><option value="FREE">FREE</option><option value="PAID">PAID vigente COM</option></>}</select></div>
            <div><label className="label" htmlFor="rule-partner">ID beneficiario para membresía (* = escala del tipo); Partner oferta para canje</label><input id="rule-partner" className="input w-full" required maxLength={190} value={form.data.partner_id} onChange={e => form.setData('partner_id', e.target.value)} /><ErrorText>{form.errors.partner_id}</ErrorText></div>
            <div><label className="label" htmlFor="rule-event">Evento remunerable</label><input id="rule-event" className="input w-full" required maxLength={100} value={form.data.event_type} onChange={e => { form.setData('event_type', e.target.value); form.setData('nature', e.target.value === 'membership.activated' ? 'ONE_TIME_ACQUISITION' : 'LEGACY'); }} /><ErrorText>{form.errors.event_type}</ErrorText></div>
            <div><label className="label" htmlFor="rule-amount">Monto fijo por conversión</label><input id="rule-amount" className="input w-full" inputMode="decimal" required value={form.data.fixed_amount} onChange={e => form.setData('fixed_amount', e.target.value)} /><ErrorText>{form.errors.fixed_amount}</ErrorText></div>
            <div><label className="label" htmlFor="rule-currency">Moneda</label><input id="rule-currency" className="input w-full" required minLength={3} maxLength={3} value={form.data.currency} onChange={e => form.setData('currency', e.target.value.toUpperCase())} /><ErrorText>{form.errors.currency}</ErrorText></div>
            <div><label className="label" htmlFor="rule-date">Entrada en vigor (hora de Bolivia)</label><input id="rule-date" type="datetime-local" className="input w-full min-w-0" required value={form.data.effective_at} onChange={e => form.setData('effective_at', e.target.value)} /><ErrorText>{form.errors.effective_at}</ErrorText></div>
            <div className="flex items-end"><button type="submit" className="btn btn-primary w-full" disabled={form.processing}>GUARDAR BORRADOR</button></div>
        </form>
        <p className="mb-5 text-sm">{rules.total} reglas configuradas. Cada tarifa requiere aprobación administrativa de sus comisiones.</p>
        {rules.data.length ? <div className="grid gap-5 lg:grid-cols-2">{rules.data.map(rule => <RuleCard key={`${rule.id}:${rule.status}`} rule={rule} />)}</div> : <p className="feature-card">No hay reglas configuradas. Los candidatos se conservarán sin importe.</p>}
        <EconomicPages page={rules} />
    </PageFrame></>;
}
