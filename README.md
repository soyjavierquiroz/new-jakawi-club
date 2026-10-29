# JAKAWI Launch Engine — jakawi.club

Aplicación Laravel independiente publicada en **https://jakawi.club** mediante Traefik compartido y Let's Encrypt. `https://www.jakawi.club` redirige permanentemente al dominio principal.

- Código y Git: `/opt/sites/jakawi.club/`; aplicación Laravel en `app/`.
- Persistencia exclusiva: `/srv/sites/jakawi.club/`.
- Stack: Laravel 13, PHP 8.4, Inertia 3, React 19, TypeScript, Tailwind 4 y PostgreSQL 16.
- Compose y recursos: proyecto `jakawi-club`; contenedores `jakawi-club-app`, `jakawi-club-web`, `jakawi-club-db`.
- HTTP de validación: `http://127.0.0.1:18082` en el servidor.
- PostgreSQL solo participa en la red interna `jakawi-club-private` y no publica puertos.
- `jakawi-club-egress` es una red propia para herramientas de instalación, salida de la aplicación y publicación de Nginx en loopback.
- Solo `jakawi-club-web` se conecta también a la red externa compartida `web`. PHP-FPM y PostgreSQL conservan sus redes propias.
- Todos los servicios mantienen `traefik.enable=false`: la publicación utiliza exclusivamente el file provider existente.
- Plantilla de routers propia: `docker/traefik/jakawi-club.yaml`; archivo activo: `/opt/infra/traefik/dynamic/jakawi-club.yaml`.

## Arranque y validación

La instalación existente está preparada. Para comprobarla sin migraciones ni cambios de infraestructura:

```bash
cd /opt/sites/jakawi.club
docker compose config --quiet
docker compose ps
python3 scripts/validate-public.py
```

La validación pública comprueba DNS, certificados TLS, portada, `/up`, redirecciones permanentes, Inertia, assets HTTPS, configuración de Laravel, redes, PostgreSQL sin puertos publicados y continuidad de `jakawi.com`. Compara el estado previo de los contenedores y los hashes de los archivos existentes de Traefik cuando el baseline local está disponible. La evidencia queda en `artifacts/https-publication/validation.json` sin secretos.

Para reconstruir esta instalación si resulta necesario, ejecutar como administrador desde `/opt/sites/jakawi.club`:

```bash
python3 scripts/prepare-host.py
bash scripts/build-php.sh
bash scripts/install-dependencies.sh
bash scripts/start-local.sh
python3 scripts/validate-local.py
```

`build-php.sh` crea y elimina un builder temporal exclusivo, con memoria y CPU limitadas y paralelismo 1. Las instalaciones, TypeScript y Vite se ejecutan secuencialmente en contenedores temporales limitados. Las imágenes base y las dependencias están fijadas mediante digests y archivos lock.

`scripts/start-local.sh` es el procedimiento inicial de preparación: no instala dependencias ni ejecuta builds, pero sí ejecuta `migrate --force` y prepara cachés. No es necesario ejecutarlo para esta publicación. Para arrancar contenedores existentes, utilizar `docker compose start`. `docker compose stop` detiene exclusivamente este proyecto; los datos sobreviven en `/srv/sites/jakawi.club/`.

`scripts/validate-local.py` conserva la comprobación de la base técnica y admite la conexión exclusiva de Nginx a `web`. Su evidencia queda en `artifacts/base-tecnica/validation.json`.

## Persistencia y configuración

| Ruta en `/srv/sites/jakawi.club/` | Uso |
| --- | --- |
| `postgres/` | Datos PostgreSQL 16 |
| `storage/` | Archivos, vistas compiladas y logs diarios de Laravel |
| `bootstrap-cache/` | Cachés de configuración, rutas y paquetes |
| `nginx-logs/` | Logs de Nginx |
| `secrets/app.env` | Configuración production y credenciales nuevas |
| `secrets/db-admin-password` | Clave administrativa exclusiva |
| `secrets/db-app-password` | Clave del rol limitado de Laravel |

