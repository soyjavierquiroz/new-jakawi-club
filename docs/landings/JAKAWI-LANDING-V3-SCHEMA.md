# Contrato oficial JAKAWI Landing JSON v3

Referencia para el ChatGPT Copywriter de **JAKAWI.CLUB**. El contrato implementado
vive en `app/app/Support/LandingContent.php`; el exportador y el brief en
`app/resources/js/lib/landing-editorial.ts`. Ejemplo integral:
[JAKAWI-WEBINAR-V3-EXAMPLE.json](JAKAWI-WEBINAR-V3-EXAMPLE.json).

## Alcance y uso

Un solo JSON configura el contenido editable y la presentación de captación y
confirmación. Se importa desde `/admin/landings` pegándolo o seleccionando archivo.
**Importar solo cambia el formulario**; guardar borrador y publicar son acciones
independientes. Las previews muestran el borrador guardado, no los cambios sin guardar.
No crea identidades ni cambia slug, campaña, estado público, registro, email,
referidos, recibos, servicios externos, secretos ni permisos.

Para una landing nueva v3 se exige un documento completo; para una existente se
admiten parches. El editor selecciona automáticamente el endpoint apropiado.
El nombre interno y el slug se completan en Identificación antes de guardar.
Un WEBINAR completo se activa con `content.presentation.template: "WEBINAR"`,
`content.presentation.webinar` y `content.webinar`: no requiere aplicar un seed ni
pulsar «Habilitar composición WEBINAR».

## Formato y reglas generales

- JSON UTF-8: máximo **65536 bytes (64 KiB)**, profundidad del decodificador 24.
  Devolver exclusivamente JSON, sin Markdown, comentarios ni comas finales.
- Raíz: objeto con `schema`, `template` opcional y `content` no vacío.
- `schema` obligatorio: **`jakawi-club-landing-v3`**.
- `template`: `LEAD_VSL`, `WEBINAR` o `WAITLIST`. Es un alias de
  `content.presentation.template`; si aparecen ambos deben coincidir.
- En parches se puede omitir template en ambos sitios para conservar el existente.
  En documentos nuevos debe declararse en al menos uno de los dos sitios.
- Solo se admiten las propiedades documentadas. Se rechazan claves desconocidas
  en cualquier nivel, código/HTML en campos de presentación y destinos arbitrarios.
- **Texto** en las tablas significa string no vacío ni solo espacios, de hasta
  **1200 caracteres**, sin `<` ni `>`. No se interpreta Markdown ni HTML.
- Booleanos: `true` o `false`, sin comillas. Números: valores JSON, sin comillas.
- Listas: arrays consecutivos. Cada elemento enviado debe estar completo.
- No repetir claves JSON: PHP conservaría la última aparición.

## Contenido editorial

Todas las rutas de esta tabla son relativas a `content`. En un documento nuevo
deben incluirse estos objetos y todos sus campos, salvo `webinar`, que es
obligatorio solo al crear un WEBINAR completo.

| Objeto | Propiedades | Tipos y límites |
| --- | --- | --- |
| `seo` | `title`, `description` | Texto; título del documento y metadescripción |
| `hero` | `eyebrow`, `title`, `emphasis`, `ending`, `description`, `cta` | Texto; titular = title + emphasis + ending |
| `benefits` | `eyebrow`, `title`, `description`, `items` | Textos y lista de 1–8 elementos |
| `benefits.items[]` | `title`, `description`, `icon` (opcional) | Ambos textos obligatorios; icono de la lista cerrada |
| `registration` | `eyebrow`, `title`, `description` | Texto; introducción exterior. Admite nueve campos opcionales de copy del formulario, descritos al final |
| `faq` | `eyebrow`, `title`, `items` | Textos y lista de 1–8 elementos |
| `faq.items[]` | `question`, `answer` | Ambos textos obligatorios |
| `final` | `eyebrow`, `title`, `description`, `cta` | Texto; cierre |
| `after_registration` | `title`, `message`, `instructions`, `cta_label` | Texto; confirmación, con restricciones adicionales |
| `webinar` | `opportunity`, `early`, `steps` | Objetos descritos debajo |
| `webinar.opportunity` | `title`, `description` | Texto; problema/oportunidad |
| `webinar.early` | `title`, `description`, `items` | Textos y lista de exactamente 3 elementos |
| `webinar.early.items[]` | `title`, `description` | Ambos textos obligatorios |
| `webinar.steps` | `title`, `items` | Texto y lista de exactamente 3 elementos |
| `webinar.steps.items[]` | `title`, `description` | Ambos textos obligatorios |

