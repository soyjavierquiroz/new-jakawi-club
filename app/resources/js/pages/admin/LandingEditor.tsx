import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import { ErrorText, PageFrame } from '../../components/UI';
import type { LandingContent, LandingRecord } from '../../types';
import { statusLabel } from './Landings';

type Value = string | null | { [key: string]: Value } | Value[];
const labels: Record<string, string> = {
    seo: 'Buscadores', hero: 'Hero', benefits: 'Beneficios', registration: 'Introducción al registro', faq: 'Preguntas frecuentes', final: 'Cierre', video: 'Video VSL',
    title: 'Título', description: 'Descripción', eyebrow: 'Antetítulo', emphasis: 'Texto destacado', ending: 'Final del titular', cta: 'Texto del botón',
    items: 'Elementos', question: 'Pregunta', answer: 'Respuesta', youtube_url: 'URL de YouTube', poster: 'Poster local (opcional)', aspect_ratio: 'Marco del reproductor', source_aspect_ratio: 'Proporción del video original',
};

function Fields({ value, path, onChange }: { value: Value; path: string; onChange: (value: Value) => void }) {
    const key = path.split('.').at(-1) || path;
    if (Array.isArray(value)) return <div className="space-y-4">{value.map((item, index) => <fieldset className="rounded-card border border-border p-4" key={index}>
        <legend className="px-2 text-sm font-bold">Elemento {index + 1}</legend><Fields value={item} path={`${path}.${index}`} onChange={next => onChange(value.map((entry, i) => i === index ? next : entry))} />
        <button type="button" className="btn btn-secondary mt-4" disabled={value.length <= 1} onClick={() => onChange(value.filter((_, i) => i !== index))}>Quitar elemento</button>
    </fieldset>)}<button type="button" className="btn btn-secondary" disabled={value.length >= 8} onClick={() => onChange([...value, Object.fromEntries(Object.keys(value[0] || {}).map(name => [name, '']))])}>Añadir elemento</button></div>;
    if (value !== null && typeof value === 'object') return <div className="space-y-5">{Object.entries(value).map(([name, entry]) => <Fields key={name} value={entry} path={`${path}.${name}`} onChange={next => onChange({ ...value, [name]: next })} />)}</div>;
    const options = key === 'aspect_ratio' ? ['3:4', '16:9'] : key === 'source_aspect_ratio' ? ['9:16', '3:4', '16:9'] : null;
    const optional = key === 'poster' || key === 'youtube_url';
    return <div><label className="label" htmlFor={path}>{labels[key] || key}</label>{options ? <select className="input" id={path} value={value || ''} onChange={event => onChange(event.target.value)}>{options.map(option => <option key={option}>{option}</option>)}</select> :
        <textarea className="input" id={path} rows={key === 'description' || key === 'answer' ? 3 : 2} maxLength={optional ? 500 : 1200} required={!optional} value={value || ''} onChange={event => onChange(optional && !event.target.value ? null : event.target.value)} />}
        {key === 'poster' && <p className="mt-2 text-xs text-text-secondary">Archivo PNG, JPEG o WebP existente en /marketing/. Vacío conserva el estado sin poster.</p>}
    </div>;
}

