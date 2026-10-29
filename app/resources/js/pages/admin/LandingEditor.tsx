import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { ErrorText, PageFrame } from '../../components/UI';
import { aiBrief, editorialJSON, sameConfiguration } from '../../lib/landing-editorial';
import type { LandingContent, LandingRecord, LandingTemplate } from '../../types';
import { statusLabel } from './Landings';

type Value = string | null | { [key: string]: Value } | Value[];
const labels: Record<string, string> = {
    seo: 'Buscadores', hero: 'Mensaje', benefits: 'Beneficios', registration: 'Introducción al registro', faq: 'Preguntas frecuentes', final: 'Cierre', video: 'Video VSL',
    message: 'Mensaje de confirmación', instructions: 'Instrucciones para el siguiente paso', cta_label: 'Texto del botón siguiente', title: 'Título', description: 'Descripción', eyebrow: 'Antetítulo', emphasis: 'Texto destacado', ending: 'Final del titular', cta: 'Texto del botón',
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

function Group({ title, children, open = false }: { title: string; children: ReactNode; open?: boolean }) {
    return <details className="feature-card" open={open}><summary className="text-lg font-bold">{title}</summary><div className="mt-6 space-y-5">{children}</div></details>;
}

function SavedPreview({ landing, width, confirmation }: { landing: LandingRecord; width: number; confirmation: boolean }) {
    const container = useRef<HTMLDivElement>(null);
    const [scale, setScale] = useState(1);
    useEffect(() => {
        const observer = new ResizeObserver(([entry]) => setScale(Math.min(1, entry.contentRect.width / width)));
        if (container.current) observer.observe(container.current);
        return () => observer.disconnect();
    }, [width]);
    return <div ref={container} className="landing-preview-scroll"><div className="landing-preview-stage" style={{ height: 900 * scale }}><iframe key={`${landing.id}-${landing.revision}-${confirmation}`} title={`Vista previa real a ${width} px`} className="landing-preview-frame" style={{ width, height: 900, transform: `scale(${scale})` }} src={`/admin/landings/${landing.id}/preview${confirmation ? '/confirmado' : ''}?revision=${landing.revision}`} /></div></div>;
}

type Props = { landing: LandingRecord | null; defaults: LandingContent | null; home_slug: string; registration_open: boolean; whatsapp_group_available: boolean; metrics?: { event: string; campaign: string; total: number }[] };
export default function LandingEditor({ landing, defaults, home_slug, registration_open, whatsapp_group_available, metrics = [] }: Props) {
    const initial = () => ({ title: landing?.title || '', slug: landing?.slug || '', campaign: landing?.campaign || '', configuration: landing?.configuration || defaults!, revision: landing?.revision || 1 });
    const form = useForm(initial());
    const operation = useForm({ revision: landing?.revision || 1 });
    const [width, setWidth] = useState(390);
    const [confirmationPreview, setConfirmationPreview] = useState(false);
    const [tool, setTool] = useState<'import' | 'export' | 'brief' | null>(null);
    const [json, setJson] = useState('');
    const [importing, setImporting] = useState(false);
    const [importErrors, setImportErrors] = useState<Record<string, string[]>>({});
    const [notice, setNotice] = useState('');
    const [mobileView, setMobileView] = useState<'editor' | 'preview'>('editor');
    const dialog = useRef<HTMLDialogElement>(null);
    const previewPanel = useRef<HTMLElement>(null);
    useEffect(() => {
        const next = initial();
        form.setData(next); form.setDefaults(next);
        operation.setData('revision', next.revision);
        setNotice('');
    }, [landing?.id, landing?.revision]);
    useEffect(() => {
        if (tool) dialog.current?.showModal();
        else dialog.current?.close();
    }, [tool]);
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
    async function copy(value: string) {
        try { await navigator.clipboard.writeText(value); setNotice('Contenido copiado.'); return true; }
        catch { setNotice('Selecciona y copia el contenido del cuadro.'); return false; }
    }
    const brief = () => aiBrief(form.data, form.data.configuration, registration_open);
    async function importJSON(event: FormEvent) {
        event.preventDefault(); setImportErrors({}); setImporting(true);
        try {
            if (new TextEncoder().encode(json).length > 65536) { setImportErrors({ editorial_json: ['El documento supera 64 KiB.'] }); return; }
            const token = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.slice(11);
            const response = await fetch('/admin/landings/import', {
                method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(token || '') },
                body: JSON.stringify({ editorial_json: json, configuration: form.data.configuration }),
            });
            if (response.status === 422) { const data = await response.json(); setImportErrors(data.errors); return; }
            if (!response.ok) throw new Error('No se pudo validar. Revisa tu sesión y vuelve a intentarlo.');
            const data = await response.json() as { configuration: LandingContent };
            form.setData('configuration', data.configuration);
            setTool(null); setJson('');
            setNotice('JSON aplicado al formulario. Campos omitidos conservados. Pendiente de guardar; no se guardó ni publicó.');
        } catch { setImportErrors({ editorial_json: ['No se pudo validar el JSON. Revisa tu conexión y sesión.'] }); }
        finally { setImporting(false); }
    }
    const busy = form.processing || operation.processing || importing;
    const saved = !!landing && !form.isDirty;
    const pendingPublication = !!landing && (landing.status !== 'published' || !sameConfiguration(landing.configuration, landing.published_configuration ? { ...landing.published_configuration, presentation: landing.published_configuration.presentation ?? { template: 'LEAD_VSL', show_video: true, show_faq: true } } : null) || landing.campaign !== landing.published_campaign);
    const errors = { ...form.errors, ...operation.errors };
    const content = form.data.configuration;
    function section(name: 'hero' | 'seo' | 'video' | 'benefits' | 'registration' | 'faq' | 'final' | 'after_registration') {
        return <Fields value={content[name] as Value} path={`content.${name}`} onChange={next => form.setData('configuration', { ...content, [name]: next } as LandingContent)} />;
    }
    function presentation(patch: Partial<LandingContent['presentation']>) {
        form.setData('configuration', { ...content, presentation: { ...content.presentation, ...patch } });
    }
    return <><Head title={landing ? `Editar ${landing.title}` : 'Crear landing'} /><PageFrame wide>
        <Link href="/admin/landings" className="text-sm underline">← Todas las landings</Link>
        <div className="my-7"><p className="eyebrow">{content.presentation.template} · {landing ? statusLabel[landing.status] : 'NUEVO BORRADOR'} {landing?.slug === home_slug ? '· PORTADA PRINCIPAL' : ''}</p><h1 className="section-title mt-4">{landing ? landing.title : 'Una nueva landing'}</h1><p className="mt-4 max-w-3xl text-text-secondary">Edita, guarda y revisa el resultado. Publica la versión guardada cuando esté lista.</p></div>
        <div className="mb-5 flex flex-wrap gap-3" aria-label="Herramientas editoriales">
            <button type="button" className="btn btn-secondary" onClick={async () => { if (!await copy(brief())) setTool('brief'); }}>COPIAR BRIEF PARA IA</button>
            <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => { setImportErrors({}); setTool('import'); }}>IMPORTAR JSON</button>
            <button type="button" className="btn btn-secondary" onClick={() => setTool('export')}>EXPORTAR JSON</button>
        </div>
        {notice && <p className="message mb-5" role="status">{notice}</p>}
        {!!Object.keys(errors).length && <div role="alert" className="message mb-6"><p className="font-bold">Revisa los campos:</p><ul className="mt-2 list-inside list-disc">{Object.entries(errors).map(([path, error]) => <li key={path}><strong>{path}</strong>: {error}</li>)}</ul></div>}
        <div className="editor-mobile-tabs mb-4" role="group" aria-label="Panel del editor"><button className="btn btn-secondary" aria-pressed={mobileView === 'editor'} onClick={() => setMobileView('editor')}>Editor</button><button className="btn btn-secondary" aria-pressed={mobileView === 'preview'} onClick={() => setMobileView('preview')}>Preview</button></div>
        <div className="landing-editor-grid" data-mobile-view={mobileView}>
            <div className="landing-editor-content">
                <form id="landing-form" onSubmit={save}>
                    <div className="editor-save-bar mb-5"><div className="flex flex-wrap gap-3"><button className="btn btn-primary" disabled={busy} type="submit">{form.processing ? 'Guardando…' : 'Guardar borrador'}</button><button className="btn btn-secondary" type="button" disabled={!landing} onClick={() => { setMobileView('preview'); previewPanel.current?.scrollIntoView({ block: 'start', behavior: 'smooth' }); }}>Previsualizar</button></div><p role="status" className="mt-3 text-sm">{form.isDirty ? 'Cambios sin guardar. La preview muestra la última versión guardada.' : landing ? `Borrador guardado · revisión ${landing.revision}` : 'Guarda el primer borrador para ver la preview real.'}</p></div>
                    <fieldset disabled={busy} className="space-y-4">
                        <Group title="Identificación" open><div><label htmlFor="landing-title" className="label">Nombre interno</label><input className="input" id="landing-title" required maxLength={120} value={form.data.title} onChange={event => form.setData('title', event.target.value)} /></div><div><label htmlFor="landing-slug" className="label">Slug público</label><input className="input" id="landing-slug" required pattern="[a-z0-9]+(-[a-z0-9]+)*" maxLength={100} readOnly={!!landing?.published_at || landing?.slug === home_slug} value={form.data.slug} onChange={event => form.setData('slug', event.target.value)} /><p className="mt-2 break-all text-xs text-text-secondary">https://jakawi.club/{form.data.slug || 'tu-landing'} {form.data.slug === home_slug ? '· También sirve la portada /' : ''} · Permanente tras publicar.</p></div></Group>
                        <Group title="Mensaje" open>{section('hero')}</Group>
                        <Group title="Presentación / plantilla" open><div><label htmlFor="landing-template" className="label">Plantilla</label><select id="landing-template" className="input" value={content.presentation.template} onChange={event => presentation({ template: event.target.value as LandingTemplate })}><option value="LEAD_VSL">LEAD_VSL · Presentación con video</option><option value="WEBINAR">WEBINAR · Encuentro y contenido</option><option value="WAITLIST">WAITLIST · Registro protagonista</option></select><p className="mt-3 text-sm text-text-secondary">Cambiar de plantilla conserva todos tus textos y medios.</p></div>{content.presentation.template === 'WEBINAR' && <><p className="text-sm">Los beneficios funcionan como contenido o agenda aprobada. Fecha y hora solo aparecen si están confirmadas en la configuración del encuentro. Seleccionar esta plantilla no crea eventos.</p><label className="checkbox-label"><input type="checkbox" className="checkbox" checked={content.presentation.show_video} onChange={event => presentation({ show_video: event.target.checked })} />Mostrar video en el encuentro</label></>}{content.presentation.template === 'WAITLIST' && <><p className="text-sm">Hero compacto, formulario primero y hasta tres beneficios. El resto del contenido y el video se conservan para otras plantillas.</p><label className="checkbox-label"><input type="checkbox" className="checkbox" checked={content.presentation.show_faq} onChange={event => presentation({ show_faq: event.target.checked })} />Mostrar FAQ en la lista de espera</label></>}</Group>
                        <Group title="Video y medios">{content.presentation.template === 'WAITLIST' && <p className="message">Esta plantilla conserva el video sin mostrarlo.</p>}{section('video')}</Group>
                        <Group title="Beneficios">{section('benefits')}</Group>
                        <Group title="Registro">{section('registration')}<p className="message">Estado real: {registration_open ? 'registro abierto' : 'registro público cerrado'}. Los textos no cambian la habilitación operativa.</p></Group>
                        <Group title="Después del registro">{section('after_registration')}
                            <p className="text-sm text-text-secondary">URL de confirmación: /{form.data.slug || '{slug}'}/confirmado. Requiere una solicitud procesada por el servidor. El estado de activación se muestra automáticamente.</p>
                            <div><label htmlFor="next-action" className="label">Acción siguiente</label><select id="next-action" className="input" value={content.next_step.action} onChange={event => form.setData('configuration', { ...content, next_step: { ...content.next_step, action: event.target.value as LandingContent['next_step']['action'] } })}><option value="none">Sin botón siguiente</option><option value="recover">Solicitar enlace de acceso</option><option value="account">Ingresar a mi cuenta</option><option value="home">Volver al inicio</option><option value="whatsapp">Acceder al grupo de WhatsApp</option></select></div>
                            {content.next_step.action === 'whatsapp' && <><p role="status" className="message">{whatsapp_group_available ? 'Grupo aprobado disponible.' : 'Sin grupo aprobado configurado. No se mostrará un enlace ni se redirigirá.'}</p><div><label htmlFor="next-countdown" className="label">Redirección al grupo</label><select id="next-countdown" className="input" value={content.next_step.redirect_seconds} onChange={event => form.setData('configuration', { ...content, next_step: { ...content.next_step, redirect_seconds: Number(event.target.value) as LandingContent['next_step']['redirect_seconds'] } })}>{[0, 5, 10, 15, 30, 60].map(seconds => <option key={seconds} value={seconds}>{seconds ? `Después de ${seconds} segundos (cancelable)` : 'Solo al pulsar el botón'}</option>)}</select></div></>}
                            <p className="text-xs text-text-secondary">El JSON solo incluye los textos. El destino aprobado y el contador se administran con estos controles; guardar e importar no publican.</p>
                        </Group>
                        <Group title="FAQ">{section('faq')}</Group>
                        <Group title="Cierre">{section('final')}</Group>
                        <Group title="Publicación / campaña"><div><label htmlFor="landing-campaign" className="label">Identificador de campaña (opcional)</label><input className="input" id="landing-campaign" pattern="[a-zA-Z0-9_-]+" maxLength={80} value={form.data.campaign} onChange={event => form.setData('campaign', event.target.value)} /><p className="mt-2 text-xs text-text-secondary">Se aplica a los conteos propios al publicar.</p></div><h3 className="font-bold">Buscadores</h3>{section('seo')}</Group>
                    </fieldset>
                </form>
                {landing && <section className="feature-card mt-5" aria-labelledby="publication-title"><h2 className="text-xl font-bold" id="publication-title">Publicación</h2><p className="mt-3 text-sm" data-publication-state>{pendingPublication ? 'Cambios pendientes de publicar.' : 'Versión publicada. El borrador guardado coincide con la publicación.'}{form.isDirty ? ' Guarda primero los cambios del formulario.' : ''}</p><div className="mt-5 flex flex-wrap gap-3"><button type="button" className="btn btn-primary" disabled={!saved || busy} onClick={() => operation.post(`/admin/landings/${landing.id}/publicar`, { preserveScroll: true })}>Publicar versión guardada</button>{landing.status === 'published' && <a href={`/${landing.slug}`} className="btn btn-secondary" target="_blank" rel="noreferrer">Abrir pública</a>}</div>{landing.published_at && <p className="mt-4 text-sm">Última publicación: {new Intl.DateTimeFormat('es-BO', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/La_Paz' }).format(new Date(landing.published_at))} (Bolivia).</p>}<details className="mt-5"><summary className="font-bold">Más acciones</summary><div className="mt-4 flex flex-wrap gap-3"><button type="button" className="btn btn-secondary" disabled={!saved || busy} onClick={() => operation.post(`/admin/landings/${landing.id}/duplicar`)}>Duplicar</button><button type="button" className="btn btn-secondary" disabled={!saved || busy || landing.slug === home_slug || landing.status === 'archived'} onClick={() => operation.post(`/admin/landings/${landing.id}/archivar`, { preserveScroll: true })}>Archivar</button></div></details></section>}
                {landing && <details className="feature-card mt-5"><summary className="font-bold">Analítica propia</summary><p className="mt-3 text-sm text-text-secondary">Conteos agregados por evento y campaña publicada. La preview no genera eventos.</p>{metrics.length ? <div className="mt-5 overflow-x-auto"><table className="w-full text-left text-sm"><thead><tr><th className="p-2">Evento</th><th className="p-2">Campaña</th><th className="p-2">Total</th></tr></thead><tbody>{metrics.map(item => <tr key={`${item.event}-${item.campaign}`}><td className="p-2">{item.event}</td><td className="p-2">{item.campaign || 'Sin campaña'}</td><td className="p-2">{item.total}</td></tr>)}</tbody></table></div> : <p className="mt-5 text-sm">Todavía no hay eventos.</p>}</details>}
            </div>
            <aside className="landing-editor-preview" ref={previewPanel} aria-label="Preview real"><div className="feature-card"><h2 className="text-xl font-bold">Preview real</h2><p className="my-3 text-sm text-text-secondary">Último borrador guardado · mismo renderer público · registro deshabilitado.</p><div className="mb-4 flex flex-wrap gap-2" role="group" aria-label="Página de la vista previa"><button type="button" className="btn btn-secondary" aria-pressed={!confirmationPreview} onClick={() => setConfirmationPreview(false)}>Landing</button><button type="button" className="btn btn-secondary" aria-pressed={confirmationPreview} onClick={() => setConfirmationPreview(true)}>Después del registro</button></div><div className="mb-4 flex flex-wrap gap-2" role="group" aria-label="Ancho de la vista previa">{[390, 430, 1440].map(size => <button type="button" key={size} className="btn btn-secondary" aria-pressed={width === size} onClick={() => setWidth(size)}>{size === 1440 ? 'Desktop' : size}</button>)}</div>{landing ? <><SavedPreview landing={landing} width={width} confirmation={confirmationPreview} /><a className="mt-4 inline-block text-sm underline" href={`/admin/landings/${landing.id}/preview${confirmationPreview ? '/confirmado' : ''}`} target="_blank" rel="noreferrer">Abrir preview a tamaño completo ↗</a></> : <p className="message">Guarda el primer borrador para cargar la preview real.</p>}</div></aside>
        </div>
        <dialog ref={dialog} className="editor-tool-dialog" onCancel={() => setTool(null)} aria-labelledby="tool-title"><div className="flex items-start justify-between gap-4"><h2 id="tool-title" className="text-xl font-bold">{tool === 'import' ? 'Importar JSON editorial' : tool === 'brief' ? 'Brief para IA' : 'Exportar JSON actual'}</h2><button type="button" className="btn btn-secondary" disabled={importing} onClick={() => setTool(null)} aria-label="Cerrar herramienta">Cerrar</button></div>
            {tool === 'import' ? <form className="mt-5 space-y-4" onSubmit={importJSON}><p className="text-sm">jakawi-club-landing-v2 · Pega texto o elige archivo. Se aplica solo al formulario; conserva campos omitidos. Compatible con v1. No modifica destinos, contador, video, poster, campaña ni publicación.</p><div><label htmlFor="editorial-file" className="label">Archivo JSON · hasta 64 KiB</label><input id="editorial-file" className="input" type="file" accept=".json,application/json" disabled={importing} onChange={async event => { const file = event.target.files?.[0]; if (!file) return; if (file.size > 65536) { setImportErrors({ editorial_json: ['El archivo supera 64 KiB.'] }); return; } try { setJson(await file.text()); setImportErrors({}); } catch { setImportErrors({ editorial_json: ['No se pudo leer el archivo.'] }); } }} /></div><label className="label" htmlFor="editorial-json">Contenido JSON</label><textarea id="editorial-json" className="input font-mono" rows={12} required value={json} disabled={importing} onChange={event => setJson(event.target.value)} />{Object.entries(importErrors).map(([path, messages]) => <div key={path}><ErrorText>{`${path}: ${messages.join(' ')}`}</ErrorText></div>)}<button className="btn btn-primary" disabled={importing} type="submit">{importing ? 'Validando…' : 'Aplicar al formulario'}</button></form> : <><p className="my-4 text-sm">{tool === 'export' ? 'Incluye los valores actuales y los cambios sin guardar. Solo contenido editorial y plantilla.' : 'Contenido propio de jakawi.club para trabajar con tu copywriter o GPT externo.'}</p><label htmlFor="editorial-output" className="label">{tool === 'brief' ? 'Brief estructurado' : 'jakawi-club-landing-v2'}</label><textarea id="editorial-output" className="input font-mono" rows={14} readOnly value={tool === 'brief' ? brief() : editorialJSON(content)} onFocus={event => event.target.select()} /><div className="mt-4 flex flex-wrap gap-3"><button className="btn btn-secondary" type="button" onClick={() => void copy(tool === 'brief' ? brief() : editorialJSON(content))}>Copiar contenido</button>{tool === 'export' && <button className="btn btn-primary" type="button" onClick={() => { const url = URL.createObjectURL(new Blob([editorialJSON(content)], { type: 'application/json' })); const link = document.createElement('a'); link.href = url; link.download = `${form.data.slug || 'landing'}.json`; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); }}>Descargar JSON</button>}</div></>}
        </dialog>
    </PageFrame></>;
}