`benefits.items[].icon` es un string opcional: `play`, `users`, `gift`,
`compass`, `calendar`, `star`, `heart`, `arrow-right`, `lightbulb` o `megaphone`.
Son iconos Lucide locales existentes; se rechazan valores vacíos, `null`, nombres
arbitrarios, SVG, objetos, componentes y código. En WEBINAR se muestran los
primeros tres beneficios con círculo amarillo JAKAWI `#FFC83D` e icono `#111111`.
Omitir `icon` conserva el fallback por posición (reproducir, flecha, reiniciar);
«Automático (icono actual)» en el editor elimina la propiedad. LEAD_VSL y WAITLIST
conservan sus indicadores numéricos. No se cambia el esquema ni se requiere una
migración. Los JSON antiguos sin `icon` siguen siendo válidos. Un parche que omite
`benefits` conserva la lista actual; al enviar `items`, reemplaza la lista completa,
incluidos sus iconos. Importación, exportación y previews conservan el valor.

En WEBINAR completo se muestran únicamente los primeros **tres beneficios**.
`registration.submit_label` controla los CTA hacia registro; en WEBINAR,
sin valor explícito, utiliza `hero.cta`.
`final.cta` y `after_registration.cta_label` se conservan por compatibilidad, pero
no controlan botones independientes. En LEAD_VSL/WAITLIST, sin CTA explícito, se conserva la etiqueta histórica. El título visible del evento es distinto de `seo.title`.

Los cuatro textos de `after_registration` rechazan la expresión regular
`/(?:cuenta\s+(?:est[aá]\s+)?activada|correo\s+(?:est[aá]\s+)?verificado|correo\s+enviado)/iu`.
No afirmar activación, verificación ni entrega de correo. El servidor muestra el
estado real. Las etiquetas accesibles fijas de los campos, privacidad,
identidad visual y mensajes del sistema no forman parte del contrato.
El antiguo título predeterminado `Gracias por dar el siguiente paso.` se normaliza
a `¡Felicidades por dar el siguiente paso!`; la instrucción heredada predeterminada
se conserva pero no se muestra como párrafo adicional en la confirmación.

## Presentación, evento, orden y visibilidad

| Propiedad de `content.presentation` | Tipo y regla |
| --- | --- |
| `template` | Enum de las tres plantillas; puede declararse mediante el alias raíz |
| `show_video` | Booleano; muestra/oculta el video principal sin borrar sus datos |
| `show_faq` | Booleano; controla FAQ en WAITLIST. WEBINAR utiliza sections |
| `show_confirmation_video` | Booleano; muestra/oculta el video posterior al registro |
| `webinar` | Objeto opcional en otras plantillas; obligatorio para crear WEBINAR completo |
| `webinar.title` | Texto; nombre del evento |
| `webinar.starts_at` | String de fecha válida `YYYY-MM-DDTHH:mm:ss-04:00`, o `null` |
| `webinar.sections` | Lista de exactamente 9 objetos completos `{ "id": ..., "visible": ... }` |
| `webinar.sections[].id` | Uno de los nueve IDs siguientes, sin repeticiones |
| `webinar.sections[].visible` | Booleano; para `registration` debe ser `true` |

Los nueve IDs son `hero`, `event`, `benefits`, `opportunity`, `early`, `steps`,
`registration`, `faq`, `final`. Cada uno debe aparecer una sola vez. **El orden del
array determina el orden visual**. Ocultar una sección conserva su contenido;
ocultar hero también oculta el video que está dentro de él.

La fecha usa `America/La_Paz` (Bolivia, `-04:00`) y requiere segundos y offset.
Sin fecha confirmada usar `null`: título visible «Próximo webinar en vivo», aviso
de fecha pendiente y ningún countdown. El nombre configurado se muestra al tener
fecha válida. No se hereda la fecha global en la composición completa. La fecha
pasada es válida: muestra el aviso de fecha alcanzada y no reinicia el contador.
No existe un campo separado para duración o reinicio del countdown.

## Videos independientes

`content.video` y `content.confirmation_video` tienen la misma estructura.
Ambos son obligatorios, con todos sus campos, en un documento nuevo.