type Props = { landing: LandingRecord | null; defaults: LandingContent | null; home_slug: string; metrics?: { event: string; campaign: string; total: number }[] };
export default function LandingEditor({ landing, defaults, home_slug, metrics = [] }: Props) {
    const initial = () => ({ title: landing?.title || '', slug: landing?.slug || '', campaign: landing?.campaign || '', configuration: landing?.configuration || defaults!, revision: landing?.revision || 1 });
    const form = useForm(initial());
    const operation = useForm({ revision: landing?.revision || 1 });
    const importer = useForm({ editorial_json: '', revision: landing?.revision || 1 });
    const [preview, setPreview] = useState(false);
    const [width, setWidth] = useState(390);
    const [importError, setImportError] = useState('');
    useEffect(() => {
        const next = initial();
        form.setData(next); form.setDefaults(next);
        operation.setData('revision', next.revision);
        importer.setData('revision', next.revision);
        setPreview(false);
    }, [landing?.id, landing?.revision]);
    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => { if (form.isDirty) event.preventDefault(); };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [form.isDirty]);
    function save(event: FormEvent) {
        event.preventDefault();
        if (landing) form.put(`/admin/landings/${landing.id}`, { preserveScroll: true });
        else form.post('/admin/landings');
    }
    const busy = form.processing || operation.processing || importer.processing;
    const saved = !!landing && !form.isDirty;
    const errors = [...Object.values(form.errors), ...Object.values(operation.errors), ...Object.values(importer.errors)];
    return <><Head title={landing ? `Editar ${landing.title}` : 'Crear landing'} /><PageFrame>
        <Link href="/admin/landings" className="text-sm underline">← Todas las landings</Link>
        <div className="my-7"><p className="eyebrow">LEAD_VSL · {landing ? statusLabel[landing.status] : 'NUEVO BORRADOR'} {landing?.slug === home_slug ? '· PORTADA /' : ''}</p><h1 className="section-title mt-4">{landing ? landing.title : 'Una nueva landing'}</h1><p className="mt-4 text-text-secondary">Guardar, previsualizar y publicar son acciones independientes. El registro público conserva su configuración operativa.</p></div>
        {!!errors.length && <div role="alert" className="message mb-6"><p className="font-bold">Revisa los cambios:</p><ul className="mt-2 list-inside list-disc">{errors.map((error, index) => <li key={index}>{error}</li>)}</ul></div>}
        <form onSubmit={save} className="space-y-6">
            <fieldset disabled={busy} className="feature-card space-y-5"><legend className="px-2 font-bold">Identificación</legend>
                <div><label htmlFor="landing-title" className="label">Nombre interno</label><input className="input" id="landing-title" required maxLength={120} value={form.data.title} onChange={event => form.setData('title', event.target.value)} /></div>
                <div><label htmlFor="landing-slug" className="label">Slug público</label><input className="input" id="landing-slug" required pattern="[a-z0-9]+(-[a-z0-9]+)*" maxLength={100} readOnly={!!landing?.published_at} value={form.data.slug} onChange={event => form.setData('slug', event.target.value)} /><p className="mt-2 text-xs text-text-secondary">/l/{form.data.slug || 'tu-landing'} · Permanente tras la primera publicación.</p></div>
                <div><label htmlFor="landing-campaign" className="label">Identificador de campaña (opcional)</label><input className="input" id="landing-campaign" pattern="[a-zA-Z0-9_-]+" maxLength={80} value={form.data.campaign} onChange={event => form.setData('campaign', event.target.value)} /><p className="mt-2 text-xs text-text-secondary">Agrupa los conteos propios por campaña al publicar.</p></div>
            </fieldset>
            <fieldset disabled={busy} className="space-y-3">{Object.entries(form.data.configuration).map(([section, value]) => <details className="feature-card" key={section} open={section === 'hero'}><summary className="text-lg font-bold">{labels[section]}</summary><div className="mt-6"><Fields value={value as Value} path={`content.${section}`} onChange={next => form.setData('configuration', { ...form.data.configuration, [section]: next } as LandingContent)} /></div></details>)}</fieldset>
            <div className="flex flex-wrap items-center gap-4"><button className="btn btn-primary" disabled={busy} type="submit">{form.processing ? 'Guardando…' : 'Guardar borrador'}</button><p role="status" className="text-sm">{form.isDirty ? 'Hay cambios sin guardar.' : landing ? `Borrador guardado · revisión ${landing.revision}` : 'Todavía no se ha guardado.'}</p></div>
        </form>
        {landing && <>
            <section className="feature-card mt-8" aria-labelledby="publication-title"><h2 className="text-xl font-bold" id="publication-title">Revisar y publicar</h2><p className="mt-3 text-sm text-text-secondary">Estas acciones utilizan el borrador guardado. Guarda los cambios pendientes antes de continuar.</p>
                <div className="mt-5 flex flex-wrap gap-3"><button type="button" className="btn btn-secondary" disabled={!saved || busy} onClick={() => setPreview(true)}>Vista previa real</button><button type="button" className="btn btn-primary" disabled={!saved || busy} onClick={() => operation.post(`/admin/landings/${landing.id}/publicar`, { preserveScroll: true })}>Publicar versión guardada</button>
                    <button type="button" className="btn btn-secondary" disabled={!saved || busy} onClick={() => operation.post(`/admin/landings/${landing.id}/duplicar`)}>Duplicar</button>
                    <button type="button" className="btn btn-secondary" disabled={!saved || busy || landing.slug === home_slug || landing.status === 'archived'} onClick={() => operation.post(`/admin/landings/${landing.id}/archivar`, { preserveScroll: true })}>Archivar</button>
                    {landing.status === 'published' && <a href={`/l/${landing.slug}`} className="btn btn-secondary" target="_blank" rel="noreferrer">Abrir pública</a>}
                </div>{landing.published_at && <p className="mt-4 text-sm">Última publicación: {new Intl.DateTimeFormat('es-BO', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/La_Paz' }).format(new Date(landing.published_at))} (Bolivia).</p>}
                {preview && <div className="mt-6"><div className="mb-4 flex flex-wrap gap-2" role="group" aria-label="Ancho de la vista previa">{[390, 430, 1440].map(size => <button type="button" key={size} className="btn btn-secondary" aria-pressed={width === size} onClick={() => setWidth(size)}>{size === 1440 ? 'Desktop · 1440' : size} px</button>)}<a className="btn btn-secondary" href={`/admin/landings/${landing.id}/preview`} target="_blank" rel="noreferrer">Abrir preview</a></div><div className="landing-preview-scroll"><iframe title={`Vista previa real a ${width} px`} className="landing-preview-frame" style={{ width }} src={`/admin/landings/${landing.id}/preview?revision=${landing.revision}`} /></div></div>}
            </section>
            <section className="feature-card mt-8" aria-labelledby="json-title"><h2 className="text-xl font-bold" id="json-title">JSON editorial</h2><p className="my-4 text-sm text-text-secondary">jakawi-club-landing-v1 · Importación parcial: conserva campos omitidos y reemplaza listas completas. Solo textos y video; la importación guarda un borrador.</p>
                <a className="btn btn-secondary" href={`/admin/landings/${landing.id}/export`}>Exportar JSON guardado</a>
                <form className="mt-6 space-y-4" onSubmit={event => { event.preventDefault(); importer.post(`/admin/landings/${landing.id}/import`, { preserveScroll: true, onSuccess: () => importer.setData('editorial_json', '') }); }}>
                    <div><label htmlFor="editorial-file" className="label">Cargar archivo JSON (hasta 64 KiB)</label><input id="editorial-file" className="input" type="file" accept=".json,application/json" disabled={busy || !saved} onChange={async event => { const file = event.target.files?.[0]; if (!file) return; if (file.size > 65536) { setImportError('El archivo supera 64 KiB.'); return; } setImportError(''); importer.setData('editorial_json', await file.text()); }} /><ErrorText>{importError}</ErrorText></div>
                    <div><label className="label" htmlFor="editorial-json">Contenido JSON</label><textarea id="editorial-json" className="input font-mono" rows={8} required maxLength={65536} disabled={busy || !saved} value={importer.data.editorial_json} onChange={event => importer.setData('editorial_json', event.target.value)} /></div><button className="btn btn-secondary" type="submit" disabled={busy || !saved}>Importar al borrador</button>
                </form>
            </section>
            <section className="feature-card mt-8"><h2 className="text-xl font-bold">Analítica propia</h2><p className="mt-3 text-sm text-text-secondary">Conteos agregados por evento y campaña publicada. La vista previa no genera eventos.</p>{metrics.length ? <div className="mt-5 overflow-x-auto"><table className="w-full text-left text-sm"><thead><tr><th className="p-2">Evento</th><th className="p-2">Campaña</th><th className="p-2">Total</th></tr></thead><tbody>{metrics.map(item => <tr key={`${item.event}-${item.campaign}`}><td className="p-2">{item.event}</td><td className="p-2">{item.campaign || 'Sin campaña'}</td><td className="p-2">{item.total}</td></tr>)}</tbody></table></div> : <p className="mt-5 text-sm">Todavía no hay eventos para esta landing.</p>}</section>
        </>}
    </PageFrame></>;
}
