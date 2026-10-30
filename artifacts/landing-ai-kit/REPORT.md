# Kit de copywriting con IA — preparado sin desplegar

Rama: `feat/landing-ai-kit`, checkout `/opt/sites/jakawi.club/.worktrees/landing-ai-kit`.
Base: `9663f542ff6a5115c931520ab7d51786a350188b`.
El SHA de entrega se consulta con `git rev-parse HEAD` en ese checkout y se informa
al entregar, para evitar autorreferencia dentro del commit.

## Implementación

Un componente compartido muestra «Crear con IA» como bloque plegable discreto en
el listado y junto a las herramientas de importación/exportación del editor.
Ofrece tres descargas individuales y un ZIP; no contiene especificaciones JSON
duplicadas ni datos de la campaña. Usa enlaces de descarga normales para que los
attachments no se procesen como navegación Inertia.

`LandingAiKitController` usa una allowlist fija de tres archivos versionados,
fuera de `public/`. Las rutas GET quedan dentro del grupo existente con `auth`,
`verified` y `MarketingAdmin`. Las respuestas son attachments privados sin caché;
Markdown y JSON mantienen sus bytes originales y declaran UTF-8. El ZIP se crea
con la extensión ya existente `ZipArchive`, solo contiene esos tres documentos
y elimina su temporal al terminar el streaming. Sin extensión ZIP se desactiva
su botón, permanecen las descargas individuales y el endpoint ZIP devuelve 503
con una explicación. No se agregan dependencias, generadores ni APIs externas.

Se crea `docs/landings/JAKAWI-COPYWRITER-PROMPT.md`; el contrato y ejemplo v3
existentes permanecen byte a byte iguales. El prompt remite a ambos documentos,
orienta la redacción al registro, exige captación/confirmación completas y evita
inventar hechos, medios o enlaces. Explica CTA único, WhatsApp posterior, modos
`none`, nulos permitidos y la identificación administrativa fuera del JSON.

## Archivos modificados

- `app/app/Http/Controllers/LandingAiKitController.php` (nuevo).
- `app/app/Http/Controllers/LandingAdminController.php` (disponibilidad ZIP).
- `app/resources/js/components/admin/LandingAiKit.tsx` (nuevo).
- `app/resources/js/pages/admin/Landings.tsx` y `LandingEditor.tsx` (accesos).
- `app/routes/web.php` (descarga administrativa protegida).
- `docs/landings/JAKAWI-COPYWRITER-PROMPT.md` (nuevo).
- `compose.yaml` (montaje de referencias de solo lectura, preparado sin aplicar).
- `scripts/landing-ai-kit-tests.php` (nuevo), `landing-tests.php`,
  `test-webinar.sh` y `check-webinar-visual.mjs` (pruebas existentes extendidas).
- `.gitignore`, `README.md` y evidencia en `artifacts/landing-ai-kit/`.

## Validación

Landings: **419 PASS** en esquema PostgreSQL temporal eliminado al terminar.
Incluye guests y miembros sin permiso, administradores no verificados/inactivos,
allowlist, integridad y UTF-8 de los tres documentos, ZIP con exactamente tres
entradas equivalentes, limpieza del temporal, fallback sin ZIP, props de listado/
creación/edición e importación del ejemplo descargado. Los casos v1/v2/v3 y
WEBINAR, borradores y snapshots existentes siguen aprobados.

`docker compose config --quiet`, contrato/ejemplo/motor JSON sin cambios y
`git diff --check`: PASS. La extensión ZIP está disponible en la imagen PHP
existente; el caso sin ZIP también se prueba mediante el mismo controlador.
`python3 scripts/validate-local.py`: **PASS** sobre la base LIVE existente,
con evidencia separada en [validation.json](validation.json).
TypeScript y Vite: **PASS**, sin instalar dependencias. Chromium con el build
preparado: **18 grupos PASS** (los 15 existentes y 3 nuevos para el kit). Verifica
listado, creación y edición a 390/1440 px, cuatro acciones, ubicación junto a
importar/exportar, ausencia de desbordes y fallback sin ZIP. Todas las mutaciones
del navegador se interceptan; la reproducción del proveedor externo no se evalúa.
Resultados en [tests.json](tests.json) y [browser-results.json](browser-results.json).

El baseline anterior/posterior verifica que HEAD y estado Git de la copia servida,
contenido completo de landings/snapshots, manifest y contenedores CLUB no cambian.
Los archivos oficiales existentes y `LandingContent.php` siguen iguales al SHA base.

## Operación y límites

**Sin despliegue, push a main, cambios de contenido LIVE, publicaciones,
migraciones, instalaciones ni reinicios.** El build se prepara solo en
`public/build-next` del worktree. No se modifican registro, recibos, proveedores,
videos, WhatsApp, comisiones, CRM, JAKAWI.COM, Traefik ni servicios vecinos.

Para un despliegue posterior se necesita aplicar el nuevo montaje
`./docs/landings:/var/www/docs/landings:ro` recreando únicamente `app`, además de
publicar assets y regenerar la caché de rutas siguiendo el procedimiento habitual.
No se aplica ese cambio en esta entrega. Nginx no recibe el montaje: los documentos
siguen accesibles exclusivamente mediante las rutas administrativas protegidas.
Si faltan los archivos/montaje el endpoint devuelve 503, sin sustituirlos por
contenido duplicado. Se necesita espacio temporal para el ZIP pequeño.