| Campo | Tipo, valores y límites |
| --- | --- |
| `youtube_url` | String hasta 500 caracteres, URL HTTPS válida de YouTube; o `null` |
| `poster` | String hasta 500 caracteres, ruta local existente; o `null` |
| `aspect_ratio` | Exclusivamente `"3:4"`; marco fijo |
| `source_type` | Exclusivamente `"youtube"` |
| `source_aspect_ratio` | `"9:16"`, `"3:4"` o `"16:9"` |
| `focal_x`, `focal_y` | Números JSON entre 0 y 1 inclusive; 0.5 centra el encuadre |

Se utiliza el mismo `JakawiVslPlayer`. No se importan autoplay, CSS, iframe,
código incrustado ni otro proveedor. La URL se normaliza a
`https://www.youtube.com/watch?v=ID` (ID de 11 caracteres). Se admiten `youtu.be`
y los hosts YouTube ya aceptados por `LaunchSettings::youtubeUrl`: `youtube.com`,
`www.youtube.com`, `m.youtube.com`, `music.youtube.com`, `youtube-nocookie.com`,
`www.youtube-nocookie.com`; formatos watch, embed, shorts, live y v según el host.
No se admiten credenciales ni puertos. No se comprueba reproducción real al importar.

Poster: `/marketing/` seguido de ruta con letras ASCII, números, `/`, `_`, `-` y
extensión minúscula `.png`, `.jpg`, `.jpeg` o `.webp`. El archivo debe existir;
no se suben archivos ni se descargan imágenes durante la importación.

Video principal con URL `null`: sin reproductor, sin fallback de otra campaña.
Confirmación con URL `null` y `show_confirmation_video: true`: puede usar el
video predeterminado del sistema. Para garantizar ausencia de video usar
`show_video: false` y `show_confirmation_video: false`, con URLs y posters `null`.
Ocultar conserva los datos guardados y no cambia los fallbacks del entorno.

## Grupos de WhatsApp

Ambos objetos son obligatorios en un documento nuevo; cada uno lleva `mode` y
`url`. Solo aparecen en la confirmación protegida por recibo, nunca en captación.

| Objeto | Modos |
| --- | --- |
| `content.whatsapp_group` | `default`, `custom`, `none` |
| `content.confirmation_whatsapp_group` | `inherit`, `default`, `custom`, `none` |

- `default`: usa el fallback del sistema.
- `custom`: exige una invitación aprobada en `url`.
- `none`: oculta el grupo. Para un documento sin grupo usar `url: null`.
- `inherit`: solo en confirmación; usa el grupo efectivo de su landing.
- `url`: string hasta 500 caracteres o `null`. Una URL no nula debe cumplir
  exactamente `https://chat.whatsapp.com/[A-Za-z0-9]{10,64}`; sin credenciales,
  puertos, parámetros, fragmentos, subrutas ni hosts similares.
- No usar `default` o `inherit` para expresar «no configurado»: pueden activar
  fallbacks. El ejemplo usa **`none` y `null` en ambos grupos**.

El CTA de confirmación mantiene «UNIRME AL GRUPO DE WHATSAPP». Un grupo válido
permite el enlace manual; el evento real de finalización del video puede navegar
al grupo efectivo. Previews y administradores no redirigen. Sin grupo no hay
botón ni redirección. No se cambia esta lógica mediante JSON.

## Documentos completos, parches y compatibilidad

En una landing nueva, v3 requiere todos los objetos comunes, metadatos de videos,
modos/URLs de grupos y banderas de presentación explícitos. Los textos también
deben quedar completos. No se usa la configuración enviada por el formulario
como relleno: así no se arrastran videos, fechas, grupos o ajustes de otra campaña.
Solo se agrega el `next_step` interno predeterminado, fuera del contrato editorial.

En una landing existente, se valida el parche y después el resultado completo:

- Objetos no vacíos: fusión recursiva; propiedades omitidas conservan lo existente,
  incluidos cambios del formulario todavía sin guardar.
- Listas: reemplazo completo, en el orden enviado. No se fusionan por índice ni ID.
- `null` solo se admite en `starts_at`, `youtube_url`, `poster` y URLs de grupos.
  No elimina secciones u objetos. Para quitar la fecha usar `starts_at: null`.
- `""`, solo espacios, listas vacías o elementos incompletos no son válidos.
- `{}` no es una operación de conservación o eliminación: al decodificarse como
  array vacío puede reemplazar la sección y hacer fallar la validación final.
- Un parche de `presentation.webinar` puede cambiar solo la fecha o título si el
  resto de la composición existe. Para introducir una composición nueva deben
  quedar completos el evento, sus nueve secciones y el contenido `webinar`.