Los secretos se generan una sola vez; el preparador rechaza estados incompletos y no sobrescribe claves existentes. No hay secretos en Git ni en las imágenes. Mantener los permisos establecidos por el preparador y respaldar este directorio de forma privada. El rol `jakawi_club` utiliza únicamente la base `jakawi_club`.

`APP_DEBUG=false`, correo `log`, sesiones/caché/colas `database`. Las cookies conservan `Secure` porque el destino configurado es HTTPS; la portada se puede validar con HTTP local sin autenticación. No hay worker de colas, scheduler ni servicios adicionales en esta fase.

Laravel confía en los encabezados `X-Forwarded-For`, `X-Forwarded-Proto` y `X-Forwarded-Port` únicamente desde la subred confirmada de la red Docker `web` (`172.18.0.0/16`). No confía globalmente en cualquier proxy ni necesita forzar el esquema HTTPS. `APP_URL=https://jakawi.club`, `APP_ENV=production` y `APP_DEBUG=false` permanecen sin cambios.

## Publicación y rollback

DNS en Cloudflare, modo DNS only: `A @ → 167.71.107.80` y `CNAME www → jakawi.club`, sin AAAA publicados. No se modificó DNS durante el despliegue.

El archivo dinámico define `jakawi-club-https` para el dominio principal, `jakawi-club-www-https` para la redirección de `www` y `jakawi-club-http` para HTTP → HTTPS. El servicio apunta a `http://jakawi-club-web:80`; todos los nombres de routers, middlewares y servicios son exclusivos. Se utiliza el resolver existente `letsencrypt` con HTTP-01 y un certificado con ambos nombres. Traefik vigila el directorio y carga el archivo automáticamente.

Solo se recreó `jakawi-club-web`. PHP-FPM recibió una recarga gradual de sus procesos para aplicar trusted proxies; su contenedor y PostgreSQL no se reiniciaron ni recrearon. No se ejecutaron migraciones ni builds en esta publicación. No se editó configuración global ni ningún router previo; no se manipuló el almacenamiento ACME.

Rollback preparado y no ejecutado: [procedimiento corto](artifacts/https-publication/ROLLBACK.md). Retira únicamente el archivo dinámico exclusivo y la conexión de Nginx a la red compartida; mantiene el acceso local, datos y credenciales. Nunca reiniciar Traefik ni borrar certificados para este rollback.

## Flujo de Git y despliegue

Repositorio oficial: `git@github.com:soyjavierquiroz/new-jakawi-club.git`. La rama principal es `main`; conservar su historial y publicar sin push forzado. Antes de trabajar, comprobar `git status` y sincronizar con `git pull --ff-only origin main`. Revisar el diff, seleccionar los archivos necesarios y crear commits pequeños:

```bash
git diff
git add -p
git commit -m "Describe el cambio"
git push origin main
```

Git versiona código, configuración sin secretos y documentación. El estado LIVE incluye además los assets generados, cachés, contenedores, base de datos, archivos persistentes y secretos de `/srv/sites/jakawi.club/`, que nunca deben copiarse a Git. Un push no ejecuta un despliegue ni acredita el estado LIVE. Como `app/` se monta desde esta copia del servidor, editar o actualizar sus archivos puede afectar a lo servido.

El despliegue es manual y requiere una petición explícita: revisar el commit objetivo, aplicar únicamente los pasos operativos existentes que correspondan al cambio y validar con `python3 scripts/validate-local.py`; usar `python3 scripts/validate-public.py` para comprobar HTTPS. Conectar GitHub no requiere ejecutar instalaciones, builds, migraciones ni reinicios.

## Operación

```bash
docker compose ps
docker stats --no-stream jakawi-club-app jakawi-club-web jakawi-club-db
docker compose exec --user www-data app php artisan migrate:status
curl --fail http://127.0.0.1:18082/
curl --fail https://jakawi.club/up
```

