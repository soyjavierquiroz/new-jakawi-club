import { Head, Link, usePage } from '@inertiajs/react';
import AdminLayout, { type AdminLink } from '../../components/admin/AdminLayout';
import { commissionMoney } from '../../components/CommissionUI';

type Summary = { partners: number; individuals: number; acquisitions: number; approved: number; amounts: { currency: string; amount: string }[]; evidence: number; review: number };
export default function Dashboard({ summary }: { summary: Summary }) {
    const links = usePage<{ admin_navigation: AdminLink[] }>().props.admin_navigation;
    const ledger = links.some(link => link.href === '/admin/comisiones');
    const cards = [
        { title: 'Adquisiciones registradas', value: summary.acquisitions, note: 'Activaciones de membresía registradas en CLUB.', filter: '', tone: 'border-brand' },
        { title: 'Comisiones aprobadas', value: summary.approved, note: 'Aprobadas y sin revisión pendiente.', filter: 'approved', tone: 'border-success' },
        { title: 'Pendientes de evidencia', value: summary.evidence, note: 'Entradas pendientes y última evidencia sin comisión emitida.', filter: 'evidence', tone: 'border-reward' },
        { title: 'Casos para revisión', value: summary.review, note: 'Alertas del libro y evidencia inválida o desconocida.', filter: 'review', tone: 'border-reward' },
    ];
    return <><Head title="Administración CLUB" /><AdminLayout>
        <p className="eyebrow text-text-secondary">OPERACIÓN CLUB</p>
        <h1 className="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl">Resumen administrativo</h1>
        <p className="mt-4 max-w-2xl text-text-secondary">Afiliación, adquisiciones y comisiones en un solo lugar. Datos registrados en CLUB, consultados al abrir este resumen.</p>
        <section aria-labelledby="affiliation-title" className="feature-card my-8 flex flex-wrap items-center justify-between gap-6">
            <div><h2 id="affiliation-title" className="text-lg font-bold">Resumen de afiliación</h2><p className="mt-2 text-sm text-text-secondary">Beneficiarios individuales con adquisiciones atribuidas y Partners habilitados.</p></div>
            <div className="flex gap-8"><div><p className="text-3xl font-extrabold">{summary.individuals}</p><p className="mt-1 text-xs font-semibold text-text-secondary">Individuales</p></div><div><p className="text-3xl font-extrabold">{summary.partners}</p><p className="mt-1 text-xs font-semibold text-text-secondary">Partners habilitados</p></div></div>
        </section>
        <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">{cards.map(card => <section className={`feature-card flex flex-col border-t-4 ${card.tone}`} key={card.title}>
            <h2 className="text-sm font-bold">{card.title}</h2><p className="my-4 text-4xl font-extrabold tabular-nums">{card.value}</p><p className="mb-5 text-sm text-text-secondary">{card.note}</p>
            {ledger && <Link className="mt-auto text-sm font-bold underline underline-offset-4" href={'/admin/comisiones' + (card.filter ? '?status=' + card.filter : '')}>Ver libro →</Link>}
        </section>)}</div>
        <section className="feature-card mt-6 border-t-4 border-success"><div className="flex flex-wrap items-start justify-between gap-5"><div><p className="eyebrow text-text-secondary">COMISIONES PENDIENTES DE PAGO</p><h2 className="mt-3 text-xl font-bold">Aprobadas pendientes de liquidación</h2></div><span className="rounded-chip bg-success-surface px-4 py-2 text-sm font-bold">{summary.approved} comisiones</span></div>
            <div className="my-5 flex flex-wrap gap-6">{summary.amounts.length ? summary.amounts.map(row => <p className="text-2xl font-extrabold" key={row.currency}>{commissionMoney(row.amount, row.currency)}</p>) : <p>Sin comisiones aprobadas registradas.</p>}</div>
            <p className="max-w-3xl text-sm text-text-secondary">Importes del libro vigente, separados por moneda. CLUB no registra desembolsos ni conciliación de pagos: el saldo efectivo pendiente de pago no está disponible. Aprobar una comisión no ejecuta su pago.</p>
            {ledger && <Link href="/admin/comisiones?status=pending_payment" className="btn btn-primary mt-5">Consultar comisiones</Link>}
        </section>
        <section className="mt-10"><h2 className="text-xl font-bold">Herramientas de administración</h2><div className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{links.filter(link => link.href !== '/admin').map(link => <Link href={link.href} className="feature-card flex items-center justify-between gap-4 font-bold hover:border-brand" key={link.href}>{link.label}<span aria-hidden="true">→</span></Link>)}</div></section>
    </AdminLayout></>;
}
