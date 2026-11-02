export type ComMembershipState = {
    available: boolean;
    type?: 'free' | 'standard' | 'pioneer';
    status?: 'free' | 'active' | 'scheduled' | 'expired' | 'cancelled';
    is_active: boolean;
    starts_at?: string | null;
    effective_ends_at?: string | null;
    expiry_policy?: string | null;
    official_launch_date?: string | null;
    benefits?: { public_catalog: boolean; club_access: boolean; exclusive_member_benefits: boolean };
};
export const planLabel = (membership?: ComMembershipState | null) => membership?.available
    ? ({ free: 'Gratuito', standard: 'Estándar', pioneer: 'Pionero' }[membership.type || 'free'])
    : 'Estado por confirmar';
function date(value: string) { return new Intl.DateTimeFormat('es-BO', { dateStyle: 'long', timeZone: 'America/La_Paz' }).format(new Date(value)); }
export default function ComMembership({ membership }: { membership: ComMembershipState }) {
    return <section className="feature-card mt-8" aria-labelledby="com-plan-title">
        <h2 id="com-plan-title" className="text-2xl font-extrabold">Plan {planLabel(membership)}</h2>
        {!membership.available ? <p className="mt-3 text-text-secondary" role="status">No pudimos consultar tu membresía. Actualiza la página para reintentar. Los beneficios exclusivos quedan pendientes de confirmar.</p> : <>
            <p className="mt-3 text-text-secondary">{{ free: 'Cuenta gratuita', active: 'Membresía vigente', scheduled: 'Membresía programada', expired: 'Membresía vencida', cancelled: 'Membresía cancelada' }[membership.status || 'free']}</p>
            {membership.starts_at && <p className="mt-2 text-sm">Inicio: {date(membership.starts_at)}</p>}
            {membership.effective_ends_at && <p className="mt-2 text-sm">Vigencia hasta: {date(membership.effective_ends_at)}</p>}
            {membership.type === 'pioneer' && <p className="mt-2 text-sm">Vigencia Pionero: lanzamiento oficial + 15 meses calendario.{!membership.official_launch_date && ' La fecha de lanzamiento está pendiente de confirmación.'}</p>}
            <ul className="mt-5 space-y-2 text-sm">
                <li>Acceso a CLUB: {membership.benefits?.club_access ? 'incluido' : 'no disponible'}</li>
                <li>Catálogo público: {membership.benefits?.public_catalog ? 'incluido' : 'no disponible'}</li>
                <li>Beneficios exclusivos: {membership.is_active && membership.benefits?.exclusive_member_benefits ? 'habilitados según condiciones y disponibilidad' : 'bloqueados · requieren membresía vigente'}</li>
            </ul>
            {membership.is_active && membership.benefits?.exclusive_member_benefits && <a className="btn btn-primary mt-5" href="https://jakawi.com/beneficios">Ver mis beneficios en JAKAWI.COM</a>}
        </>}
    </section>;
}