El código se monta en solo lectura. Después de cambios de código y de regenerar los assets/cachés que correspondan, reiniciar únicamente `app` para renovar OPcache: `docker compose restart app`.

Límites de RAM: PHP-FPM 256 MiB, Nginx 48 MiB y PostgreSQL 192 MiB; total 496 MiB. FPM utiliza como máximo dos workers bajo demanda. Los logs de Laravel rotan diariamente durante siete días; los logs Docker están limitados a 2 × 5 MiB por servicio. Pendiente operativo de la base técnica: definir backup externo de PostgreSQL/storage y rotación de los logs de Nginx.

El MVP de captación incluye landing, registro sin contraseña inicial, activación/verificación de correo, login/logout, recuperación, perfil, enlaces de referido permanentes, atribución del primer referente y dashboard de membresía gratuita. La bienvenida previa a verificar correo es genérica para no revelar cuentas existentes; tras activar muestra el enlace personal. No incluye compras, comisiones ni liquidaciones.

**El registro público permanece cerrado.** La apertura exige `JAKAWI_REGISTRATION_OPEN=true`, `JAKAWI_TRANSACTIONAL_MAIL_READY=true` y un SMTP real aprobado. Con `MAIL_MAILER=log` no se puede abrir ni se escriben notificaciones de acceso en logs. Video, evento y WhatsApp se configuran desde Laravel y permanecen vacíos hasta disponer de contenido aprobado.

Pruebas focalizadas sin dependencias PHP de desarrollo: `bash scripts/test-mvp.sh`. Usa PostgreSQL en un esquema temporal aislado, sesiones de prueba y notificaciones simuladas; no crea usuarios LIVE. TypeScript y build secuenciales: `bash scripts/build-frontend.sh`. Las dependencias no cambiaron y no necesitan reinstalarse.

Informes: [MVP](artifacts/mvp/REPORT.md), [operación y apertura](artifacts/mvp/OPERACION.md), [rollback del MVP](artifacts/mvp/ROLLBACK.md), [base técnica](artifacts/base-tecnica/REPORT.md) y [publicación HTTPS inicial](artifacts/https-publication/REPORT.md). El rollback del MVP conserva datos y la migración aditiva; no utiliza el rollback de publicación HTTPS.

