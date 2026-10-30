# Importador completo JAKAWI Landing JSON v3 — preparado sin desplegar

Implementación en `feat/landing-json-v3`, checkout
`/opt/sites/jakawi.club/.worktrees/landing-json-v3`.
Base sincronizada con `origin/main`: `45b26e7a1fc93f2ed34437195971e8d4783032bc`.
El SHA de entrega se obtiene con `git rev-parse HEAD` en ese checkout y se informa
en la entrega; no se introduce autorreferencia dentro del commit.

**Sin despliegue, publicación CMS, cambios de landings LIVE ni push/merge a main.**
No se ejecutaron instalaciones, migraciones LIVE ni reinicios. No se modificaron
secretos, JAKAWI.COM, CRM, Traefik ni servicios vecinos.

## Resultado

El importador existente acepta `jakawi-club-landing-v3`, con presentación WEBINAR
explícita, título/fecha del evento, nueve secciones ordenadas y visibles/ocultas,
ambos videos independientes y grupos de WhatsApp existentes. La exportación y el
brief utilizan la misma allowlist v3. No hay nuevos modelos, tablas, rutas,
dependencias, formularios ni otro motor de configuración.

Las creaciones v3 exigen contenido completo y configuración explícita; no rellenan
el documento con datos de otra campaña. El editor utiliza el endpoint ya existente
con ID para parches y el endpoint sin ID para creación. v1/v2 mantienen su alcance
de textos y template raíz. En existentes, los objetos se fusionan y las listas se
reemplazan completas. Las claves omitidas conservan la base, incluidos cambios sin
guardar. Propiedades desconocidas y valores inválidos generan 422 con ruta y motivo
en español. El límite de 64 KiB se comprueba también en bytes UTF-8 en el servidor.

Importar solo modifica el formulario; guardar y publicar continúan separados.
Registro, atribución, recibos, activación/email, fallbacks, reproductor oficial
`JakawiVslPlayer`, marco 3:4 y navegación posterior al terminar video no cambian.

## Referencias oficiales para el copywriter

- [JAKAWI-LANDING-V3-SCHEMA.md](../../docs/landings/JAKAWI-LANDING-V3-SCHEMA.md): propiedades, tipos, límites, enums, parches, fallbacks y correspondencia visual.
- [JAKAWI-WEBINAR-V3-EXAMPLE.json](../../docs/landings/JAKAWI-WEBINAR-V3-EXAMPLE.json): 6661 bytes UTF-8, sin fechas ni enlaces inventados; nueve secciones; videos explícitos y ocultos; ambos grupos `none`/`null`.

## Archivos de aplicación modificados

- `app/app/Support/LandingContent.php`: contrato v3, exportación, guard de creación, validación parcial de presentación y mensajes por propiedad; validadores administrativos compartidos.
- `app/app/Http/Controllers/LandingAdminController.php`: contexto de creación/existente y mensajes de entrada JSON.
- `app/resources/js/lib/landing-editorial.ts`: exportador v3 y brief completo para IA.
- `app/resources/js/pages/admin/LandingEditor.tsx`: selección del endpoint existente y ayuda actualizada.

Además: los dos archivos oficiales anteriores, `README.md`, `.gitignore`, y las
pruebas `scripts/landing-json-v3-tests.php` (nueva), `scripts/landing-tests.php`,
`scripts/webinar-tests.php`, `scripts/campaigns-admin-tests.php`,
`scripts/postconversion-tests.php`, `scripts/check-webinar-visual.mjs` y el montaje
de referencias de `scripts/test-webinar.sh`. Este directorio contiene el informe y
la evidencia resumida. Los fixtures y capturas generados quedan fuera de Git.

## Validación

Ejecutada en secuencia, con los límites de los scripts existentes y sin instalar
dependencias. PHP usa esquemas PostgreSQL temporales, eliminados al terminar;
notificaciones y solicitudes externas se simulan.

| Comprobación | Resultado |
| --- | --- |
| Landings, compatibilidad y nuevos casos v3 | **379 PASS** |
| Registro/confirmación, recibos y atribución | **134 PASS** |
| Catálogo comercial | **75 PASS** |
| Receptor comercial | **62 PASS** |
| Total PHP | **650 PASS** |
| TypeScript estricto | **PASS** |
| Vite, únicamente `public/build-next` del worktree | **PASS** |
| Chromium, build preparado y fixtures administrativos | **15 grupos PASS** |
| `python3 scripts/validate-local.py` | **PASS**, comprobación de base LIVE existente |
| `git diff --check` | **PASS** |

Los casos v3 verifican importación HTTP del ejemplo sin seed, creación independiente,
roundtrip equivalente, base contaminada con configuración ajena, parches que conservan
ediciones sin guardar, reemplazo de listas, fecha Bolivia y null, orden/visibilidad,
videos y encuadres independientes, grupo solo en confirmación, ambas previews,
aislamiento de publicación y errores de tipos, propiedades, URLs, tamaño y coherencia.
El navegador comprueba también countdown futuro/pasado, composición responsive,
importación real de UI con respuestas producidas por PHP, exportación equivalente,
brief y errores sin guardar ni publicar. Todas sus mutaciones se interceptan.

Evidencia: [tests.json](tests.json), [browser-results.json](browser-results.json),
[validación local](validation.json), [baseline anterior](unchanged-before.json) y
[estado posterior](unchanged-after.json). El digest de todas las filas de
`public.marketing_landings` —incluidos borradores y snapshots—, HEAD de main,
manifest LIVE e ID/inicio/reinicios de los tres contenedores CLUB permanecen iguales.
El checkout servido sigue limpio en el SHA base. Ninguna fuente comercial cambió.

## Límites y riesgos de uso

- LIVE sigue usando v2 hasta un despliegue posterior explícito. El JSON v3 es
  importable por la implementación preparada, no por el importador LIVE anterior.
- Una creación v3 incompleta se rechaza; las correcciones parciales se usan en
  landings existentes. Los documentos v1/v2 siguen admitiendo su flujo anterior.
- `confirmation_video.youtube_url: null` permite fallback si su visibilidad es true;
  `default`/`inherit` de grupos también permiten fallbacks. El ejemplo los desactiva
  explícitamente. No se alteran secretos ni fallbacks del sistema.
- `next_step`, identidad, campaña y publicación permanecen fuera del JSON. Los CTA
  heredados sin efecto independiente y los textos fijos se documentan; no se añade
  control editorial sobre registro o identidad visual.
- No se acredita reproducción real de YouTube ni entrega SMTP: se conserva su
  implementación, y las pruebas usan proveedores/notificaciones simulados.
