import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { ErrorText, PageFrame } from '../../components/UI';
import { aiBrief, editorialJSON, sameConfiguration } from '../../lib/landing-editorial';
import type { LandingContent, LandingRecord, LandingTemplate } from '../../types';
import { benefitIconOptions } from '../../lib/benefit-icons';
import { LandingAiKit } from '../../components/admin/LandingAiKit';
import { ClubCardImage } from '../../components/ClubCardPresentation';
import { statusLabel } from './Landings';

type Value = string | null | { [key: string]: Value } | Value[];
const labels: Record<string, string> = {
    webinar: 'Contenido del webinar', opportunity: 'Problema / oportunidad', early: 'Beneficios de participar desde el comienzo', steps: 'Cómo funciona', seo: 'Buscadores', hero: 'Mensaje', benefits: 'Beneficios', registration: 'Introducción al registro', faq: 'Preguntas frecuentes', final: 'Cierre', video: 'Video VSL',
    message: 'Mensaje de confirmación', instructions: 'Instrucción de revisar correo', cta_label: 'Texto del botón siguiente', title: 'Título', description: 'Descripción', eyebrow: 'Antetítulo', emphasis: 'Texto destacado', ending: 'Final del titular', cta: 'Texto del botón',
    form_title: 'Título del formulario', form_subtitle: 'Subtítulo del formulario (opcional)', name_placeholder: 'Placeholder del nombre', phone_placeholder: 'Placeholder del teléfono', email_placeholder: 'Placeholder del email', submit_label: 'CTA del formulario (vacío: automático)', submit_microcopy: 'Microcopy debajo del botón (opcional)', existing_account_label: 'Texto de cuenta existente', existing_account_link_label: 'Texto del enlace de acceso',
    icon: 'Icono', items: 'Elementos', question: 'Pregunta', answer: 'Respuesta', youtube_url: 'URL de YouTube', poster: 'Poster local (opcional)', aspect_ratio: 'Marco del reproductor', source_aspect_ratio: 'Proporción del video original',
};

function Fields({ value, path, onChange }: { value: Value; path: string; onChange: (value: Value) => void }) {
    const fixedItems = path.startsWith('content.webinar.') && path.endsWith('.items');
    const key = path.split('.').at(-1) || path;
    if (Array.isArray(value)) return <div className="space-y-4">{value.map((item, index) => <fieldset className="rounded-card border border-border p-4" key={index}>
        <legend className="px-2 text-sm font-bold">Elemento {index + 1}</legend><Fields value={item} path={`${path}.${index}`} onChange={next => onChange(value.map((entry, i) => i === index ? next : entry))} />
        {!fixedItems && <button type="button" className="btn btn-secondary mt-4" disabled={value.length <= 1} onClick={() => onChange(value.filter((_, i) => i !== index))}>Quitar elemento</button>}
    </fieldset>)}{!fixedItems && <button type="button" className="btn btn-secondary" disabled={value.length >= 8} onClick={() => onChange([...value, Object.fromEntries(Object.keys(value[0] || {}).filter(name => name !== 'icon' || path !== 'content.benefits.items').map(name => [name, '']))])}>Añadir elemento</button>}</div>;
    if (value !== null && typeof value === 'object') {
        const benefitItem = /^content\.benefits\.items\.\d+$/.test(path);
        const fields = benefitItem ? { ...value, icon: value.icon ?? '' } : value;
        return <div className="space-y-5">{Object.entries(fields).filter(([name]) => !(path === 'content.after_registration' && name === 'cta_label') && !(['content.hero', 'content.final'].includes(path) && name === 'cta')).map(([name, entry]) => <Fields key={name} value={entry} path={`${path}.${name}`} onChange={next => {
            const updated = { ...value, [name]: next };
            if (benefitItem && name === 'icon' && next === '') delete updated.icon;
            onChange(updated);
        }} />)}</div>;
    }
    const options = key === 'icon' ? [['', 'Automático (icono actual)'], ...benefitIconOptions] : key === 'source_aspect_ratio' ? ['9:16', '3:4', '16:9'].map(option => [option, option]) : null;
    const optional = ['poster', 'youtube_url', 'submit_label', 'form_subtitle', 'submit_microcopy'].includes(key);
    const nullable = ['poster', 'youtube_url', 'submit_label'].includes(key);
    return <div><label className="label" htmlFor={path}>{labels[key] || key}</label>{options ? <select className="input" id={path} value={value || ''} onChange={event => onChange(event.target.value)}>{options.map(([option, label]) => <option key={option} value={option}>{label}</option>)}</select> :
        <textarea className="input" id={path} rows={key === 'description' || key === 'answer' ? 3 : 2} maxLength={['poster', 'youtube_url'].includes(key) ? 500 : 1200} required={!optional} value={value || ''} onChange={event => onChange(nullable && !event.target.value ? null : event.target.value)} />}
        {key === 'submit_label' && <p className="mt-2 text-xs text-text-secondary">Vacío usa el CTA del hero en WEBINAR y el CTA histórico en las demás plantillas. Los botones hacia registro y el sticky comparten esta acción. Solo texto, sin HTML ni URLs.</p>}
        {key === 'poster' && <p className="mt-2 text-xs text-text-secondary">Archivo PNG, JPEG o WebP existente en /marketing/. Vacío conserva el estado sin poster.</p>}
    </div>;
}

