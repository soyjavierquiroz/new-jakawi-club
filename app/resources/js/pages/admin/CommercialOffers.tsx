import { Head, Link, useForm } from '@inertiajs/react';
import { PageFrame, ErrorText } from '../../components/UI';
import { EconomicNav, EconomicPages, economicDate, type Pagination } from '../../components/CommissionUI';
import { catalogStates, type CatalogOffer } from '../../components/CatalogOffer';

type ShortLink = { url: string; referral_code: string; created_at: string };
type Run = { status: string; offers: number; pages: number; completed_at: string };
function OfferControl({ offer }: { offer: CatalogOffer }) {
    const form = useForm({ local_paused: offer.local_paused });
    return <article className="feature-card min-w-0" data-offer-key={offer.key}>
        <div className="flex flex-wrap justify-between gap-3"><h2 className="text-xl font-extrabold">{offer.name}</h2><p className="text-sm font-semibold">{catalogStates[offer.effective_state]}</p></div>
        <p className="mt-3 break-all text-sm">Origen: {offer.source.toUpperCase()} · {offer.source_id} · {offer.category || 'Sin categoría'}</p>
        <p className="mt-2 text-sm text-text-secondary">Publicación: {offer.publication_status} · Landing: {offer.landing_status} · Disponibilidad: {offer.availability_status}</p>
        <p className="mt-2 text-sm text-text-secondary">Última sincronización: {offer.last_synced_at ? economicDate(offer.last_synced_at) : 'Pendiente'}</p>
        <p className="mt-3 break-all text-sm text-text-secondary">Destino de origen: {offer.destination_url}</p>
        <form className="mt-5 flex flex-wrap items-center gap-4" onSubmit={event => { event.preventDefault(); form.put(`/admin/ofertas-comerciales/${encodeURIComponent(offer.key)}`, { preserveScroll: true }); }}>
            <label className="flex items-center gap-3 text-sm font-semibold"><input type="checkbox" checked={form.data.local_paused} onChange={event => form.setData('local_paused', event.target.checked)} /> Pausar promoción en CLUB</label>
            <button className="btn btn-primary" type="submit" disabled={form.processing}>GUARDAR</button><ErrorText>{form.errors.local_paused}</ErrorText>
        </form>
        <Link href={`/admin/ofertas-comerciales?offer=${encodeURIComponent(offer.key)}`} className="mt-5 inline-block text-sm font-semibold underline">Consultar enlaces asociados</Link>
    </article>;
}
export default function CommercialOffers({ offers, selected, links, q, last_sync, last_success }: { offers: Pagination<CatalogOffer>; selected: string; links: Pagination<ShortLink>; q: string; last_sync: Run | null; last_success: Run | null }) {
    return <><Head title="Ofertas comerciales" /><PageFrame>
        <p className="eyebrow">ADMINISTRACIÓN CLUB</p><h1 className="section-title mt-4">OFERTAS COMERCIALES</h1><EconomicNav />
        <p className="mb-4 text-text-secondary">COM controla sus datos operativos. La pausa local solo restringe la promoción; quitarla respeta la disponibilidad de origen.</p>
        <p className="mb-3 text-sm">Último recorrido válido: {last_success ? `${economicDate(last_success.completed_at)} · ${last_success.offers} ofertas COM · ${last_success.pages} páginas` : 'Pendiente'}.</p>
        {last_sync?.status === 'failed' && <p role="status" className="message mb-4">El último intento falló. Se conserva el catálogo válido anterior.</p>}
        <form className="mb-6 flex flex-wrap gap-3" method="get"><label className="sr-only" htmlFor="admin-offer-search">Buscar ofertas</label><input id="admin-offer-search" type="search" name="q" defaultValue={q} placeholder="Nombre o Partner" className="input min-w-0 grow" maxLength={120} /><button className="btn btn-primary">BUSCAR</button></form>
        <p className="mb-4 text-sm">{offers.total} ofertas en el catálogo local.</p>
        <div className="grid gap-5">{offers.data.map(offer => <OfferControl key={`${offer.key}:${offer.local_paused}`} offer={offer} />)}</div><EconomicPages page={offers} />
        <section className="mt-10"><h2 className="break-all text-2xl font-extrabold">Enlaces asociados · {selected}</h2><p className="my-4 text-sm text-text-secondary">{links.total} enlaces permanentes. Los enlaces no representan conversiones.</p>
            <div className="space-y-3">{links.data.map(link => <article key={link.url} className="feature-card text-sm"><a href={link.url} target="_blank" rel="noreferrer" className="break-all font-semibold underline">{link.url}</a><p className="mt-2 break-all text-text-secondary">Código CLUB: {link.referral_code}</p></article>)}</div><EconomicPages page={links} />
        </section>
    </PageFrame></>;
}