Referencias de integración: [Laravel 13](https://laravel.com/docs/13.x/releases), [Inertia 3](https://inertiajs.com/docs/v3/installation/server-side-setup), [Vite](https://vite.dev/guide/).

## Identidad visual JAKAWI

Los tokens locales están en `app/resources/css/jakawi-theme.css`, importados desde `app.css` y expuestos a Tailwind 4 mediante `@theme inline`. La referencia oficial, consultada **solo en lectura**, es `/root/jakawi-build-v1/repository/app/resources/css/theme.css` (SHA `0bcda5842a0d1b9df93437784d7b3ad0fbb53fde`), junto con su `app.css` y `resources/js/layouts/marketing-layout.tsx`. Se aplica `data-marketing-theme="light-conversion"` a todas las páginas del micrositio. Los radios, espacios, superficies y sombras conservan los valores oficiales.

Marketing usa Montserrat (400–800) y los inputs Instrument Sans (400–600), exactamente como la referencia. Los WOFF2 oficiales se alojan en `app/resources/fonts/`, declarados en `jakawi-fonts.css`. Los originales `jakawi-logo.svg` y `jakawi-mark.svg` se reutilizan sin alterar geometría, proporciones ni colores; el icono WhatsApp proviene de `city-community-card.tsx`. Los hashes de procedencia están en `artifacts/identity/assets.json`.

Cuando cambie la marca, comparar manualmente el tema oficial y su contexto marketing, actualizar **solo los tokens locales necesarios**, y revisar las páginas a 390 y 1440 px antes de ejecutar `bash scripts/build-frontend.sh` y las validaciones locales. Actualizar el SHA documentado y la evidencia de procedencia. No hay sincronización automática ni paquete compartido. **Prohibido hardcodear colores en TSX**: utilizar tokens y utilidades semánticas. El hover coral se oscurece mediante `color-mix` y los CTA coral utilizan texto grande en negrita para preservar el contraste del blanco oficial. El foco combina el borde coral oficial de los campos con un contorno tinta visible sobre crema.

La evidencia de esta homologación y las capturas están en `artifacts/identity/` ([informe](artifacts/identity/REPORT.md)). `bash scripts/check-identity-visual.sh` reutiliza el Chromium local en un contenedor limitado, sin instalaciones ni cuentas LIVE; las páginas privadas se comprueban mediante fixtures solo del navegador. El sello local `LIVE.json` se genera después del commit para evitar autorreferencia. El build genera primero `public/build-next`; el despliegue conserva los chunks antiguos y publica el nuevo manifest al final, seguido únicamente del reinicio de `app` para renovar OPcache. No necesita migraciones ni cambios de infraestructura.

## Landing de conversión local

La landing usa componentes propios en `app/resources/js/components/marketing/`, el núcleo YouTube oficial adaptado en `components/media/jakawi-vsl-player/` y el formulario en `components/forms/`. No necesita el runtime ni APIs de jakawi.com. La procedencia de los archivos consultados está en `artifacts/conversion/provenance.json`. Se conserva el tema `light-conversion`, las fuentes locales y el único formulario `/registro`.

El hero responsive usa dos columnas desde 1024 px, con el texto centrado verticalmente y alineado a la izquierda. En móvil coloca el único reproductor entre titular y descripción. La presentación de la landing es 3:4; `JakawiVslPlayer` conserva 16:9 como opción y permite `sourceAspectRatio` para encuadrar la fuente aprobada sin recorte. El poster local usa el mismo marco con `object-fit: contain`. `MobileStickyCTA` aparece solo por debajo de 768 px, apunta al formulario existente y se oculta ante formulario visible, teclado, diálogo, fullscreen, interacción con video o posible superposición sobre el marco. Incluye safe area y espacio inferior. Informe y rollback actuales: [responsive](artifacts/responsive/REPORT.md) y [rollback responsive](artifacts/responsive/ROLLBACK.md).

`libphonenumber-js` está fijado en el lockfile. El selector local empieza en Bolivia (+591), permite búsqueda y teclado y utiliza metadatos `max` para validar y normalizar a E.164. Las 245 banderas SVG son assets locales de `country-flag-icons@1.6.20`, con licencia y hashes en `app/public/marketing/flags/`; no requieren otra dependencia de ejecución. Se pueden recuperar con `bash scripts/prepare-country-flags.sh`, en su contenedor limitado. El país manual tiene prioridad: pegar un número de otro país exige elegir ese país. El backend mantiene su normalización y validación E.164 originales; no se modifican cuentas, activación ni atribución.

El video oficial aprobado es el Short `P42gDOTL6Ys` (`https://youtube.com/shorts/P42gDOTL6Ys`), configurado mediante `JAKAWI_VIDEO_URL`. Laravel lo publica como URL canónica de YouTube; React recibe esa configuración sin hardcodear la URL. La landing conserva el marco 3:4 y usa `sourceAspectRatio="9:16"` para mostrar el Short completo, centrado y con bandas laterales. El poster sigue siendo opcional y no hay uno aprobado configurado. Evidencia y rollback: [publicación VSL](artifacts/vsl/REPORT.md) y [rollback VSL](artifacts/vsl/ROLLBACK.md).

Configurar **solo** en `/srv/sites/jakawi.club/secrets/app.env`:

- `JAKAWI_VIDEO_URL`: URL HTTPS aprobada de YouTube (`watch`, `youtu.be`, `embed`, `shorts` o `live`). Laravel rechaza otros hosts, credenciales y puertos y publica una URL canónica.
- `JAKAWI_VIDEO_POSTER`: opcional, ruta de archivo local existente como `/marketing/presentacion.webp`. Copiar el archivo aprobado a `app/public/marketing/`; se admiten PNG, JPEG y WebP.

Después de un cambio de entorno se debe recrear **únicamente app**, porque Compose inyecta el env al crear el contenedor: `docker compose up -d --no-deps --force-recreate app`, seguido de `docker compose exec -T --user www-data app php artisan config:cache` y `docker compose restart app`. No mostrar el env en consola. Configurar el video no abre registros. La API de YouTube y su iframe `youtube-nocookie.com` se cargan solo tras pulsar reproducir. Hay pausa, sonido, repetición, pantalla completa y estados de carga/error. No se copia la barra decorativa ni se condicionan CTAs al video. Sin URL válida no hay botón de reproducción ni conexión a YouTube.

La medición propia usa `POST /medicion`, protegido con CSRF y límite de frecuencia. Solo permite visita, clic de CTA e inicio de video. Los conteos agregados se guardan por día de Bolivia, evento y ubicación en `marketing_event_totals`; no contiene IDs, IPs, códigos de referido ni datos del formulario. Se deduplican eventos de navegador por sesión/día/ubicación con un digest HMAC temporal en la caché local. El registro completado se cuenta dentro de la transacción de creación de la cuenta: los correos repetidos y las solicitudes rechazadas no suman conversiones. Los eventos del navegador son conteos operativos, no una prueba de retención ni una medición antifraude. No hay Meta Pixel, GA4 ni envío de métricas a jakawi.com.

La única migración añadida crea esa tabla de totales. No modifica ni elimina datos de usuarios. Los registros públicos siguen cerrados y se conserva la doble habilitación más SMTP real del MVP.

Pruebas focalizadas, en secuencia y con los límites existentes:

```bash
bash scripts/test-conversion-unit.sh
bash scripts/test-mvp.sh
bash scripts/build-frontend.sh
# Publicar assets según el procedimiento existente, conservando chunks anteriores.
bash scripts/check-conversion-visual.sh
python3 scripts/validate-local.py
python3 scripts/validate-public.py
```

El navegador reutiliza Chromium instalado, sin instalaciones globales. Intercepta los envíos de cuentas y usa fixtures del proveedor para probar estados de video sin crear usuarios LIVE ni publicar un video de prueba. Se comprueba 390, 768, 1024 y 1440 px, además de viewports de poca altura, sticky, foco y movimiento reducido. Cuando hay video configurado, retira la interceptación de YouTube y prueba clics reales en 390 y 1440 px con caché desactivada; solo acredita reproducción si la API real del proveedor informa reproducción y su tiempo avanza. Los rechazos de YouTube se documentan como limitaciones. Se puede repetir únicamente esa sonda con `bash scripts/check-conversion-visual.sh --real-provider-only`, conservando las pruebas responsive del mismo build. Evidencia histórica de la integración: `artifacts/conversion/REPORT.md`; composición responsive: `artifacts/responsive/`; publicación del video: `artifacts/vsl/`.

## Creador de Marketing Landings

`/admin/landings` utiliza las sesiones existentes y exige cuenta activada, correo verificado y permiso `is_marketing_admin`. La columna comienza en `false`, no es asignable desde formularios públicos ni desde JSON editorial. No se crean cuentas administrativas automáticamente. Conceder o revocar el permiso a una cuenta existente:

```bash
docker compose exec -T --user www-data app php artisan landings:admin correo-del-administrador
docker compose exec -T --user www-data app php artisan landings:admin correo-del-administrador --revoke
```

La instalación no tenía usuarios al desplegar este creador. La identidad de la cuenta administrativa queda por definir; el registro público sigue cerrado. El comando rechaza cuentas inexistentes, sin activar o sin verificar. No abrir registros para aprovisionar administradores.

El modelo `MarketingLanding` usa `marketing_landings.configuration` y `published_configuration` JSONB. `LEAD_VSL` reutiliza el renderer, identidad, selector telefónico, formulario y referidos locales. `/l/{slug}` solo expone landings publicadas; `/` busca el slug `principal` definido en `config/jakawi.php`. La migración crea esa portada con el contenido y video locales aprobados, sin cambiar cuentas, precios ni credenciales. El slug queda permanente tras publicar y la portada no se puede archivar.

Crear, guardar, duplicar, importar, previsualizar, publicar y archivar son acciones distintas. Guardar/importar no cambia la versión pública. Duplicar produce otro borrador. Publicar copia exclusivamente el borrador guardado y su campaña a un snapshot público, con bloqueo de fila y revisión para impedir sobrescrituras desde otra ventana. Archivar retira la ruta pública sin borrar datos. La vista previa autenticada utiliza la misma página `Welcome`, en un iframe real a 390, 430 o 1440 px, sin registro ni eventos analíticos.

La importación/exportación usa `jakawi-club-landing-v1`. Ejemplo parcial:

```json
{
  "schema": "jakawi-club-landing-v1",
  "template": "LEAD_VSL",
  "content": {
    "hero": { "title": "Tu próximo capítulo empieza aquí" },
    "video": { "aspect_ratio": "3:4", "source_aspect_ratio": "9:16" }
  }
}
```

Los campos omitidos se conservan; las listas se reemplazan completas. Solo se admiten textos de `seo`, `hero`, `benefits`, `registration`, `faq`, `final` y la configuración `video`. Se rechazan campos desconocidos, HTML, listas incompletas, URLs ajenas a YouTube, posters remotos/inexistentes y documentos de otra versión. El documento no controla slug, campaña, estado, permisos, precios, reglas de negocio, endpoints ni CSS. La exportación representa el borrador persistido, sin metadatos operativos.

YouTube, poster local, marco 3:4/16:9 y proporción original 9:16/3:4/16:9 son independientes. Tras la migración, editar el video de cada landing desde el editor: `JAKAWI_VIDEO_URL` y `JAKAWI_VIDEO_POSTER` proporcionan valores iniciales a landings nuevas, sin sobrescribir snapshots existentes. Los posters siguen usando archivos aprobados de `public/marketing/`.

La campaña es un identificador interno opcional, administrado fuera del JSON editorial. No existe un catálogo previo de campañas en este repositorio. `marketing_landing_event_totals` amplía los agregados existentes por landing/campaña publicada, conserva el histórico al cambiar de campaña y no almacena datos personales. El registro confirmado sigue midiéndose en su transacción original. Los previews no emiten eventos. No hay integraciones con el CMS o APIs de jakawi.com.

Validación focalizada, sin instalaciones ni cuentas de prueba LIVE:

```bash
bash scripts/test-landings.sh
bash scripts/test-mvp.sh
bash scripts/build-frontend.sh
# Publicar chunks conservando los anteriores y cambiar manifest atómicamente.
bash scripts/check-landings-visual.sh
bash scripts/check-landings-responsive.sh --landing-builder
python3 scripts/validate-local.py
python3 scripts/validate-public.py
```

Las pruebas administrativas usan PostgreSQL en un esquema temporal que se elimina al terminar. Generan fixtures HTTP reales para probar el editor en Chromium, con las mutaciones interceptadas en navegador. El responsive comprueba la portada HTTPS real a 390, 430 y 1440 px y reutiliza fixtures de video/formulario sin crear usuarios LIVE. No repite la sonda externa de YouTube: la restricción histórica del proveedor sobre el video aprobado se documenta en `artifacts/vsl/REPORT.md`.

Evidencia: `artifacts/landings/`. [Rollback del creador](artifacts/landings/ROLLBACK.md). Después del commit y push, `python3 scripts/verify-landings-live.py` sella el SHA efectivo, los archivos montados, assets servidos, servicios propios y cierre del registro en `artifacts/landings/LIVE.json`, excluido de Git.
