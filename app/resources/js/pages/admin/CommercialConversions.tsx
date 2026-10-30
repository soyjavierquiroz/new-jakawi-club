import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { EconomicNav } from '../../components/CommissionUI';
import { PageFrame } from '../../components/UI';

type CommercialEvent = {
    event_id: string; occurred_at: string; landing: string | null; campaign: string | null;
    partner_id: string | null; partner_name: string | null; event_type: string;
    com_referral_id: string | null; club_referral_code: string | null;
    referral_verification: 'club_code_verified' | 'com_reference_only' | null;
    conversion_status: string; com_reference_type: string; com_reference_id: string;
};
type Props = {
    events: { data: CommercialEvent[]; total: number; current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null };
    filters: { partner: string; campaign: string }; partners: string[]; campaigns: string[];
};
const date = new Intl.DateTimeFormat('es-BO', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/La_Paz' });

export default function CommercialConversions({ events, filters, partners, campaigns }: Props) {
    const [partner, setPartner] = useState(filters.partner);
    const [campaign, setCampaign] = useState(filters.campaign);
    function filter(event: FormEvent) {
        event.preventDefault();
        router.get('/admin/conversiones-com', { ...(partner ? { partner } : {}), ...(campaign ? { campaign } : {}) });
    }
    return <><Head title="CONVERSIONES COM" /><PageFrame>
        <div className="mb-8 flex flex-wrap items-end justify-between gap-5"><div><p className="eyebrow">ADMINISTRACIÓN CLUB</p><h1 className="section-title mt-4">CONVERSIONES COM</h1><p className="mt-4 text-text-secondary">Eventos comerciales recibidos de JAKAWI.COM. Fechas en hora de Bolivia.</p></div><Link className="btn btn-secondary" href="/admin/landings">Administración</Link></div>
        <EconomicNav />
        <form onSubmit={filter} className="mb-8 flex flex-wrap items-end gap-4">
            <div><label className="label" htmlFor="commercial-partner">Partner</label><select className="input" id="commercial-partner" value={partner} onChange={e => setPartner(e.target.value)}><option value="">Todos</option>{partners.map(id => <option key={id} value={id}>{id}</option>)}</select></div>
            <div><label className="label" htmlFor="commercial-campaign">Campaña</label><select className="input" id="commercial-campaign" value={campaign} onChange={e => setCampaign(e.target.value)}><option value="">Todas</option>{campaigns.map(id => <option key={id} value={id}>{id}</option>)}</select></div>
            <button className="btn btn-primary" type="submit">Filtrar</button><Link className="btn btn-secondary" href="/admin/conversiones-com">Limpiar</Link>
        </form>
        <p className="mb-4 text-sm text-text-secondary" role="status">{events.total} {events.total === 1 ? 'evento recibido' : 'eventos recibidos'}</p>
        {events.data.length === 0 ? <p className="feature-card">No hay conversiones COM para mostrar con estos filtros.</p> : <div className="overflow-x-auto rounded-card border border-border"><table className="w-full min-w-[900px] text-left text-sm"><caption className="sr-only">Conversiones comerciales de JAKAWI.COM</caption><thead className="bg-surface-subtle"><tr>{['Fecha', 'Landing / campaña', 'Partner', 'Tipo de conversión', 'Referido', 'Estado', 'Referencia original COM'].map(label => <th scope="col" className="px-4 py-4" key={label}>{label}</th>)}</tr></thead><tbody>{events.data.map(event => <tr className="border-t border-border align-top" key={event.event_id}>
            <td className="px-4 py-4"><time dateTime={event.occurred_at}>{date.format(new Date(event.occurred_at))}</time></td>
            <td className="max-w-64 break-words px-4 py-4">{event.landing || 'Sin landing informada'}<span className="mt-2 block text-text-secondary">{event.campaign || 'Sin campaña informada'}</span></td>
            <td className="max-w-48 break-words px-4 py-4">{event.partner_name || event.partner_id || 'Sin partner informado'}{event.partner_name && <span className="mt-2 block text-text-secondary">{event.partner_id}</span>}</td>
            <td className="break-words px-4 py-4">{event.event_type}</td>
            <td className="px-4 py-4">{event.club_referral_code || event.com_referral_id || 'Sin referido'}{event.referral_verification && <span className="mt-2 block text-xs text-text-secondary">{event.referral_verification === 'club_code_verified' ? 'Código CLUB verificado' : 'Referencia informada por COM'}</span>}{event.club_referral_code && event.com_referral_id && <span className="mt-2 block text-xs">COM: {event.com_referral_id}</span>}</td>
            <td className="break-words px-4 py-4">{event.conversion_status}</td><td className="max-w-64 break-words px-4 py-4">{event.com_reference_type}: {event.com_reference_id}</td>
        </tr>)}</tbody></table></div>}
        <nav aria-label="Páginas de conversiones" className="mt-6 flex flex-wrap items-center gap-4">{events.prev_page_url && <Link className="btn btn-secondary" href={events.prev_page_url}>Anterior</Link>}<span className="text-sm">Página {events.current_page} de {events.last_page}</span>{events.next_page_url && <Link className="btn btn-secondary" href={events.next_page_url}>Siguiente</Link>}</nav>
    </PageFrame></>;
}