function Group({ title, children, open = false }: { title: string; children: ReactNode; open?: boolean }) {
    return <details className="feature-card" open={open}><summary className="text-lg font-bold">{title}</summary><div className="mt-6 space-y-5">{children}</div></details>;
}

function WhatsAppFields({ path, mode, url, options, onMode, onUrl }: {
    path: string; mode: string; url: string | null; options: [string, string][];
    onMode: (mode: string) => void; onUrl: (url: string | null) => void;
}) {
    return <div className="space-y-4">
        <div><label className="label" htmlFor={`${path}.mode`}>Grupo de WhatsApp</label><select className="input" id={`${path}.mode`} value={mode} onChange={event => onMode(event.target.value)}>{options.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></div>
        {mode === 'custom' && <div><label className="label" htmlFor={`${path}.url`}>URL de invitación al grupo</label><input className="input" id={`${path}.url`} type="url" required maxLength={500} pattern={'https://chat\\.whatsapp\\.com/[A-Za-z0-9]{10,64}'} value={url || ''} onChange={event => onUrl(event.target.value || null)} /><p className="mt-2 text-xs text-text-secondary">Solo invitaciones HTTPS de chat.whatsapp.com, sin parámetros, fragmentos ni redirecciones.</p></div>}
    </div>;
}

function VideoFields({ video, path, onChange, defaultUrl = null }: { video: LandingContent['video']; path: string; onChange: (video: LandingContent['video']) => void; defaultUrl?: string | null }) {
    const patch = (value: Partial<LandingContent['video']>) => onChange({ ...video, ...value });
    return <div className="space-y-5">
        <p className="text-sm font-semibold">Marco fijo vertical 3:4 · desktop y móvil</p>
        <div><label className="label" htmlFor={`${path}.source_type`}>Fuente compatible</label><select className="input" id={`${path}.source_type`} value="youtube" onChange={() => patch({ source_type: 'youtube' })}><option value="youtube">YouTube · reproductor VSL oficial</option></select></div>
        <Fields value={video.youtube_url} path={`${path}.youtube_url`} onChange={value => patch({ youtube_url: value as string | null })} />
        <Fields value={video.poster} path={`${path}.poster`} onChange={value => patch({ poster: value as string | null })} />
        <Fields value={video.source_aspect_ratio} path={`${path}.source_aspect_ratio`} onChange={value => patch({ source_aspect_ratio: value as LandingContent['video']['source_aspect_ratio'] })} />
        <p className="text-xs text-text-secondary">9:16 recorta el contenido vertical dentro del lienzo de YouTube. El marco siempre conserva 3:4 sin deformar.</p>
        {(['focal_x', 'focal_y'] as const).map(key => <div key={key}><label className="label" htmlFor={`${path}.${key}`}>{key === 'focal_x' ? 'Encuadre horizontal' : 'Encuadre vertical'} · {Math.round(video[key] * 100)}%</label><input className="w-full" id={`${path}.${key}`} type="range" min="0" max="1" step="0.01" value={video[key]} onChange={event => patch({ [key]: Number(event.target.value) })} /></div>)}
        {!video.youtube_url && <p className="message" role="status">{defaultUrl ? 'Se usará el video predeterminado del entorno. Una URL de esta landing tendrá prioridad.' : 'Sin video configurado. El reproductor público estará oculto.'}</p>}
    </div>;
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

type Props = { ai_kit_zip_available?: boolean; landing: LandingRecord | null; defaults: LandingContent | null; webinar_defaults: LandingContent; home_slug: string; registration_open: boolean; whatsapp_group_available: boolean; confirmation_video_default_url: string | null; metrics?: { event: string; campaign: string; total: number }[] };
export default function LandingEditor({ ai_kit_zip_available = false, landing, defaults, webinar_defaults, home_slug, registration_open, whatsapp_group_available, confirmation_video_default_url, metrics = [] }: Props) {
    const initial = () => ({ title: landing?.title || '', slug: landing?.slug || '', campaign: landing?.campaign || '', configuration: landing?.configuration || defaults!, revision: landing?.revision || 1 });
    const form = useForm(initial());
    const operation = useForm({ revision: landing?.revision || 1 });
    const [width, setWidth] = useState(390);
    const [stage, setStage] = useState<'landing' | 'confirmation'>('landing');
    const confirmationPreview = stage === 'confirmation';
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
        if (landing) form.put(`/admin/landings/${landing.id}`, { preserveScroll: true, onError: focusErrors });
        else form.post('/admin/landings', { onError: focusErrors });
    }
    function focusErrors(errors: Record<string, string>) {
        const key = Object.keys(errors)[0] || '';
        setStage(/after_registration|confirmation_video|confirmation_whatsapp_group|show_confirmation_video/.test(key) ? 'confirmation' : 'landing');
        setMobileView('editor');
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
            const response = await fetch(landing ? `/admin/landings/${landing.id}/import` : '/admin/landings/import', {
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
    // Present legacy inherited settings in this single confirmation control.
    const group = content.confirmation_whatsapp_group.mode === 'inherit'
        ? content.whatsapp_group : content.confirmation_whatsapp_group;
    const groupMode = group.mode === 'custom' ? 'custom' : group.mode === 'none' ? 'none' : 'default';
    const confirmationGroupAvailable = groupMode === 'default' ? whatsapp_group_available : groupMode === 'custom' && /^https:\/\/chat\.whatsapp\.com\/[A-Za-z0-9]{10,64}$/.test(group.url || '');
    const landingURL = `https://jakawi.club/${landing?.slug || form.data.slug || 'tu-landing'}`;
    const confirmationURL = `${landingURL}/gracias`;
    const publishedVideo = landing?.published_configuration?.presentation.show_video ? landing.published_configuration.video.youtube_url : null;
    function section(name: 'hero' | 'seo' | 'benefits' | 'registration' | 'faq' | 'final' | 'after_registration') {
        return <Fields value={content[name] as Value} path={`content.${name}`} onChange={next => form.setData('configuration', { ...content, [name]: next } as LandingContent)} />;
    }
    function presentation(patch: Partial<LandingContent['presentation']>) {
        form.setData('configuration', { ...content, presentation: { ...content.presentation, ...patch } });
    }
    const webinarSettings = content.presentation.webinar;
    const sectionNames = { hero: 'Hero y video', event: 'Evento y fecha', benefits: 'Qué descubrirás (primeros tres beneficios)', opportunity: 'Problema / oportunidad', early: 'Participar desde el comienzo', steps: 'Cómo funciona', registration: 'Formulario', faq: 'FAQ', final: 'CTA final' };
    function moveSection(index: number, direction: number) {
        if (!webinarSettings) return;
        const sections = [...webinarSettings.sections];
        [sections[index], sections[index + direction]] = [sections[index + direction], sections[index]];
        presentation({ webinar: { ...webinarSettings, sections } });
    }
    return <><Head title={landing ? `Editar ${landing.title}` : 'Crear landing'} /><PageFrame wide>
        <Link href="/admin/landings" className="text-sm underline">← Todas las landings</Link>
        <div className="my-7"><p className="eyebrow">{content.presentation.template} · {landing ? statusLabel[landing.status] : 'NUEVO BORRADOR'} {landing?.slug === home_slug ? '· PORTADA PRINCIPAL' : ''}</p><h1 className="section-title mt-4">{landing ? landing.title : 'Una nueva landing'}</h1><p className="mt-4 max-w-3xl text-text-secondary">Edita, guarda y revisa el resultado. Publica la versión guardada cuando esté lista.</p></div>
        <section className="editor-save-bar mb-5" aria-label="Guardar y publicar">
            <div className="editor-save-actions">
                <button className="btn btn-primary" form="landing-form" disabled={busy} type="submit">{form.processing ? 'Guardando…' : 'Guardar borrador'}</button>
                <button className="btn btn-secondary" type="button" disabled={!saved || busy || !pendingPublication} onClick={() => landing && operation.post(`/admin/landings/${landing.id}/publicar`, { preserveScroll: true, onError: focusErrors })}>{operation.processing ? 'Publicando…' : 'Publicar cambios'}</button>
                <button className="editor-preview-action" type="button" disabled={!landing} onClick={() => { setMobileView('preview'); previewPanel.current?.scrollIntoView({ block: 'start', behavior: 'smooth' }); }}>Previsualizar</button>
            </div>
            <p role="status" className="mt-3 text-sm font-semibold" data-publication-state>{form.isDirty ? 'Cambios pendientes · sin guardar.' : pendingPublication ? 'Cambios pendientes · borrador guardado, falta publicar.' : landing ? 'Publicado · el borrador coincide con la versión pública.' : 'Nuevo borrador · guarda antes de publicar.'}</p>
            <p className="mt-2 text-xs text-text-secondary">Guardar actualiza el borrador y la preview. Publicar cambios actualiza ambas páginas públicas.</p>
            {landing?.status === 'published' && <details className="mt-2 text-xs text-text-secondary" data-published-video><summary>Video publicado de captación</summary><p className="mt-2 break-all">{publishedVideo || 'oculto o sin URL'}</p></details>}
        </section>
        <div className="editor-stage-tabs mb-5" role="tablist" aria-label="Etapas del embudo">
            {([['landing', 'LANDING DE CAPTACIÓN'], ['confirmation', 'PÁGINA DE CONFIRMACIÓN']] as const).map(([value, label], index) => <button key={value} id={`stage-${value}`} type="button" role="tab" aria-selected={stage === value} aria-controls="stage-fields" tabIndex={stage === value ? 0 : -1} className="btn btn-secondary" onClick={() => setStage(value)} onKeyDown={event => { if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) { event.preventDefault(); const next = event.key === 'Home' ? 'landing' : event.key === 'End' ? 'confirmation' : index === 0 ? 'confirmation' : 'landing'; setStage(next); document.getElementById(`stage-${next}`)?.focus(); } }}>{label}</button>)}
        </div>
        <div className="editor-page-urls mb-5" aria-label="URLs de la campaña">
            {[[landingURL, 'Landing'], [confirmationURL, 'Confirmación']].map(([url, label]) => <div key={label} className="rounded-card border border-border p-4"><p className="text-sm font-bold">{label}</p><p className="my-2 break-all text-xs">{url}</p><button type="button" className="text-xs font-semibold underline" onClick={() => void copy(url)}>Copiar URL de {label.toLowerCase()}</button></div>)}
        </div>
        <div className="mb-5 flex flex-wrap gap-3" aria-label="Herramientas editoriales">
            <button type="button" className="btn btn-secondary" onClick={async () => { if (!await copy(brief())) setTool('brief'); }}>COPIAR BRIEF PARA IA</button>
            <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => { setImportErrors({}); setTool('import'); }}>IMPORTAR JSON</button>
            <button type="button" className="btn btn-secondary" onClick={() => setTool('export')}>EXPORTAR JSON</button>
        </div>
        <LandingAiKit zipAvailable={ai_kit_zip_available} />
        {notice && <p className="message mb-5" role="status">{notice}</p>}
        {!!Object.keys(errors).length && <div role="alert" className="message mb-6"><p className="font-bold">Revisa los campos:</p><ul className="mt-2 list-inside list-disc">{Object.entries(errors).map(([path, error]) => <li key={path}><strong>{path}</strong>: {error}</li>)}</ul></div>}
        <div className="editor-mobile-tabs mb-4" role="group" aria-label="Panel del editor"><button className="btn btn-secondary" aria-pressed={mobileView === 'editor'} onClick={() => setMobileView('editor')}>Editor</button><button className="btn btn-secondary" aria-pressed={mobileView === 'preview'} onClick={() => setMobileView('preview')}>Preview</button></div>
        <div className="landing-editor-grid" data-mobile-view={mobileView}>
            <div className="landing-editor-content">
                <form id="landing-form" onSubmit={save}>
                    <fieldset id="stage-fields" role="tabpanel" aria-labelledby={`stage-${stage}`} disabled={busy} className="space-y-4">
                        {!confirmationPreview && <>
                        <Group title="Identificación" open><div><label htmlFor="landing-title" className="label">Nombre interno</label><input className="input" id="landing-title" required maxLength={120} value={form.data.title} onChange={event => form.setData('title', event.target.value)} /></div><div><label htmlFor="landing-slug" className="label">Slug público</label><input className="input" id="landing-slug" required pattern="[a-z0-9]+(-[a-z0-9]+)*" maxLength={100} readOnly={!!landing?.published_at || landing?.slug === home_slug} value={form.data.slug} onChange={event => form.setData('slug', event.target.value)} /><p className="mt-2 break-all text-xs text-text-secondary">https://jakawi.club/{form.data.slug || 'tu-landing'} {form.data.slug === home_slug ? '· También sirve la portada /' : ''} · Permanente tras publicar.</p></div></Group>
                        <Group title="Mensaje" open>{section('hero')}</Group>
                        <Group title="Presentación / plantilla" open><div><label htmlFor="landing-template" className="label">Plantilla</label><select id="landing-template" className="input" value={content.presentation.template} onChange={event => presentation({ template: event.target.value as LandingTemplate })}><option value="LEAD_VSL">LEAD_VSL · Presentación con video</option><option value="WEBINAR">WEBINAR · Encuentro y contenido</option><option value="WAITLIST">WAITLIST · Registro protagonista</option></select><p className="mt-3 text-sm text-text-secondary">Cambiar de plantilla conserva todos tus textos y medios. El texto del botón del Hero se usa en todos los CTA de la composición WEBINAR completa.</p></div>{content.presentation.template === 'WEBINAR' && <><p className="text-sm">La composición completa es opcional. Las landings históricas conservan su composición hasta habilitarla.</p>
                            {!landing && <button type="button" className="btn btn-secondary" onClick={() => { form.setData('configuration', structuredClone(webinar_defaults)); setNotice('Contenido inicial WEBINAR aplicado al formulario. Pendiente de guardar; no se publicó.'); }}>Usar contenido inicial WEBINAR</button>}
                            {!webinarSettings && <button type="button" className="btn btn-secondary" onClick={() => form.setData('configuration', { ...content, webinar: content.webinar || structuredClone(webinar_defaults.webinar!), presentation: { ...content.presentation, webinar: structuredClone(webinar_defaults.presentation.webinar!) } })}>Habilitar composición WEBINAR</button>}
                            {webinarSettings && <>
                                <div><label className="label" htmlFor="webinar-event-title">Nombre del evento</label><input id="webinar-event-title" className="input" required maxLength={1200} value={webinarSettings.title} onChange={event => presentation({ webinar: { ...webinarSettings, title: event.target.value } })} /></div>
                                <div><label className="label" htmlFor="webinar-starts-at">Fecha y hora confirmadas · America/La_Paz (Bolivia)</label><input id="webinar-starts-at" className="input" type="datetime-local" min="2000-01-01T00:00" max="9999-12-31T23:59" value={webinarSettings.starts_at?.slice(0, 16) || ''} onChange={event => presentation({ webinar: { ...webinarSettings, starts_at: event.target.value ? `${event.target.value}:00-04:00` : null } })} /><p className="mt-2 text-xs text-text-secondary">Opcional. Vacía muestra Próximo webinar en vivo y oculta el countdown. El contador termina en la fecha guardada y nunca se reinicia.</p></div>
                                <Fields value={content.hero.cta} path="content.hero.cta" onChange={value => form.setData('configuration', { ...content, hero: { ...content.hero, cta: value as string } })} />
                                <h3 className="font-bold">Orden y visibilidad de secciones</h3>
                                <p className="text-xs text-text-secondary">El formulario permanece visible para que todos los CTA tengan destino. Ocultar conserva los textos.</p>
                                {webinarSettings.sections.map((entry, index) => <div key={entry.id} className="rounded-card border border-border p-3"><label className="checkbox-label"><input type="checkbox" className="checkbox" checked={entry.visible} disabled={entry.id === 'registration'} onChange={event => presentation({ webinar: { ...webinarSettings, sections: webinarSettings.sections.map(item => item.id === entry.id ? { ...item, visible: event.target.checked } : item) } })} />{sectionNames[entry.id]}</label><div className="mt-2 flex gap-2"><button type="button" className="btn btn-secondary" aria-label={`Subir ${sectionNames[entry.id]}`} disabled={index === 0} onClick={() => moveSection(index, -1)}>↑ Subir</button><button type="button" className="btn btn-secondary" aria-label={`Bajar ${sectionNames[entry.id]}`} disabled={index === webinarSettings.sections.length - 1} onClick={() => moveSection(index, 1)}>↓ Bajar</button></div></div>)}
                            </>}
</>}{content.presentation.template === 'WAITLIST' && <><p className="text-sm">Hero compacto, formulario primero y hasta tres beneficios. El video principal es opcional y se configura en Video y medios.</p><label className="checkbox-label"><input type="checkbox" className="checkbox" checked={content.presentation.show_faq} onChange={event => presentation({ show_faq: event.target.checked })} />Mostrar FAQ en la lista de espera</label></>}</Group>
                        <Group title="IMAGEN Y PRESENTACIÓN COMERCIAL" open>
                            <p className="text-sm text-text-secondary">Se usa en el catálogo y al compartir. El título procede de Buscadores; vacío usa el nombre público al publicar.</p>
                            <div><label htmlFor="commercial-image" className="label">Imagen promocional de tarjeta (opcional)</label><input id="commercial-image" className="input" maxLength={500} placeholder="/marketing/imagen.webp" value={content.commercial?.image || ''} onChange={event => form.setData('configuration', { ...content, commercial: { ...content.commercial, description: content.commercial?.description || null, image: event.target.value || null } })} /><p className="mt-2 text-xs text-text-secondary">Recomendado 16:9. Archivo PNG, JPEG o WebP existente en /marketing/, igual que los posters. Sin imagen se usa el poster publicado; si falta, la marca JAKAWI.</p></div>
                            <div><label htmlFor="commercial-description" className="label">Descripción corta comercial (opcional)</label><textarea id="commercial-description" className="input" rows={3} maxLength={320} value={content.commercial?.description || ''} onChange={event => form.setData('configuration', { ...content, commercial: { ...content.commercial, image: content.commercial?.image || null, description: event.target.value || null } })} /><p className="mt-2 text-xs text-text-secondary">Vacía usa la descripción del hero. Solo texto; se resume para la tarjeta.</p></div>
                            <div data-commercial-preview className="mx-auto max-w-sm overflow-hidden rounded-card border border-border bg-surface">
                                <ClubCardImage image={content.commercial?.image || content.video.poster} title={content.seo.title.trim() || form.data.title || 'JAKAWI CLUB'} />
                                <div className="p-5"><p className="text-xs font-semibold">Captación · CLUB · Promocionable</p><h3 className="mt-2 line-clamp-2 break-words text-xl font-extrabold">{content.seo.title.trim() || form.data.title || 'JAKAWI CLUB'}</h3><p className="mt-3 line-clamp-3 text-sm text-text-secondary">{(content.commercial?.description?.trim() || content.hero.description).slice(0, 200)}{(content.commercial?.description?.trim() || content.hero.description).length > 200 ? '…' : ''}</p></div>
                            </div>
                            <p className="text-xs text-text-secondary">Preview del borrador actual. Guardar borrador no cambia el catálogo; Publicar cambios actualiza su versión pública.</p>
                        </Group>
                        <Group title="Video y medios"><label className="checkbox-label"><input id="show-main-video" type="checkbox" className="checkbox" checked={content.presentation.show_video} onChange={event => presentation({ show_video: event.target.checked })} />Mostrar video principal</label><VideoFields video={content.video} path="content.video" onChange={video => form.setData('configuration', { ...content, video })} /></Group>
                        {content.presentation.template === 'WEBINAR' && content.webinar && <Group title="Contenido del webinar">{(['opportunity', 'early', 'steps'] as const).map(name => <fieldset key={name} className="space-y-4 rounded-card border border-border p-4"><legend className="px-2 font-bold">{sectionNames[name]}</legend><Fields value={content.webinar![name] as Value} path={`content.webinar.${name}`} onChange={next => form.setData('configuration', { ...content, webinar: { ...content.webinar!, [name]: next } as LandingContent['webinar'] })} /></fieldset>)}</Group>}
                        <Group title="Beneficios">{section('benefits')}</Group>
                        <Group title="Registro">{section('registration')}<p className="message">Estado real: {registration_open ? 'registro abierto' : 'registro público cerrado'}. Los textos no cambian la habilitación operativa.</p></Group>
                        <Group title="FAQ">{section('faq')}</Group>
                        <Group title="Cierre">{section('final')}</Group>
                        <Group title="Publicación / campaña"><div><label htmlFor="landing-campaign" className="label">Identificador de campaña (opcional)</label><input className="input" id="landing-campaign" pattern="[a-zA-Z0-9_-]+" maxLength={80} value={form.data.campaign} onChange={event => form.setData('campaign', event.target.value)} /><p className="mt-2 text-xs text-text-secondary">Se aplica a los conteos propios al publicar.</p></div><h3 className="font-bold">Buscadores</h3>{section('seo')}</Group>
                        </>}
                        {confirmationPreview && <>
                        <Group title="Página de confirmación" open>{section('after_registration')}
                            <h3 className="font-bold">Video explicativo de esta landing</h3>
                            <label className="checkbox-label"><input id="show-confirmation-video" type="checkbox" className="checkbox" checked={content.presentation.show_confirmation_video} onChange={event => presentation({ show_confirmation_video: event.target.checked })} />Mostrar video de confirmación</label>
                            <VideoFields video={content.confirmation_video} path="content.confirmation_video" defaultUrl={confirmation_video_default_url} onChange={confirmation_video => form.setData('configuration', { ...content, confirmation_video })} />
                            <h3 className="font-bold">Grupo de WhatsApp de confirmación</h3>
                            <WhatsAppFields path="content.confirmation_whatsapp_group" mode={groupMode} url={group.url} options={[["default", "Usar fallback del entorno"], ["custom", "Grupo específico de la campaña"], ["none", "Ocultar grupo"]]} onMode={mode => form.setData('configuration', { ...content, confirmation_whatsapp_group: { mode: mode as LandingContent['confirmation_whatsapp_group']['mode'], url: mode === 'custom' ? group.url : null } })} onUrl={url => form.setData('configuration', { ...content, confirmation_whatsapp_group: { mode: 'custom', url } })} />
                            <p className="message" role="status">{confirmationGroupAvailable ? 'La confirmación mostrará su grupo configurado al aceptar el registro.' : 'Sin grupo efectivo. El botón de WhatsApp y su redirección estarán ocultos.'}</p>
                            <p className="text-sm text-text-secondary">URL de confirmación: {confirmationURL}. El acceso anterior /confirmado sigue siendo válido. Requiere un recibo de registro vigente. El estado de activación se muestra automáticamente.</p>
                            <p className="text-sm text-text-secondary">La confirmación tiene un único botón: UNIRME AL GRUPO DE WHATSAPP. Al terminar el video, el visitante irá a su grupo efectivo. Las previews y las sesiones administrativas no redirigen.</p>
                            <p className="text-xs text-text-secondary">El JSON v3 incluye textos, composición, medios y grupos. Importar aplica al formulario; guardar y publicar son acciones independientes.</p>
                        </Group>
                        </>}
                    </fieldset>
                </form>
                {landing && <section className="feature-card mt-5" aria-labelledby="publication-title"><h2 className="text-xl font-bold" id="publication-title">Publicación</h2><div className="mt-5 flex flex-wrap gap-3">{landing.status === 'published' && <a href={`/${landing.slug}`} className="btn btn-secondary" target="_blank" rel="noreferrer">Abrir pública</a>}</div>{landing.published_at && <p className="mt-4 text-sm">Última publicación: {new Intl.DateTimeFormat('es-BO', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/La_Paz' }).format(new Date(landing.published_at))} (Bolivia).</p>}<details className="mt-5"><summary className="font-bold">Más acciones</summary><div className="mt-4 flex flex-wrap gap-3"><button type="button" className="btn btn-secondary" disabled={!saved || busy} onClick={() => operation.post(`/admin/landings/${landing.id}/duplicar`)}>Duplicar</button><button type="button" className="btn btn-secondary" disabled={!saved || busy || landing.slug === home_slug || landing.status === 'archived'} onClick={() => operation.post(`/admin/landings/${landing.id}/archivar`, { preserveScroll: true })}>Archivar</button></div></details></section>}
                {landing && <details className="feature-card mt-5"><summary className="font-bold">Analítica propia</summary><p className="mt-3 text-sm text-text-secondary">Conteos agregados por evento y campaña publicada. La preview no genera eventos.</p>{metrics.length ? <div className="mt-5 overflow-x-auto"><table className="w-full text-left text-sm"><thead><tr><th className="p-2">Evento</th><th className="p-2">Campaña</th><th className="p-2">Total</th></tr></thead><tbody>{metrics.map(item => <tr key={`${item.event}-${item.campaign}`}><td className="p-2">{item.event}</td><td className="p-2">{item.campaign || 'Sin campaña'}</td><td className="p-2">{item.total}</td></tr>)}</tbody></table></div> : <p className="mt-5 text-sm">Todavía no hay eventos.</p>}</details>}
            </div>
            <aside className="landing-editor-preview" ref={previewPanel} aria-label="Preview real"><div className="feature-card"><h2 className="text-xl font-bold">Preview · {confirmationPreview ? 'Confirmación' : 'Captación'}</h2><p className="my-3 text-sm text-text-secondary">Último borrador guardado · mismo renderer público · registro deshabilitado.</p><div className="mb-4 flex flex-wrap gap-2" role="group" aria-label="Ancho de la vista previa">{[390, 430, 1440].map(size => <button type="button" key={size} className="btn btn-secondary" aria-pressed={width === size} onClick={() => setWidth(size)}>{size === 1440 ? 'Desktop' : size}</button>)}</div>{landing ? <><SavedPreview landing={landing} width={width} confirmation={confirmationPreview} /><a className="mt-4 inline-block text-sm underline" href={`/admin/landings/${landing.id}/preview${confirmationPreview ? '/confirmado' : ''}`} target="_blank" rel="noreferrer">Abrir preview a tamaño completo ↗</a></> : <p className="message">Guarda el primer borrador para cargar la preview real.</p>}</div></aside>
        </div>
        <dialog ref={dialog} className="editor-tool-dialog" onCancel={() => setTool(null)} aria-labelledby="tool-title"><div className="flex items-start justify-between gap-4"><h2 id="tool-title" className="text-xl font-bold">{tool === 'import' ? 'Importar JSON editorial' : tool === 'brief' ? 'Brief para IA' : 'Exportar JSON actual'}</h2><button type="button" className="btn btn-secondary" disabled={importing} onClick={() => setTool(null)} aria-label="Cerrar herramienta">Cerrar</button></div>
            {tool === 'import' ? <form className="mt-5 space-y-4" onSubmit={importJSON}><p className="text-sm">jakawi-club-landing-v3 · Incluye textos, fecha, orden, visibilidad, ambos videos y grupos. Landing nueva: documento completo con configuración explícita; existente: admite parches y conserva campos omitidos. Compatible con v1/v2. Solo aplica al formulario; no guarda ni publica. No modifica identidad, campaña ni habilitación del registro.</p><div><label htmlFor="editorial-file" className="label">Archivo JSON · hasta 64 KiB</label><input id="editorial-file" className="input" type="file" accept=".json,application/json" disabled={importing} onChange={async event => { const file = event.target.files?.[0]; if (!file) return; if (file.size > 65536) { setImportErrors({ editorial_json: ['El archivo supera 64 KiB.'] }); return; } try { setJson(await file.text()); setImportErrors({}); } catch { setImportErrors({ editorial_json: ['No se pudo leer el archivo.'] }); } }} /></div><label className="label" htmlFor="editorial-json">Contenido JSON</label><textarea id="editorial-json" className="input font-mono" rows={12} required value={json} disabled={importing} onChange={event => setJson(event.target.value)} />{Object.entries(importErrors).map(([path, messages]) => <div key={path}><ErrorText>{`${path}: ${messages.join(' ')}`}</ErrorText></div>)}<button className="btn btn-primary" disabled={importing} type="submit">{importing ? 'Validando…' : 'Aplicar al formulario'}</button></form> : <><p className="my-4 text-sm">{tool === 'export' ? 'Incluye los valores actuales y cambios sin guardar: textos, composición, fecha, medios, visibilidad y grupos. No incluye identidad, campaña ni publicación.' : 'Contenido propio de jakawi.club para trabajar con tu copywriter o GPT externo.'}</p><label htmlFor="editorial-output" className="label">{tool === 'brief' ? 'Brief estructurado' : 'jakawi-club-landing-v3'}</label><textarea id="editorial-output" className="input font-mono" rows={14} readOnly value={tool === 'brief' ? brief() : editorialJSON(content)} onFocus={event => event.target.select()} /><div className="mt-4 flex flex-wrap gap-3"><button className="btn btn-secondary" type="button" onClick={() => void copy(tool === 'brief' ? brief() : editorialJSON(content))}>Copiar contenido</button>{tool === 'export' && <button className="btn btn-primary" type="button" onClick={() => { const url = URL.createObjectURL(new Blob([editorialJSON(content)], { type: 'application/json' })); const link = document.createElement('a'); link.href = url; link.download = `${form.data.slug || 'landing'}.json`; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); }}>Descargar JSON</button>}</div></>}
        </dialog>
    </PageFrame></>;
}