- Cambiar de plantilla conserva la composición almacenada y los medios existentes.
- v1/v2 siguen admitiendo únicamente los textos de `seo`, `hero`, `benefits`,
  `registration`, `faq`, `final`, `after_registration`, `webinar` y el alias raíz
  `template`. Rechazan presentation, medios y grupos, y conservan sus reglas de
  parches. Su importación no activa por sí sola la composición WEBINAR completa.
- La exportación pasa a v3 tanto en el editor como en el endpoint administrativo.
  Incluye toda la configuración editorial almacenada (sin resolver fallbacks),
  para volver a importarla sin perder medios, grupos, fecha, orden o visibilidad.
  No incluye `next_step`, identidad administrativa ni estado de publicación.
- No se reescriben campañas históricas ni snapshots al leer o exportar.

Ejemplo de parche para una landing existente:

```json
{
  "schema": "jakawi-club-landing-v3",
  "content": {
    "hero": { "title": "Un nuevo comienzo" },
    "presentation": { "webinar": { "starts_at": null } }
  }
}
```

Errores: respuesta HTTP 422 con `errors`, indexada por ruta (`content.video.youtube_url`,
`content.presentation.webinar.sections.6.visible`, etc.). Explica tipo, límite,
valor inválido o propiedad desconocida. Si falla la importación, el formulario,
el borrador y el snapshot publicado se conservan. Los errores sintácticos o de
tamaño aparecen bajo `editorial_json`.

## Reglas para el copywriter

Entregar el ejemplo completo adaptando solo datos aprobados. No inventar fechas,
URLs, medios, ofertas, testimonios, cifras, urgencia, garantías o condiciones.
Sin fecha: `null`. Sin videos: URLs/posters `null` y ambas banderas `false`.
Sin grupo: ambos modos `none` y URLs `null`. No prometer acceso si el registro está
cerrado. No añadir secretos, endpoints, callbacks, HTML, scripts, estilos,
permisos, pagos, formularios ni integraciones.

El registro, la atribución, activación/email y los recibos siguen siendo del backend.
Un POST aceptado fija los textos y medios/grupo efectivos en el recibo temporal
de 15 minutos. Cambiar o republicar la landing no modifica un recibo vigente.
El acceso a `/{slug}/gracias` exige el recibo de esa landing. Importar contenido
no registra personas ni envía mensajes.

## Copy del formulario existente (`content.registration`)

Además de `eyebrow`, `title` y `description` de la introducción exterior, admite
estos nueve campos opcionales. No crea otro formulario ni cambia su acción.

| Campo | Predeterminado para documentos históricos |
| --- | --- |
| `form_title` | Tu lugar en JAKAWI |
| `form_subtitle` | Cadena vacía; sin subtítulo |
| `name_placeholder` | Tu nombre completo |
| `phone_placeholder` | WhatsApp |
| `email_placeholder` | Tu correo electrónico |
| `submit_label` | `null`: automático |
| `submit_microcopy` | Cadena vacía; sin microcopy |
| `existing_account_label` | ¿Ya tienes cuenta? |
| `existing_account_link_label` | Ingresa aquí |

Son textos planos de hasta 1200 caracteres, sin HTML ni URLs (incluidos dominios,
protocolos y enlaces `www`). Si se proporcionan, no pueden estar vacíos salvo
`form_subtitle`, `submit_microcopy` y `submit_label` (vacío o solo espacios se normaliza a `null` y usa el CTA automático). `form_subtitle` y `submit_microcopy` también admiten `null`, normalizado a cadena
vacía. `submit_label` admite `null` para volver
al CTA automático: `hero.cta` en WEBINAR, `QUIERO SER PARTE DE JAKAWI` en LEAD_VSL y
WAITLIST. El CTA explícito tiene prioridad en el formulario y en todos los botones
hacia registro, incluido el sticky móvil. Esta regla sustituye la precedencia
anterior exclusiva de `hero.cta` en WEBINAR. Usa el mismo texto en ambos campos
cuando sean explícitos. `form_subtitle` es independiente de `description`.

Omitir campos en un parche conserva el valor existente; en documentos históricos
o nuevos sin estos campos se aplican los defaults en memoria. v1/v2 conservan
compatibilidad. Exportar incluye estos campos; importar no guarda ni publica.
La preview y el público usan el mismo componente. El enlace de acceso conserva
`/login`; privacidad conserva su consentimiento y `/privacidad`. No se admiten
nombres técnicos, destinos, endpoints, validaciones ni acciones adicionales.
