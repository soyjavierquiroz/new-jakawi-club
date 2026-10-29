import { Head, Link } from '@inertiajs/react';
import { PageFrame } from '../../components/UI';
import type { LandingRecord } from '../../types';

export const statusLabel = { draft: 'Borrador', published: 'Publicada', archived: 'Archivada' };

export default function Landings({ landings, home_slug }: { landings: LandingRecord[]; home_slug: string }) {
    return <><Head title="Administrar landings" /><PageFrame>
        <div className="mb-8 flex flex-wrap items-end justify-between gap-5"><div><p className="eyebrow">JAKAWI LAUNCH ENGINE</p><h1 className="section-title mt-4">Tus landings</h1><p className="mt-4 text-text-secondary">Crea un borrador, revísalo y publica cuando esté listo.</p></div><Link className="btn btn-primary" href="/admin/landings/crear">Crear landing</Link></div>
        <div className="grid gap-4 md:grid-cols-2">{landings.map(landing => <article className="feature-card" key={landing.id}>
            <div className="flex flex-wrap items-center gap-3"><span className="eyebrow">{statusLabel[landing.status]}</span>{landing.slug === home_slug && <span className="text-xs font-bold">PORTADA /</span>}</div>
            <h2 className="mt-4 text-xl font-bold">{landing.title}</h2><p className="mt-3 break-all text-sm text-text-secondary">/l/{landing.slug}</p><p className="mt-2 text-sm">Campaña: {landing.campaign || 'Sin asignar'}</p>
            <div className="mt-6 flex flex-wrap gap-3"><Link className="btn btn-secondary" href={`/admin/landings/${landing.id}/editar`}>Editar</Link>{landing.status === 'published' && <a className="btn btn-secondary" href={`/l/${landing.slug}`} target="_blank" rel="noreferrer">Ver pública</a>}</div>
        </article>)}</div>
    </PageFrame></>;
}
