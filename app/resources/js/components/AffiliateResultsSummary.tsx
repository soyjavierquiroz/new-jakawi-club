export type AffiliateResults = {
    leads_captured: number;
    memberships_activated: number;
    activation_rate: number;
    clicks: { total: number; last_7_days: number };
    club_registrations: number;
    confirmed_redemptions: number;
    approved_commissions: { currency: string; amount: string }[];
};

export function AffiliateResultsSummary({ results, conversions }: { results: AffiliateResults; conversions: number }) {
    return <section id="mis-resultados" className="scroll-mt-6" aria-labelledby="results-title">
        <h2 id="results-title" className="text-2xl font-extrabold">MIS RESULTADOS</h2>
        <p className="mb-5 mt-3 text-sm text-text-secondary">Personas registradas con tu invitación original y membresías activadas confirmadas por JAKAWI.COM.</p>
        <div className="grid gap-4 sm:grid-cols-2">
            <article className="feature-card"><h3 className="text-sm font-semibold">LEADS CAPTADOS</h3><p data-attributed-registrations className="my-3 text-4xl font-extrabold">{results.leads_captured}</p><p className="text-xs text-text-secondary">Una persona registrada en CLUB con tu invitación original cuenta una vez.</p></article>
            <article className="feature-card"><h3 className="text-sm font-semibold">MEMBRESÍAS ACTIVADAS</h3><p data-memberships-activated className="my-3 text-4xl font-extrabold">{results.memberships_activated}</p><p className="text-xs text-text-secondary">Una persona captada con activación COM confirmada. Sin duplicados ni renovaciones como nuevas altas.</p></article>
        </div>
        <p className="my-5 text-sm text-text-secondary">Tasa de activación: <strong>{results.activation_rate}%</strong></p>
        <details className="mt-6"><summary className="cursor-pointer font-semibold">Actividad de enlaces y registros comerciales históricos</summary>
        <p className="my-4 text-xs text-text-secondary">Estos registros se conservan por separado de los leads y las membresías activadas.</p>
        <div className="grid gap-4 sm:grid-cols-2">
            <article className="feature-card"><h3>Clics totales</h3><p data-clicks-total>{results.clicks.total}</p></article>
            <article className="feature-card"><h3>Clics últimos 7 días</h3><p data-clicks-recent>{results.clicks.last_7_days}</p></article>
            <article className="feature-card"><h3>Canjes históricos confirmados</h3><p data-confirmed-redemptions>{results.confirmed_redemptions}</p></article>
        </div>
        <p className="my-5 text-xs text-text-secondary">Los clics son aperturas, no personas únicas ni conversiones. La medición comienza con la activación del contador; no se reconstruyen clics anteriores.</p>
        <div className="grid gap-4 sm:grid-cols-2">
            <article className="feature-card"><h3 className="text-sm font-semibold">Comisiones aprobadas por moneda</h3>
                {results.approved_commissions.length ? <ul className="mt-3 space-y-2">{results.approved_commissions.map(total => <li data-approved-currency={total.currency} key={total.currency} className="break-all text-xl font-bold">{total.amount} {total.currency}</li>)}</ul> : <p className="mt-3 text-sm text-text-secondary">Todavía no tienes comisiones aprobadas.</p>}
                <p className="mt-3 text-xs text-text-secondary">Importes aprobados sin revisión pendiente. No representan un saldo disponible para retirar.</p>
            </article>
            <article className="feature-card"><h3 className="text-sm font-semibold">Operaciones COM históricas</h3><p data-conversion-count className="my-3 text-4xl font-extrabold">{conversions}</p><a href="#mis-conversiones" className="text-sm font-semibold underline">Ver historial comercial ↓</a><p className="mt-3 text-xs text-text-secondary">Cada referencia comercial se cuenta una sola vez. Incluye los canjes y otras operaciones verificadas.</p></article>
        </div>
        </details>
    </section>;
}
