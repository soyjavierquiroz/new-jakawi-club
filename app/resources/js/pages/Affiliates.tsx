import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, type FormEvent } from 'react';
import { commissionStatus, EconomicCutoff, EconomicPages, economicDate, type Pagination } from '../components/CommissionUI';
import { AffiliateResultsSummary, type AffiliateResults } from '../components/AffiliateResultsSummary';
import { PageFrame } from '../components/UI';
import { OfferCard, catalogTypes, type CatalogOffer } from '../components/CatalogOffer';

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
    tab: 'explore' | 'links' | 'history' | 'results';
    results: AffiliateResults;
    filters: { q?: string; type?: string; city?: string; availability?: string };
    offers: Pagination<CatalogOffer>;
    cities: string[];
    quick_links: { name: string; url: string }[];
    conversions: { data: Conversion[]; total: number; current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null };
};
const dates = new Intl.DateTimeFormat('es-BO', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/La_Paz' });

export default function Affiliates({ tab, filters, offers, cities, quick_links, conversions, economics, commission_entries, economic_cutoff, results }: Props) {
    const { url } = usePage();
    useEffect(() => {
        const syncAnchor = () => {
            const hash = window.location.hash;
            if (!['#mis-conversiones', '#mis-resultados', '#mis-comisiones'].includes(hash)) return;
            if (tab !== 'results') {
                const params = new URLSearchParams(window.location.search);
                params.set('tab', 'results');
                router.get(`/afiliados?${params.toString()}${hash}`, {}, { replace: true, preserveScroll: true });
            } else {
                requestAnimationFrame(() => document.getElementById(hash.slice(1))?.scrollIntoView());
            }
        };
        syncAnchor();
        window.addEventListener('hashchange', syncAnchor);
        return () => window.removeEventListener('hashchange', syncAnchor);
    }, [tab, url]);
    function apply(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(event.currentTarget).entries()) as Record<string, string>;
        router.get('/afiliados', { ...data, tab: 'explore' }, { preserveState: false });
    }
    // The server cannot receive fragments. Hide Explore while the legacy
    // fragment is resolved so a direct results link never flashes the catalog.
    if (tab !== 'results' && typeof window !== 'undefined' && ['#mis-conversiones', '#mis-resultados', '#mis-comisiones'].includes(window.location.hash)) {
        return <><Head title="Mis resultados" /><PageFrame><p role="status" aria-busy="true">Cargando tus resultados…</p></PageFrame></>;
    }
    return <><Head title="Catálogo de oportunidades" /><PageFrame>
        <Link href="/dashboard" className="text-sm font-semibold underline underline-offset-4">Volver a mi cuenta</Link>
        <div className="mb-8 mt-8"><p className="eyebrow">MI ESPACIO DE AFILIADO</p><h1 className="mt-3 text-3xl font-extrabold tracking-tight sm:text-5xl">OPORTUNIDADES</h1><p className="mt-4 max-w-2xl text-text-secondary">Descubre oportunidades, comparte tus enlaces y sigue tus resultados.</p></div>
        <nav data-catalog-tab={tab} aria-label="Catálogo de afiliados" className="mb-7 grid grid-cols-2 gap-2 sm:grid-cols-4 border-b border-border pb-4 text-center text-sm font-bold">
            {([['explore', 'EXPLORAR'], ['links', 'MIS ENLACES'], ['history', 'HISTORIAL'], ['results', 'MIS RESULTADOS']] as const).map(([value, label]) => <Link key={value} href={`/afiliados?tab=${value}`} aria-current={tab === value ? 'page' : undefined} className={`rounded-control px-2 py-3 ${tab === value ? 'bg-ink text-surface' : 'bg-surface-subtle text-text-secondary'}`}>{label}</Link>)}
        </nav>
        {tab === 'explore' && <form key={JSON.stringify(filters)} onSubmit={apply} className="mb-7 grid grid-cols-2 gap-4 rounded-card border border-border bg-surface-subtle p-4 lg:grid-cols-[2fr_1fr_1fr_1fr_auto]">
            <div className="col-span-2 min-w-0 lg:col-span-1"><label htmlFor="catalog-search" className="label">Buscar por nombre o Partner</label><input id="catalog-search" name="q" type="search" maxLength={120} defaultValue={filters.q || ''} placeholder="¿Qué quieres recomendar?" className="input w-full" /></div>
            <div><label htmlFor="catalog-type" className="label">Tipo</label><select id="catalog-type" name="type" defaultValue={filters.type || ''} className="input w-full min-w-0"><option value="">Todos</option>{Object.entries(catalogTypes).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></div>
            <div><label htmlFor="catalog-city" className="label">Ciudad</label><select id="catalog-city" name="city" defaultValue={filters.city || ''} className="input w-full min-w-0"><option value="">Todas</option>{cities.map(city => <option key={city}>{city}</option>)}</select></div>
            <div><label htmlFor="catalog-availability" className="label">Disponibilidad</label><select id="catalog-availability" name="availability" defaultValue={filters.availability || ''} className="input w-full min-w-0"><option value="">Todas</option><option value="active">Promocionables</option><option value="upcoming">Próximamente</option><option value="paused">No disponibles</option></select></div>
            <button className="btn btn-primary self-end" type="submit">BUSCAR</button>
        </form>}
        {tab === 'links' && quick_links.length > 0 && <aside className="mb-7 rounded-card bg-surface-subtle p-4"><h2 className="text-sm font-bold">ACCESO RÁPIDO</h2><div className="mt-3 flex flex-wrap gap-4">{quick_links.map(link => <a key={link.url} className="text-sm font-semibold underline" href={link.url} target="_blank" rel="noopener noreferrer">{link.name}</a>)}</div></aside>}
        {tab === 'history' && <p className="mb-6 text-sm text-text-secondary">Tus ofertas finalizadas o retiradas conservan sus enlaces permanentes y sus resultados históricos.</p>}
        {tab !== 'results' ? <>
            <p className="mb-4 text-sm text-text-secondary" data-offer-total>{offers.total} {tab === 'explore' ? 'oportunidades' : 'enlaces personales'}</p>
            {offers.data.length ? <div className="grid items-stretch gap-5 sm:grid-cols-2 xl:grid-cols-3">{offers.data.map(offer => <OfferCard key={offer.key} offer={offer} />)}</div> : <div className="feature-card"><p className="font-semibold">{tab === 'explore' ? 'No encontramos oportunidades con estos filtros.' : tab === 'links' ? 'Todavía no has generado enlaces. Explora y elige una oportunidad.' : 'Todavía no tienes ofertas finalizadas o retiradas.'}</p></div>}
            <EconomicPages page={offers} />
            {tab !== 'explore' && <Link href="/afiliados?tab=results" className="btn btn-primary mt-6">VER MIS RESULTADOS</Link>}
        </> : <>
        <AffiliateResultsSummary results={results} conversions={conversions.total} />
        <section aria-labelledby="link-results-title" className="mt-8">
            <h2 id="link-results-title" className="mb-4 text-xl font-extrabold">CLICS POR OPORTUNIDAD</h2>
            {offers.data.length ? <div className="space-y-3">{offers.data.map(offer => <article key={offer.key} data-result-offer={offer.key} className="feature-card flex flex-wrap items-center justify-between gap-4"><div className="min-w-0"><h3 className="break-words font-bold">{offer.name}</h3><p className="mt-2 text-xs text-text-secondary">{offer.source.toUpperCase()} · Enlace permanente</p><a className="mt-2 inline-block break-all text-sm underline" href={offer.personal_url || undefined} target="_blank" rel="noopener noreferrer">{offer.personal_url}</a></div><dl className="flex flex-wrap gap-6 text-sm"><div><dt className="text-text-secondary">Clics totales</dt><dd data-opportunity-clicks className="mt-1 text-xl font-bold">{offer.clicks_total ?? 0}</dd></div><div><dt className="text-text-secondary">Últimos 7 días</dt><dd className="mt-1 text-xl font-bold">{offer.clicks_7_days ?? 0}</dd></div></dl></article>)}</div> : <p className="text-sm text-text-secondary">Todavía no has generado enlaces personales.</p>}
            <EconomicPages page={offers} />
        </section>
        {economics && <section id="mis-comisiones" className="my-10">
            <h2 className="text-2xl font-extrabold tracking-tight">MIS COMISIONES</h2>{economic_cutoff && <EconomicCutoff value={economic_cutoff} />}
            <div className="my-5 grid gap-4 sm:grid-cols-3"><article className="feature-card"><p className="text-sm text-text-secondary">Pendientes de evaluación</p><p data-pending-commissions className="mt-3 text-3xl font-bold">{economics.pending}</p></article><article className="feature-card"><p className="text-sm text-text-secondary">Comisiones aprobadas</p><p data-approved-commissions className="mt-3 text-3xl font-bold">{economics.approved}</p></article><article className="feature-card"><p className="text-sm text-text-secondary">Requieren revisión</p><p className="mt-3 text-3xl font-bold">{economics.review}</p></article></div>
            <p className="text-sm text-text-secondary">Los candidatos sin regla se conservan sin importe. Las valoraciones pendientes son estimaciones sujetas a revisión; estos totales no son saldos disponibles para retirar.</p>
            {economics.currencies.length ? <div className="mt-5 grid gap-4 sm:grid-cols-2">{economics.currencies.map(total => <article key={total.currency} className="feature-card" data-commission-currency={total.currency}><h3 className="font-bold">{total.currency}</h3><dl className="mt-3 space-y-2 text-sm"><div><dt className="text-text-secondary">Valoración pendiente</dt><dd className="break-all font-semibold">{total.pending} {total.currency}</dd></div><div><dt className="text-text-secondary">Aprobado manualmente</dt><dd className="break-all font-semibold">{total.approved} {total.currency}</dd></div><div><dt className="text-text-secondary">Importes en revisión</dt><dd className="break-all font-semibold">{total.review} {total.currency}</dd></div></dl></article>)}</div> : <p className="mt-4 text-sm">Todavía no hay importes asignados.</p>}
            {commission_entries && commission_entries.data.length > 0 && <><h3 className="mb-4 mt-8 text-lg font-bold">Detalle de comisiones</h3><div className="space-y-3">{commission_entries.data.map(entry => <article key={entry.id} className="feature-card flex flex-wrap justify-between gap-4 text-sm" data-affiliate-commission={entry.id}><div><p className="break-all font-semibold">{entry.com_reference_type}: {entry.com_reference_id}</p><p className="mt-2 text-text-secondary">{economicDate(entry.created_at)} · {commissionStatus[entry.status]}</p>{entry.review_required && <p className="mt-2">Requiere revisión</p>}{entry.status === 'candidate' && !entry.review_required && <p className="mt-2 text-text-secondary">{entry.rule_id === null ? 'Sin regla activa aplicable · Sin obligación económica' : 'Pendiente de aprobación manual'}</p>}</div><p className="break-all font-semibold">{entry.amount === null ? 'Sin importe asignado' : `${entry.amount} ${entry.currency}${entry.status === 'candidate' ? ' · Valoración pendiente' : ''}`}</p></article>)}</div><EconomicPages page={commission_entries} /></>}
        </section>}
        <section id="mis-conversiones" className="mt-12 scroll-mt-8">
            <h2 className="text-2xl font-extrabold tracking-tight">HISTORIAL COMERCIAL</h2>
            <p className="mb-6 mt-3 text-sm text-text-secondary">Operaciones vinculadas a tu código CLUB verificado. Fecha y hora de Bolivia.</p>
            {conversions.data.length === 0 ? <div className="feature-card"><p className="font-semibold">Todavía no tienes operaciones históricas confirmadas.</p><p className="mt-2 text-sm text-text-secondary">Cuando COM confirme una operación con tu enlace, aparecerá aquí.</p></div> : <>
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
        </>}
    </PageFrame></>;
}
