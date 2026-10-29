import type { LandingContent } from '../types';

export const editorialSchema = 'jakawi-club-landing-v1';
export function sameConfiguration(left: unknown, right: unknown): boolean {
    function ordered(value: unknown): unknown {
        if (Array.isArray(value)) return value.map(ordered);
        if (value !== null && typeof value === 'object') return Object.fromEntries(Object.entries(value).sort(([a], [b]) => a.localeCompare(b)).map(([key, item]) => [key, ordered(item)]));
        return value;
    }
    return JSON.stringify(ordered(left)) === JSON.stringify(ordered(right));
}
export function editorialDocument(content: LandingContent) {
    // Explicit allowlist: media, display flags and all operational data stay out.
    const { seo, hero, benefits, registration, faq, final } = content;
    return { schema: editorialSchema, template: content.presentation.template, content: { seo, hero, benefits, registration, faq, final } };
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
Salida: devolver únicamente un objeto JSON válido, sin Markdown. Versión exacta: ${editorialSchema}. El documento adjunto muestra la estructura completa esperada. Se permiten parches parciales de content; conservar las claves omitidas. Las listas reemplazan la lista completa (1 a 8 elementos completos). Textos planos sin HTML, de 1 a 1200 caracteres. template solo LEAD_VSL, WEBINAR o WAITLIST. No añadir propiedades. No incluir video, poster, presentación, slug, campaña, estado público, endpoints ni permisos en el JSON editorial.

JSON EDITORIAL ACTUAL / CONTRATO COMPLETO:
${editorialJSON(content)}`;
}
