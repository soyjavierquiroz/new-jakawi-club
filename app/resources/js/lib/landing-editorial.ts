import type { LandingContent } from '../types';

export const editorialSchema = 'jakawi-club-landing-v3';
export function sameConfiguration(left: unknown, right: unknown): boolean {
    function ordered(value: unknown): unknown {
        if (Array.isArray(value)) return value.map(ordered);
        if (value !== null && typeof value === 'object') return Object.fromEntries(Object.entries(value).sort(([a], [b]) => a.localeCompare(b)).map(([key, item]) => [key, ordered(item)]));
        return value;
    }
    return JSON.stringify(ordered(left)) === JSON.stringify(ordered(right));
}
export function editorialDocument(content: LandingContent) {
    // Keep the v3 allowlist aligned with LandingContent::EDITORIAL_V3. Legacy next_step stays out.
    const { seo, hero, benefits, registration, faq, final, after_registration, webinar, presentation, video, confirmation_video, whatsapp_group, confirmation_whatsapp_group } = content;
    return { schema: editorialSchema, template: presentation.template, content: { seo, hero, benefits, registration, faq, final, after_registration, ...(webinar ? { webinar } : {}), presentation, video, confirmation_video, whatsapp_group, confirmation_whatsapp_group } };
}

export function editorialJSON(content: LandingContent) {
    return JSON.stringify(editorialDocument(content), null, 2);
}

export function aiBrief(identity: { title: string; slug: string; campaign: string }, content: LandingContent, registrationOpen: boolean) {
    return `BRIEF EDITORIAL · JAKAWI.CLUB
Identidad: ${JSON.stringify({ title: identity.title, slug: identity.slug })}
Objetivo de captación: presentar JAKAWI y facilitar el registro gratuito cuando esté habilitado.
Público objetivo: no documentado como segmento en esta landing; no inventarlo. Contexto geográfico y editorial: usar exclusivamente los textos adjuntos.
Campaña: ${identity.campaign || 'Sin asignar'}
Plantilla: ${content.presentation.template}
Estado real del registro: ${registrationOpen ? 'ABIERTO' : 'CERRADO. No prometer acceso inmediato ni presentar el formulario como habilitado.'}
Condiciones conocidas: registro y membresía gratuitos en esta etapa; sin pagos, compras ni comisiones disponibles. La invitación personal requiere activar la cuenta. Fechas de encuentros solo si están confirmadas por el propietario.
Beneficios y contenido de referencia: incluidos en el documento editorial adjunto; pueden contener propuestas aún sin guardar. No convertirlos en nuevas promesas comerciales.
Instrucciones: escribir en español para jakawi.club. No inventar ofertas, descuentos, testimonios, cifras, fechas, bonos, urgencia ni garantías. No incluir datos personales, credenciales ni información de otros proyectos. No cambiar las condiciones conocidas ni el estado del registro.
Salida: devolver únicamente un objeto JSON válido, sin Markdown, de hasta 65536 bytes UTF-8. Versión exacta: ${editorialSchema}. Raíz: schema, template y content. template solo LEAD_VSL, WEBINAR o WAITLIST; debe coincidir con content.presentation.template si aparecen ambos. No añadir propiedades.
Documento completo para una landing nueva: incluir seo, hero, benefits, registration, faq, final, after_registration, presentation, video, confirmation_video, whatsapp_group y confirmation_whatsapp_group, con todos sus campos. Para WEBINAR incluir además content.webinar completo y content.presentation.webinar: title, starts_at y sections. Esto habilita explícitamente la composición sin pulsar botones del editor.
presentation incluye template y los booleanos estrictos show_video, show_faq y show_confirmation_video. presentation.webinar.starts_at debe ser null mientras no haya fecha confirmada; de existir, usar YYYY-MM-DDTHH:mm:ss-04:00 (America/La_Paz). sections contiene exactamente una vez cada id: hero, event, benefits, opportunity, early, steps, registration, faq, final. Su orden es el orden visual; cada elemento lleva visible true o false y registration debe permanecer visible.
Textos planos sin < ni >, de 1 a 1200 caracteres. benefits.items y faq.items: 1 a 8 elementos completos; WEBINAR muestra los primeros tres beneficios. benefits.items[].icon es opcional: play, users, gift, compass, calendar, star, heart, arrow-right, lightbulb o megaphone. Omitirlo conserva el icono automático; no usar null, cadenas vacías, nombres arbitrarios, SVG ni código. Solo WEBINAR muestra estos iconos; LEAD_VSL y WAITLIST conservan indicadores numéricos. webinar.early.items y webinar.steps.items: exactamente tres elementos con title y description. registration admite form_title, form_subtitle, name_placeholder, phone_placeholder, email_placeholder, submit_label, submit_microcopy, existing_account_label y existing_account_link_label; solo texto plano sin HTML ni URLs. Subtítulo independiente de description; subtítulo y microcopy admiten cadena vacía. submit_label admite null para CTA automático (hero.cta en WEBINAR; histórico en LEAD_VSL/WAITLIST); explícito se comparte con todos los CTA hacia registro y sticky. Mantén submit_label y hero.cta coherentes; final.cta y after_registration.cta_label se conservan por compatibilidad, sin botón independiente. after_registration permite title, message, instructions y cta_label; nunca afirmar cuenta activada, correo verificado o correo enviado: el servidor confirma esos estados.
Ambos videos incluyen youtube_url y poster (null o texto hasta 500), aspect_ratio fijo "3:4", source_type "youtube", source_aspect_ratio "9:16", "3:4" o "16:9", focal_x y focal_y numéricos entre 0 y 1. Solo URLs HTTPS de YouTube aprobadas y posters locales existentes en /marketing/ (PNG, JPEG o WebP). No inventar medios. Sin videos aprobados: URLs y posters null, show_video y show_confirmation_video false. Una URL de confirmación null permite el fallback del sistema si show_confirmation_video es true; false lo oculta.
Grupos: whatsapp_group.mode admite default/custom/none; confirmation_whatsapp_group.mode admite inherit/default/custom/none. url es null o una invitación aprobada HTTPS chat.whatsapp.com con código alfanumérico de 10 a 64 caracteres, sin parámetros ni fragmentos. custom exige URL válida; inherit usa el grupo de la landing y default usa el fallback del sistema. Sin grupo aprobado usar mode "none" y url null en ambos objetos. WhatsApp aparece únicamente después del registro; no cambiar el registro ni la navegación al terminar el video.
En una landing existente se permiten parches: los objetos se fusionan, los campos omitidos se conservan y las listas reemplazan la lista completa. null solo es válido en starts_at, youtube_url, poster y url de grupos; no elimina objetos. Para una landing nueva entregar siempre el documento completo, con medios, fecha, grupos y visibilidad explícitos; no reutilizar configuración de otra campaña. Importar solo aplica al formulario; guardar y publicar son acciones independientes.
No incluir next_step, contadores configurables, secretos, código, HTML, endpoints, destinos arbitrarios, slug, campaña, estado público ni permisos. Referencias oficiales: JAKAWI-LANDING-V3-SCHEMA.md y JAKAWI-WEBINAR-V3-EXAMPLE.json en docs/landings/.

JSON EDITORIAL ACTUAL / CONTRATO COMPLETO:
${editorialJSON(content)}`;
}
