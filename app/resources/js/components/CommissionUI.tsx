import { Link } from '@inertiajs/react';

export const commissionStatus: Record<string, string> = { registered: 'Conversión registrada', candidate: 'Pendiente de evaluación', approved: 'Comisión aprobada', discarded: 'Comisión descartada', draft: 'Borrador', active: 'Activa', inactive: 'Inactiva' };
export type Audit = { action: string; actor_uuid: string | null; reason: string; created_at: string; before?: string | object | null; after?: string | object };
export type Pagination<T> = { data: T[]; total: number; current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null };
export const economicDate = (value: string) => new Intl.DateTimeFormat('es-BO', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/La_Paz' }).format(new Date(value));
export function EconomicNav() {
    return <nav aria-label="Administración económica" className="my-6 flex flex-wrap gap-4 text-sm font-semibold"><Link className="underline underline-offset-4" href="/admin/ofertas-comerciales">OFERTAS COMERCIALES</Link><Link className="underline underline-offset-4" href="/admin/reglas-comisiones">REGLAS DE COMISIONES</Link><Link className="underline underline-offset-4" href="/admin/comisiones">LIBRO DE COMISIONES</Link><Link className="underline underline-offset-4" href="/admin/conversiones-com">CONVERSIONES COM</Link><Link className="underline underline-offset-4" href="/dashboard">Mi cuenta</Link></nav>;
}
export function EconomicPages({ page }: { page: Pagination<unknown> }) {
    return page.last_page > 1 ? <nav aria-label="Paginación" className="mt-6 flex flex-wrap gap-4 text-sm">{page.prev_page_url && <Link href={page.prev_page_url}>Anterior</Link>}<span>Página {page.current_page} de {page.last_page}</span>{page.next_page_url && <Link href={page.next_page_url}>Siguiente</Link>}</nav> : null;
}
export function EconomicHistory({ history }: { history: Audit[] }) {
    return <details className="mt-5 text-sm"><summary className="cursor-pointer font-semibold">Historial de decisiones ({history.length})</summary><ol className="mt-3 space-y-3">{history.map((item, index) => <li key={index} className="break-words border-l border-border pl-3"><p>{economicDate(item.created_at)} · {item.action}</p><p className="mt-1 text-text-secondary">{item.reason}</p><p className="mt-1 break-all text-xs text-text-muted">{item.actor_uuid ? `Administrador: ${item.actor_uuid}` : 'Motor CLUB'}</p>{item.after && <details className="mt-2"><summary className="cursor-pointer">Evidencia registrada</summary><pre className="mt-2 max-h-80 overflow-auto whitespace-pre-wrap break-all text-xs">{JSON.stringify({ before: typeof item.before === 'string' ? JSON.parse(item.before) : item.before, after: typeof item.after === 'string' ? JSON.parse(item.after) : item.after }, null, 2)}</pre></details>}</li>)}</ol></details>;
}

export function EconomicCutoff({ value }: { value: string }) {
    return <p className="message my-5 text-sm">CLUB evalúa operaciones confirmadas después del corte económico: <time dateTime={value}>{value}</time> (hora de Bolivia). Se utiliza la fecha original del hecho económico de COM.</p>;
}
