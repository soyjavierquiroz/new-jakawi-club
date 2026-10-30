import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { CommunityWhatsAppIcon } from './CommunityWhatsAppIcon';

export type CatalogOffer = {
    key: string; name: string; source: 'com' | 'club'; source_id: string; type: string;
    short_description: string | null; image_url: string | null; partners: { id: number; name: string }[];
    cities: string[]; category: string | null; effective_state: string; availability_status: string;
    publication_status: string; landing_status: string; starts_at: string | null; ends_at: string | null;
    personal_url?: string | null; destination_url: string; last_synced_at: string | null; local_paused: boolean;
};
export const catalogStates: Record<string, string> = { active: 'Promocionable', upcoming: 'Próximamente', paused: 'No disponible', finished: 'Finalizada', retired: 'Retirada del catálogo' };
export const catalogTypes: Record<string, string> = { benefit: 'Beneficio', experience: 'Experiencia', acquisition: 'Captación' };
const date = (value: string) => new Intl.DateTimeFormat('es-BO', { dateStyle: 'medium', timeZone: 'America/La_Paz' }).format(new Date(value));

export function OfferCard({ offer }: { offer: CatalogOffer }) {
    const [message, setMessage] = useState('');
    const [imageFailed, setImageFailed] = useState(false);
    async function copy() {
        if (!offer.personal_url) return;
        try { await navigator.clipboard.writeText(offer.personal_url); setMessage('¡Enlace copiado!'); }
        catch { setMessage('Selecciona y copia el enlace del campo.'); }
    }
    return <article data-catalog-offer={offer.key} className="flex min-w-0 flex-col overflow-hidden rounded-card border border-border bg-surface">
        {offer.image_url && !imageFailed ? <img src={offer.image_url} alt="" loading="lazy" referrerPolicy="no-referrer" className="aspect-[16/9] w-full object-cover" onError={() => setImageFailed(true)} /> : <div className="flex aspect-[16/9] items-center justify-center bg-surface-subtle p-6 text-lg font-bold text-text-secondary">{catalogTypes[offer.type]}</div>}
        <div className="flex grow flex-col p-5">
            <div className="flex flex-wrap justify-between gap-2 text-xs font-semibold"><span>{catalogTypes[offer.type]} · {offer.source.toUpperCase()}</span><span data-availability={offer.effective_state}>{catalogStates[offer.effective_state]}</span></div>
            <p className="mt-3 text-xs font-semibold text-text-secondary">{offer.category || 'Oportunidades'}{offer.cities.length > 0 && ` · ${offer.cities.join(', ')}`}</p>
            <h2 className="mt-2 break-words text-xl font-extrabold leading-snug">{offer.name}</h2>
            {offer.partners.length > 0 && <p className="mt-2 break-words text-sm font-semibold">{offer.partners.map(partner => partner.name).join(' · ')}</p>}
            {offer.short_description && <p className="mt-3 text-sm text-text-secondary">{offer.short_description}</p>}
            {(offer.starts_at || offer.ends_at) && <p className="mt-3 text-xs text-text-secondary">{offer.starts_at && `Desde ${date(offer.starts_at)}`}{offer.starts_at && offer.ends_at && ' · '}{offer.ends_at && `Hasta ${date(offer.ends_at)}`}</p>}
            {offer.availability_status === 'scheduled' && <p className="mt-3 text-xs text-text-secondary">Sesión programada. La disponibilidad de cupos y las reservas se consultan en COM.</p>}
            <div className="mt-auto pt-5">
                {offer.personal_url ? <>
                    <label className="label" htmlFor={`link-${offer.key}`}>Tu enlace permanente</label>
                    <input id={`link-${offer.key}`} data-personal-link readOnly value={offer.personal_url} className="input w-full min-w-0 text-sm" onFocus={event => event.target.select()} />
                    <div className="mt-3 flex flex-wrap gap-3">
                        <button type="button" className="btn btn-primary w-full" onClick={() => void copy()}>COPIAR ENLACE</button>
                        <a href={`https://wa.me/?text=${encodeURIComponent(`${offer.name}\n${offer.personal_url}`)}`} target="_blank" rel="noopener noreferrer" className="btn community-whatsapp-cta w-full text-center"><CommunityWhatsAppIcon /> WHATSAPP</a>
                    </div>
                    <p role="status" className="mt-2 min-h-5 text-xs">{message}</p>
                    <details className="mt-2 text-xs"><summary>Ver destino vigente</summary><p className="mt-2 break-all">{offer.source === 'club' ? 'Invitación CLUB mediante tu código de referido permanente.' : offer.destination_url}</p></details>
                </> : offer.effective_state === 'active' ? <Link href={`/afiliados/enlaces/${encodeURIComponent(offer.key)}`} method="post" as="button" className="btn btn-primary w-full">OBTENER MI ENLACE</Link> : <p className="text-sm text-text-secondary">{offer.effective_state === 'upcoming' ? 'Vuelve pronto para obtener tu enlace.' : 'La promoción está detenida por ahora.'}</p>}
            </div>
        </div>
    </article>;
}
