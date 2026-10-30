export function LandingAiKit({ zipAvailable }: { zipAvailable: boolean }) {
    return <details className="mb-5 rounded-card border border-border p-4" data-landing-ai-kit>
        <summary className="cursor-pointer font-semibold">Crear con IA</summary>
        <p className="mt-3 text-sm text-text-secondary">Entrega los tres archivos al Copywriter. Importa su JSON y revisa ambas etapas antes de guardar y publicar.</p>
        <div className="mt-4 flex flex-wrap gap-3">
            <a className="btn btn-secondary" href="/admin/landings/crear-con-ia/prompt">Descargar prompt maestro del Copywriter</a>
            <a className="btn btn-secondary" href="/admin/landings/crear-con-ia/example">Descargar ejemplo JSON WEBINAR</a>
            <a className="btn btn-secondary" href="/admin/landings/crear-con-ia/schema">Descargar contrato oficial JSON v3</a>
            {zipAvailable ? <a className="btn btn-secondary" href="/admin/landings/crear-con-ia/kit">Descargar kit completo ZIP</a> : <button type="button" className="btn btn-secondary" disabled>Descargar kit completo ZIP · no disponible</button>}
        </div>
        {!zipAvailable && <p className="mt-3 text-sm text-text-secondary">Puedes descargar los tres archivos individuales.</p>}
    </details>;
}
