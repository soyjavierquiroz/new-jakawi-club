import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { ErrorText, PageFrame } from '../../components/UI';
import { EconomicNav, EconomicPages, type Pagination } from '../../components/CommissionUI';

type Offer = { key: string; name: string; original_url: string; destination_url: string; status: 'active' | 'paused' | 'finished' };
type ShortLink = { url: string; referral_code: string; created_at: string };
const states = { active: 'Activa', paused: 'Pausada', finished: 'Finalizada' };
function OfferCard({ offer }: { offer: Offer }) {
    const form = useForm({ destination_url: offer.destination_url, status: offer.status });
    function submit(event: FormEvent) {
        event.preventDefault();
        form.put(`/admin/ofertas-comerciales/${offer.key}`, { preserveScroll: true });
    }
    return <article className="feature-card" data-offer-key={offer.key}>
        <h2 className="text-2xl font-extrabold">{offer.name}</h2>
        <p className="mt-3 font-semibold">Estado vigente: {states[offer.status]}</p>
        <p className="mt-3 break-all text-sm text-text-secondary">URL original: {offer.original_url}</p>
        <form className="mt-5 space-y-5" onSubmit={submit}>
            <div><label className="label" htmlFor={`destination-${offer.key}`}>Destino vigente (HTTPS JAKAWI.COM)</label><input className="input w-full" id={`destination-${offer.key}`} type="url" required maxLength={2048} value={form.data.destination_url} onChange={e => form.setData('destination_url', e.target.value)} /><ErrorText>{form.errors.destination_url}</ErrorText></div>
            <div><label className="label" htmlFor={`status-${offer.key}`}>Estado de la oferta</label><select id={`status-${offer.key}`} className="input w-full" value={form.data.status} onChange={e => form.setData('status', e.target.value as Offer['status'])}>{Object.entries(states).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select><ErrorText>{form.errors.status}</ErrorText></div>
            <p className="text-sm text-text-secondary">Actualiza la URL de esta misma oferta. Los enlaces conservan el afiliado y su atribución. Pausar o finalizar muestra una página pública de aviso.</p>
            <button className="btn btn-primary" type="submit" disabled={form.processing}>GUARDAR CAMBIOS</button>
        </form>
        <Link href={`/admin/ofertas-comerciales?offer=${encodeURIComponent(offer.key)}`} className="mt-5 inline-block text-sm font-semibold underline">Consultar enlaces asociados</Link>
    </article>;
}
export default function CommercialOffers({ offers, selected, links }: { offers: Offer[]; selected: string; links: Pagination<ShortLink> }) {
    return <><Head title="Ofertas comerciales" /><PageFrame>
        <p className="eyebrow">ADMINISTRACIÓN CLUB</p><h1 className="section-title mt-4">OFERTAS COMERCIALES</h1><EconomicNav />
        <p className="mb-6 text-text-secondary">Gestión manual de destinos y estados del piloto.</p>
        <div className="grid gap-5">{offers.map(offer => <OfferCard key={`${offer.key}:${offer.destination_url}:${offer.status}`} offer={offer} />)}</div>
        <section className="mt-10"><h2 className="text-2xl font-extrabold">Enlaces asociados · {offers.find(offer => offer.key === selected)?.name}</h2><p className="my-4 text-sm text-text-secondary">{links.total} enlaces permanentes. Cada código CLUB identifica a su afiliado; estos enlaces no representan conversiones.</p>
            <div className="space-y-3">{links.data.map(link => <article key={link.url} className="feature-card text-sm"><a href={link.url} target="_blank" rel="noreferrer" className="break-all font-semibold underline">{link.url}</a><p className="mt-2 break-all text-text-secondary">Código CLUB: {link.referral_code}</p></article>)}</div><EconomicPages page={links} />
        </section>
    </PageFrame></>;
}
