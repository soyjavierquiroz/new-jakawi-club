export type AffiliateResults = {
    clicks: { total: number; last_7_days: number };
    club_registrations: number;
    confirmed_redemptions: number;
    approved_commissions: { currency: string; amount: string }[];
};

export function AffiliateResultsSummary({ results, conversions }: { results: AffiliateResults; conversions: number }) {
    return <section id="mis-resultados" className="scroll-mt-6" aria-labelledby="results-title">
        <h2 id="results-title" className="text-2xl font-extrabold">MIS RESULTADOS</h2>
        <p className="mb-5 mt-3 text-sm text-text-secondary">Cada paso cuenta. Consulta por separado las aperturas de tus enlaces y los resultados que ya tienen evidencia.</p>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <article className="feature-card"><h3 className="text-sm font-semibold">Clics totales</h3><p data-clicks-total className="my-3 text-4xl font-extrabold">{results.clicks.total}</p><p className="text-xs text-text-secondary">Aperturas de tus enlaces cortos activos.</p></article>
            <article className="feature-card"><h3 className="text-sm font-semibold">Clics últimos 7 días</h3><p data-clicks-recent className="my-3 text-4xl font-extrabold">{results.clicks.last_7_days}</p><p className="text-xs text-text-secondary">Hoy y los seis días anteriores, en hora de Bolivia.</p></article>
            <article className="feature-card"><h3 className="text-sm font-semibold">Registros CLUB atribuidos</h3><p data-attributed-registrations className="my-3 text-4xl font-extrabold">{results.club_registrations}</p><p className="text-xs text-text-secondary">Cuentas CLUB con tu referente permanente verificado. No incluye registros COM sin evidencia.</p></article>
            <article className="feature-card"><h3 className="text-sm font-semibold">Canjes confirmados</h3><p data-confirmed-redemptions className="my-3 text-4xl font-extrabold">{results.confirmed_redemptions}</p><p className="text-xs text-text-secondary">Canjes recibidos de COM con tu código CLUB verificado.</p></article>
        </div>
        <p className="my-5 text-xs text-text-secondary">Los clics son aperturas, no personas únicas ni conversiones. La medición comienza con la activación del contador; no se reconstruyen clics anteriores.</p>
        <div className="grid gap-4 sm:grid-cols-2">
            <article className="feature-card"><h3 className="text-sm font-semibold">Comisiones aprobadas por moneda</h3>
                {results.approved_commissions.length ? <ul className="mt-3 space-y-2">{results.approved_commissions.map(total => <li data-approved-currency={total.currency} key={total.currency} className="break-all text-xl font-bold">{total.amount} {total.currency}</li>)}</ul> : <p className="mt-3 text-sm text-text-secondary">Todavía no tienes comisiones aprobadas.</p>}
                <p className="mt-3 text-xs text-text-secondary">Importes aprobados sin revisión pendiente. No representan un saldo disponible para retirar.</p>
            </article>
            <article className="feature-card"><h3 className="text-sm font-semibold">Conversiones COM confirmadas</h3><p data-conversion-count className="my-3 text-4xl font-extrabold">{conversions}</p><a href="#mis-conversiones" className="text-sm font-semibold underline">Ver mis conversiones ↓</a><p className="mt-3 text-xs text-text-secondary">Cada referencia comercial se cuenta una sola vez. Incluye los canjes y otras operaciones verificadas.</p></article>
        </div>
    </section>;
}
