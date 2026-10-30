# JAKAWI Launch Engine — jakawi.club

Afiliados: **MIS ENLACES** y **MIS CONVERSIONES** en el dashboard privado,
panel `/afiliados`. Enlace Dossis 43 con código CLUB real y operaciones COM
confirmadas únicas por referencia, sin comisiones. [Contrato y operación del MVP](docs/integrations/club-affiliates-mvp.md).

Recepción comercial COM → CLUB: [Fase 05](artifacts/commercial-phase05/REPORT.md).
Receptor HMAC exclusivo `POST /internal/commercial/events`, idempotente y sin altas
de usuarios ni efectos CRM. Panel privado **CONVERSIONES COM** en
`/admin/conversiones-com`. [Contrato exacto para Codex COM](docs/integrations/com-commercial-events-v1.md).
No incluye comisiones, pagos ni saldos.

Acceso SSO COM → CLUB: [Fase 04](artifacts/identity-phase04/REPORT.md). Login principal **INGRESAR CON JAKAWI** en `https://jakawi.club/login`; acceso local conservado.

Identidad global COM: [Fase 03, operación y pruebas](artifacts/identity-phase03/REPORT.md). La acción **ACTIVAR MI ACCESO A JAKAWI.COM** pertenece exclusivamente al dashboard privado. Usa el contrato técnico real de COM y conserva cuentas, permisos, sesiones y atribución locales. El registro de leads no provisiona ni vincula cuentas COM.

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

`APP_DEBUG=false`, correo `log`, sesiones/caché/colas `database`. Las cookies conservan `Secure` porque el destino configurado es HTTPS; la portada se puede validar con HTTP local sin autenticación. La cola `crm` se atiende cada minuto mediante `jakawi-club-crm.timer` y un contenedor efímero limitado; no hay worker permanente.

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

**El registro público está abierto desde el 29/10/2026, por instrucción del propietario.** `JAKAWI_REGISTRATION_OPEN=true` y `JAKAWI_TRANSACTIONAL_MAIL_READY=true`, con SES SMTP aprobado. Se conservan los guards, CSRF, límites, validación, activación y atribución existentes. El propietario confirmó acceso de producción SES en us-east-1 y la política individual `JakawiClubSesSmtpSend` del usuario exclusivo de CLUB, restringida al remitente `info@jakawi.com`, sin `ses:Recipients`. La apertura es independiente del video.

La [preparación SMTP transaccional con SES](artifacts/smtp-preparation/REPORT.md) confirma compatibilidad con `smtps` en `us-east-1:2465` y documenta credenciales exclusivas, aplicación privada y prueba única. El transporte SES SMTP ya está configurado; la entrega real y SPF/DKIM/DMARC satisfactorios fueron confirmados por el propietario antes de la apertura.

Pruebas focalizadas sin dependencias PHP de desarrollo: `bash scripts/test-mvp.sh`. Usa PostgreSQL en un esquema temporal aislado, sesiones de prueba y notificaciones simuladas; no crea usuarios LIVE. TypeScript y build secuenciales: `bash scripts/build-frontend.sh`. Las dependencias no cambiaron y no necesitan reinstalarse.

Informes: [MVP](artifacts/mvp/REPORT.md), [operación y apertura](artifacts/mvp/OPERACION.md), [rollback del MVP](artifacts/mvp/ROLLBACK.md), [base técnica](artifacts/base-tecnica/REPORT.md) y [publicación HTTPS inicial](artifacts/https-publication/REPORT.md). El rollback del MVP conserva datos y la migración aditiva; no utiliza el rollback de publicación HTTPS.

Referencias de integración: [Laravel 13](https://laravel.com/docs/13.x/releases), [Inertia 3](https://inertiajs.com/docs/v3/installation/server-side-setup), [Vite](https://vite.dev/guide/).

## Identidad visual JAKAWI

Los tokens locales están en `app/resources/css/jakawi-theme.css`, importados desde `app.css` y expuestos a Tailwind 4 mediante `@theme inline`. La referencia oficial, consultada **solo en lectura**, es `/root/jakawi-build-v1/repository/app/resources/css/theme.css` (SHA `0bcda5842a0d1b9df93437784d7b3ad0fbb53fde`), junto con su `app.css` y `resources/js/layouts/marketing-layout.tsx`. Se aplica `data-marketing-theme="light-conversion"` a todas las páginas del micrositio. Los radios, espacios, superficies y sombras conservan los valores oficiales.

Marketing usa Montserrat (400–800) y los inputs Instrument Sans (400–600), exactamente como la referencia. Los WOFF2 oficiales se alojan en `app/resources/fonts/`, declarados en `jakawi-fonts.css`. Los originales `jakawi-logo.svg` y `jakawi-mark.svg` se reutilizan sin alterar geometría, proporciones ni colores; el icono WhatsApp proviene de `city-community-card.tsx`. Los hashes de procedencia están en `artifacts/identity/assets.json`.

Cuando cambie la marca, comparar manualmente el tema oficial y su contexto marketing, actualizar **solo los tokens locales necesarios**, y revisar las páginas a 390 y 1440 px antes de ejecutar `bash scripts/build-frontend.sh` y las validaciones locales. Actualizar el SHA documentado y la evidencia de procedencia. No hay sincronización automática ni paquete compartido. **Prohibido hardcodear colores en TSX**: utilizar tokens y utilidades semánticas. El hover coral se oscurece mediante `color-mix` y los CTA coral utilizan texto grande en negrita para preservar el contraste del blanco oficial. El foco combina el borde coral oficial de los campos con un contorno tinta visible sobre crema.

La evidencia de esta homologación y las capturas están en `artifacts/identity/` ([informe](artifacts/identity/REPORT.md)). `bash scripts/check-identity-visual.sh` reutiliza el Chromium local en un contenedor limitado, sin instalaciones ni cuentas LIVE; las páginas privadas se comprueban mediante fixtures solo del navegador. El sello local `LIVE.json` se genera después del commit para evitar autorreferencia. El build genera primero `public/build-next`; el despliegue conserva los chunks antiguos y publica el nuevo manifest al final, seguido únicamente del reinicio de `app` para renovar OPcache. No necesita migraciones ni cambios de infraestructura.

## Landing de conversión local

La landing usa componentes propios en `app/resources/js/components/marketing/`, el reproductor VSL oficial portado localmente en `components/media/jakawi-vsl-player/` y el formulario en `components/forms/`. No necesita el runtime ni APIs de jakawi.com. La procedencia de los archivos consultados está en `artifacts/conversion/provenance.json`. Se conserva el tema `light-conversion`, las fuentes locales y el único formulario `/registro`.

El hero responsive usa dos columnas desde 1024 px, con el texto centrado verticalmente y alineado a la izquierda. En móvil coloca el único reproductor entre titular y descripción. La presentación predeterminada es 3:4, con el crop marketing oficial. Se conserva 16:9 como opción del editor y la proporción de fuente se adapta a `framing`. El poster local usa el mismo marco con `object-fit: cover`. `MobileStickyCTA` aparece solo por debajo de 768 px, apunta al formulario existente y se oculta ante formulario visible, teclado, diálogo, fullscreen, interacción con video o posible superposición sobre el marco. Incluye safe area y espacio inferior. Informe y rollback actuales: [responsive](artifacts/responsive/REPORT.md) y [rollback responsive](artifacts/responsive/ROLLBACK.md).

`libphonenumber-js` está fijado en el lockfile. El selector local empieza en Bolivia (+591), permite búsqueda y teclado y utiliza metadatos `max` para validar y normalizar a E.164. Las 245 banderas SVG son assets locales de `country-flag-icons@1.6.20`, con licencia y hashes en `app/public/marketing/flags/`; no requieren otra dependencia de ejecución. Se pueden recuperar con `bash scripts/prepare-country-flags.sh`, en su contenedor limitado. El país manual tiene prioridad: pegar un número de otro país exige elegir ese país. El backend mantiene su normalización y validación E.164 originales; no se modifican cuentas, activación ni atribución.

El video oficial aprobado es el Short `P42gDOTL6Ys` (`https://youtube.com/shorts/P42gDOTL6Ys`), configurado mediante `JAKAWI_VIDEO_URL`. Laravel lo publica como URL canónica de YouTube; React recibe esa configuración sin hardcodear la URL. La landing conserva el marco 3:4 y adapta la proporción editorial 9:16 a `embedded-vertical`: canvas YouTube 16:9 con crop centrado oficial. Este encuadre recorta aproximadamente 25 % de la altura del contenido vertical; conserva sus proporciones. El poster sigue siendo opcional y no hay uno aprobado configurado. Evidencia y rollback: [publicación VSL](artifacts/vsl/REPORT.md) y [rollback VSL](artifacts/vsl/ROLLBACK.md).

Configurar **solo** en `/srv/sites/jakawi.club/secrets/app.env`:

- `JAKAWI_VIDEO_URL`: URL HTTPS aprobada de YouTube (`watch`, `youtu.be`, `embed`, `shorts` o `live`). Laravel rechaza otros hosts, credenciales y puertos y publica una URL canónica.
- `JAKAWI_VIDEO_POSTER`: opcional, ruta de archivo local existente como `/marketing/presentacion.webp`. Copiar el archivo aprobado a `app/public/marketing/`; se admiten PNG, JPEG y WebP.

Después de un cambio de entorno se debe recrear **únicamente app**, porque Compose inyecta el env al crear el contenedor: `docker compose up -d --no-deps --force-recreate app`, seguido de `docker compose exec -T --user www-data app php artisan config:cache` y `docker compose restart app`. No mostrar el env en consola. Configurar el video no abre registros. La API de YouTube y su iframe `youtube-nocookie.com` se crean al montar una plantilla que presenta video configurado. Se solicita autoplay silenciado respetando reduced-motion y saveData. El overlay activa audio desde cero y solo confirma mediante eventos del proveedor. Hay pausa, continuar, fin, replay y barra decorativa independiente 3/90, limitada al 97 % hasta ended. Los errores muestran el código del proveedor y permiten reintentar dentro del mismo player, sin poster que los oculte ni enlaces de salida; carga y buffering tienen tiempos máximos. Sin URL o en WAITLIST/WEBINAR sin video no se carga YouTube. No se condicionan conversiones a la barra.

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

El navegador reutiliza Chromium instalado, sin instalaciones globales. Intercepta los envíos de cuentas y usa fixtures identificados para comprobar autoplay, audio, progreso, errores, sticky y teléfono a 390, 430, 1024 y 1440 px. El modo `--live` retira la interceptación del proveedor y exige evento playing, tiempo real avanzando y mute confirmado para acreditar reproducción silenciada. El audio requiere una confirmación posterior equivalente. Los rechazos reales quedan registrados como limitaciones. Las evidencias actuales están en `artifacts/vsl-official/`; `artifacts/conversion/`, `artifacts/responsive/` y `artifacts/vsl/` conservan el historial anterior.

## Creador de Marketing Landings

El editor actual separa **LANDING DE CAPTACIÓN** y **PÁGINA DE CONFIRMACIÓN**; cada pestaña controla sus campos y su preview guardada. La barra superior fija reúne **Guardar borrador**, **Publicar cambios** y el estado de publicación. Guardar nunca publica. El video de la preview procede del borrador; el público procede de `published_configuration`. Las dos URL efectivas se pueden copiar. La confirmación canónica es `/{slug}/gracias`; `/{slug}/confirmado` conserva los recibos y enlaces anteriores. Ambas exigen recibo válido.

Captación usa únicamente **QUIERO SER PARTE DE JAKAWI**, con los CTA repetidos hacia el formulario, enlaces legales y acceso discreto. No muestra WhatsApp ni expone los grupos operativos en su payload público. Confirmación administra grupo específico, fallback de entorno u ocultación; las herencias anteriores siguen resolviéndose para no perder grupos de campañas. El grupo se fija en el recibo y conserva el bounce y la navegación por `ended` real.

Los leads siguen en PostgreSQL CLUB: `users.name/email/phone/uuid/referred_by_id/activated_at/email_verified_at`. `registration_origin` conserva individualmente landing/campaña/UTMs de registros nuevos; los históricos sin fuente permanecen desconocidos. Registro y activación escriben eventos cifrados en la cola `crm`, entregada cada minuto a FluentCRM con HMAC exclusivo CLUB, idempotencia y reintentos. El CRM conserva identidades COM y consentimiento comercial. [Contrato y operación](docs/crm-club-endpoint.md).

`/admin/landings` utiliza las sesiones existentes y exige cuenta activada, correo verificado y permiso `is_marketing_admin`. La columna comienza en `false`, no es asignable desde formularios públicos ni desde JSON editorial. No se crean cuentas administrativas automáticamente. Conceder o revocar el permiso a una cuenta existente:

```bash
docker compose exec -T --user www-data app php artisan landings:admin correo-del-administrador
docker compose exec -T --user www-data app php artisan landings:admin correo-del-administrador --revoke
```

La instalación no tenía usuarios al desplegar este creador. La identidad de la cuenta administrativa queda por definir; el registro público sigue cerrado. El comando rechaza cuentas inexistentes, sin activar o sin verificar. No abrir registros para aprovisionar administradores.

### Primer administrador mediante CLI

El propietario puede crear su primera cuenta real desde una terminal privada del servidor:

```bash
cd /opt/sites/jakawi.club
docker compose exec --user www-data app php artisan landings:bootstrap-admin
```

Usar una terminal interactiva y conservar el TTY de Compose: no añadir `-T` ni `--no-interaction`. El comando no recibe credenciales por argumentos, variables ni tuberías. Solicita nombre real, correo real, confirmación explícita de que el correo pertenece al propietario y controla su acceso, contraseña oculta y su repetición, y confirmación final del alta. La contraseña exige al menos 12 caracteres, letras y números, y un máximo de 72 bytes; conserva los espacios introducidos. Si no puede ocultar la entrada, cancela sin mostrarla.

La titularidad del correo se confirma manualmente por el propietario en esta operación CLI; no se afirma una comprobación mediante correo enviado. Solo después de ambas confirmaciones crea la cuenta activada y verificada con el UUID/código de referido del modelo existente y la contraseña almacenada mediante su cast de hash. Concede el permiso invocando `landings:admin`, dentro de la misma transacción. Si falla la concesión, revierte el alta completa. No envía correos, genera tokens, registra contraseñas ni cambia la configuración del registro público o del mailer.

Rechaza el correo de cualquier cuenta existente sin cambiar su contraseña o permisos, y rechaza crear otra cuenta si ya hay un administrador. Un bloqueo breve de la tabla de usuarios revalida esas condiciones durante el alta; nunca mantiene bloqueos mientras pregunta al propietario. Para cambios posteriores de permisos, usar el comando `landings:admin` existente.

Después del alta, iniciar sesión en `https://jakawi.club/login` con el correo y contraseña introducidos y abrir `https://jakawi.club/admin/landings`. Se reutilizan login, sesiones y comprobaciones administrativas existentes. Esta entrega prepara el comando y **no crea ninguna cuenta real**. Pruebas focalizadas: `bash scripts/test-admin-bootstrap.sh`; incluyen un terminal TTY real y esquemas PostgreSQL temporales, sin usuarios LIVE ni contraseñas de prueba en archivos. Evidencia y rollback: `artifacts/admin-bootstrap/REPORT.md`.

### Edición y publicación

El modelo `MarketingLanding` usa `marketing_landings.configuration` y `published_configuration` JSONB. `LEAD_VSL` reutiliza el renderer, identidad, selector telefónico, formulario y referidos locales. `/{slug}` solo expone landings publicadas; `/l/{slug}` redirige 301 al enlace directo después de comprobar su existencia; `/` busca el slug `principal` definido en `config/jakawi.php`. La migración crea esa portada con el contenido y video locales aprobados, sin cambiar cuentas, precios ni credenciales. El slug queda permanente tras publicar y la portada no se puede archivar.

Crear, guardar, duplicar, importar, previsualizar, publicar y archivar son acciones distintas. Guardar no cambia la versión pública. Importar valida y aplica al formulario local: no persiste ni publica. Duplicar produce otro borrador. Publicar copia exclusivamente el borrador guardado y su campaña a un snapshot público, con bloqueo de fila y revisión para impedir sobrescrituras desde otra ventana. Archivar retira la ruta pública sin borrar datos. La vista previa autenticada utiliza la misma página `Welcome`, en un iframe real a 390, 430 o 1440 px, sin registro ni eventos analíticos.

La importación/exportación usa `jakawi-club-landing-v2`. Ejemplo parcial:

```json
{
  "schema": "jakawi-club-landing-v2",
  "template": "LEAD_VSL",
  "content": {
    "hero": { "title": "Tu próximo capítulo empieza aquí" }
  }
}
```

Los campos omitidos se conservan; las listas se reemplazan completas. Admite también los JSON anteriores `jakawi-club-landing-v1`. Solo se admiten textos de `seo`, `hero`, `benefits`, `registration`, `faq`, `final` y `after_registration`. Video y poster se editan exclusivamente en sus controles y no se admiten en JSON editorial. Se rechazan campos desconocidos u operativos, HTML, listas incompletas y documentos de otra versión. Los controles de medios siguen rechazando URLs ajenas a YouTube y posters remotos/inexistentes. El documento no controla slug, campaña, estado, permisos, precios, reglas de negocio, endpoints ni CSS. La exportación visible representa los valores actuales del formulario, incluidos cambios sin guardar, sin metadatos operativos. El endpoint histórico `/{landing}/export` sigue entregando el borrador guardado.

YouTube, poster local y proporción original 9:16/3:4/16:9 son independientes. El marco visual es siempre 3:4; el encuadre cover se configura en el editor. Tras la migración, editar el video de cada landing desde el editor: `JAKAWI_VIDEO_URL` y `JAKAWI_VIDEO_POSTER` proporcionan valores iniciales a landings nuevas, sin sobrescribir snapshots existentes. Los posters siguen usando archivos aprobados de `public/marketing/`.

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


### Editor optimizado y plantillas

La portada `/` y `/principal` usan el mismo registro y snapshot publicado; `principal` está protegido. El listado destaca **PORTADA PRINCIPAL**. `LandingSlug` reserva namespaces explícitos y las raíces de todas las rutas registradas, incluyendo endpoints POST; la ruta GET de una sola sección se registra al final. Nunca redirigir aliases inexistentes. Tras añadir rutas, regenerar `route:cache` como en cualquier despliegue.

El editor tiene dos columnas desde 769 px y pestañas Editor/Preview hasta 768 px. La preview automática usa el renderer real del borrador guardado a 390, 430 o 1440 px, ajustado visualmente al panel; abrir a tamaño completo permite inspeccionarlo sin escala. Guardar permanece visible junto al formulario. El estado diferencia cambios sin guardar, borrador, publicación y cambios pendientes. La preview no registra usuarios ni eventos.

**COPIAR BRIEF PARA IA**, **IMPORTAR JSON** y **EXPORTAR JSON** están al inicio, también al crear una landing. El brief incluye identidad, campaña, objetivo, contexto editorial, condiciones conocidas y estado real del registro, sin datos de sesión ni credenciales. No hay integración de IA. Importar admite texto o archivo de 64 KiB, muestra errores por campo y devuelve configuración validada sin escrituras. Exportar/copia/descarga representa el formulario actual. Solo admite template y textos de seo, hero, benefits, registration, faq, final y after_registration; video, poster, presentación, estado, slug y campaña están excluidos. Los JSON históricos que contienen video deben retirar esa sección antes de importar.

`configuration.presentation` guarda plantilla y opciones; el snapshot publicado mantiene su propia presentación. Los snapshots anteriores reciben valores LEAD_VSL en memoria. No necesita migraciones.

- **LEAD_VSL:** composición original, VSL fijo 3:4, beneficios, formulario, FAQ, cierre y sticky móvil.
- **WEBINAR:** hero de encuentro, fecha/hora únicamente desde configuración confirmada, contenido aprobado en beneficios, video opcional y formulario existente. No crea eventos ni llamadas a Zoom.
- **WAITLIST:** hero compacto, formulario antes de beneficios (hasta tres), FAQ opcional y sticky. Conserva todos los textos y permite mostrar u ocultar el video principal desde sus controles.

Duplicar crea un borrador independiente. Las tres plantillas reutilizan registro, atribución, tokens y publicación existentes. Evidencia actual: [informe del editor](artifacts/editor/REPORT.md). Pruebas: `bash scripts/test-landings.sh`, `bash scripts/test-mvp.sh`, `bash scripts/build-frontend.sh`, `bash scripts/check-landings-visual.sh`, `bash scripts/check-landings-responsive.sh --landing-builder`, validadores local y público. Los runners de landings guardan la evidencia nueva en `artifacts/editor/`. Sellar commit/LIVE después del push con `python3 scripts/verify-editor-live.py`; requiere el baseline local ignorado. [Rollback](artifacts/editor/ROLLBACK.md).


### Paridad del VSL oficial

Fuente de código cotejada con producción de jakawi.com: `130bfac34a645060c0df9767e3994064451349c3`. Informe de implementación y límites: [VSL oficial](artifacts/vsl-official/REPORT.md), [procedencia por archivo](artifacts/vsl-official/provenance.json) y [rollback](artifacts/vsl-official/ROLLBACK.md). No se instala HLS ni una librería de iconos: este host admite YouTube y VSL; los cuatro SVG oficiales son locales y conservan su licencia. La URL permanece en la configuración de cada landing, fuera de JSON editorial `jakawi-club-landing-v2`; no se hardcodea en el renderer.

Pruebas actuales: `bash scripts/test-conversion-unit.sh`, `bash scripts/test-landings.sh`, `bash scripts/test-mvp.sh`, `bash scripts/build-frontend.sh`, `bash scripts/check-vsl-official.sh` (build-next interceptado únicamente en navegador), `bash scripts/check-landings-visual.sh`. Los entry points responsive anteriores delegan en las pruebas oficiales; los fixtures nunca acreditan reproducción real. Tras publicar assets, `bash scripts/check-vsl-official.sh --live` repite estados/layout y consulta el proveedor real con caché desactivada. `python3 scripts/verify-vsl-live.py` sella Git, archivos montados, assets HTTPS, aislamiento, rutas y datos contra el baseline de esta entrega; escribe `artifacts/vsl-official/LIVE.json`, ignorado para evitar autorreferencia. No aplicar migraciones.

El video aprobado ha devuelto error 150 también en el origen. Esta implementación conserva su ID, host y parámetros documentados; no garantiza autorización de inserción. Las pruebas físicas Safari/iPhone y Android permanecen diferenciadas de Chromium.

### Formulario compacto de captación

LEAD_VSL, WEBINAR y WAITLIST comparten los placeholders `Tu nombre completo`, `WhatsApp` y `Tu correo electrónico`, con labels asociados visualmente ocultos. Se conserva el selector internacional con Bolivia +591, las validaciones y E.164, sin la ayuda de código de área. El texto de privacidad es `Acepto el acuerdo de privacidad`, con enlace local a `/privacidad`; comienza marcado por solicitud expresa del propietario y se puede desmarcar. El backend conserva `required|accepted` y registra fecha y versión de la aceptación; el estado inicial no acredita un clic afirmativo individual. No suscribe a marketing ni cambia las preferencias opcionales.

Se retiran los avisos de preapertura del bloque de conversión. **Los registros siguen cerrados mientras el correo efectivo sea `log`**: formulario y CTA deshabilitados con un único estado breve, `Registro no disponible.`. Falta SMTP transaccional real y confirmar entrega/activación antes de aprobar las dos banderas existentes. No abrir desde textos editoriales ni pruebas simuladas.

Evidencia, capturas y rollback: [informe del formulario](artifacts/registration/REPORT.md). Validación focalizada del navegador: `bash scripts/check-registration-visual.sh` antes de publicar assets y `bash scripts/check-registration-visual.sh --live` después; usa los contenedores limitados y nunca envía altas LIVE. Tras commit/push, sellar el SHA efectivo con `python3 scripts/verify-registration-live.py`; requiere el baseline privado de esta entrega.


### Después del registro

`Registered` sigue siendo la única página de confirmación y bienvenida. Un POST `/registro` aceptado por el backend emite el recibo de sesión de 15 minutos existente, vinculado a la landing y a su snapshot/campaña publicados. Redirige a `/{slug}/gracias` (por ejemplo `/principal/gracias`, `/webinar/gracias` o `/fundadores/gracias`, cuando esas landings estén publicadas). Sin recibo de esa landing vuelve a su página pública; slugs inexistentes, archivados o reservados devuelven 404. `confirmado` queda reservado. Parámetros de query y visitas no generan recibos ni conversiones. No se crean landings de ejemplo en LIVE.

`/bienvenida` y el consumo de tokens en `/activar` se conservan. Una activación en la misma sesión y para el mismo correo vuelve a la landing del recibo. En otra sesión utiliza la bienvenida genérica existente. El estado «Cuenta activada» exige sesión autenticada, correo verificado y `activated_at`; una sesión con recibo de otro correo sigue mostrando «Revisa tu correo». El correo repetido recibe el mismo mensaje genérico sin crear otra cuenta, cambiar referente ni sumar conversión. El origen y campaign key se conservan en el recibo temporal y los agregados existentes; no se añade rastreo personal ni cambia el programa de afiliados.

El grupo plegable **Después del registro** conserva los textos configurables `after_registration.title`, `message` e `instructions`, incluidos en JSON editorial v2. El titular predeterminado es **¡Felicidades por dar el siguiente paso!**; la presentación actualiza únicamente el anterior titular predeterminado y conserva los personalizados. Los campos heredados `cta_label` y `next_step` se conservan por compatibilidad, sin controles de acciones secundarias ni contador en el editor.

La confirmación está centrada, sin header, navegación ni acciones secundarias. Su único CTA es **UNIRME AL GRUPO DE WHATSAPP**, verde `#25D366`, con bounce periódico que respeta `prefers-reduced-motion`. El bloque **Revisa tu correo** lleva un SVG local. El grupo efectivo validado procede del recibo: grupo propio de confirmación → grupo heredado de la landing → fallback `JAKAWI_WHATSAPP_GROUP_URL`; ocultarlo conserva el destino nulo. Sin grupo válido no se muestra botón ni se navega.

Solo el evento real `ended` del `JakawiVslPlayer` de confirmación inicia `window.location.assign`, una vez por montaje. Pausa, errores, progreso decorativo y tiempo transcurrido no redirigen. La preview y las sesiones administrativas tampoco redirigen. El enlace manual permanece disponible si la navegación no se completa. El VSL de captación, los recibos y la lógica de registro/SMTP/afiliados se conservan. Prueba focalizada: `bash scripts/check-confirmation-visual.sh`, seguida de `--live` tras publicar assets. Informe y capturas: [confirmación](artifacts/confirmation-focus/REPORT.md); sello de Git/assets/`/up`: `python3 scripts/verify-confirmation-live.py` después del push normal.

Validación focalizada de esta entrega: `bash scripts/test-postconversion.sh`, pruebas existentes de landings/MVP/unidades y `bash scripts/build-frontend.sh`, secuenciales. Para guardar evidencia nueva de landings/editor sin alterar la histórica: `JAKAWI_EVIDENCE_DIR=/opt/sites/jakawi.club/artifacts/postconversion bash scripts/test-landings.sh` y el mismo prefijo para `bash scripts/check-landings-visual.sh`. Navegador del formulario y gracias: `bash scripts/check-postconversion-visual.sh` y `--live` tras desplegar. Los casos de apertura y correo usan exclusivamente esquemas PostgreSQL temporales/notificaciones falsas y fixtures de navegador; no son altas LIVE ni prueba SMTP. Informe y rollback: [posconversión](artifacts/postconversion/REPORT.md), [rollback](artifacts/postconversion/ROLLBACK.md). Sellar con `python3 scripts/verify-postconversion-live.py` después del commit/push.


### Videos públicos 3:4 y confirmación por landing

Todos los videos públicos y sus previews usan `JakawiVslPlayer` con marco visual fijo 3:4 en desktop y móvil. En **Video y medios** se configuran URL de YouTube, poster local existente, proporción del contenido original y encuadre horizontal/vertical (0–100 %). El video principal puede mostrarse u ocultarse en LEAD_VSL, WEBINAR y WAITLIST. El recorte cover conserva la proporción del contenido y limita el encuadre al área útil para evitar bandas del lienzo incrustado. Se mantienen autoplay muted, audio desde cero, overlay, progreso decorativo, pause/replay y errores del proveedor.

**Después del registro** conserva los textos de `after_registration` y añade `confirmation_video`, separado del JSON editorial. El recibo aceptado guarda este video junto al snapshot de la landing; la misma página `Registered` y el mismo player sirven las tres plantillas. Solo una cuenta realmente activada y verificada puede mostrar ese estado. Refrescar no registra conversiones nuevas. La preview usa el borrador guardado y no envía eventos ni altas.

Configuración operativa opcional en `/srv/sites/jakawi.club/secrets/app.env`:

```dotenv
JAKAWI_CONFIRMATION_VIDEO_URL=
JAKAWI_WHATSAPP_GROUP_URL=
JAKAWI_REGISTRATION_OPEN=false
```

Una URL específica de confirmación tiene prioridad sobre la predeterminada. Sin ambas URLs válidas, se oculta el reproductor público y el admin explica el estado vacío. Las URLs de video publicadas se conservan al normalizar snapshots heredados, incluido `P42gDOTL6Ys`; ningún ID se excluye de los defaults. El commit `a4e46cc` anulaba ese ID cuando faltaba `source_type`, causando su desaparición en `/` y `/principal`. La comparación con YouTube real en Chromium del servidor devuelve error 150 en COM y CLUB para ese mismo video; el iframe de CLUB pide confirmar que el visitante no es un bot: montaje y `onReady` confirmados, sin `playing` ni tiempo avanzando. Evidencia focalizada en `artifacts/vsl-recovery/live-domains.json`; no se declara la VSL resuelta. Las landings sin video usan una sola columna.

El grupo predeterminado se lee desde `JAKAWI_WHATSAPP_GROUP_URL`, mediante `config/jakawi.php`; cada landing puede reemplazarlo u ocultarlo desde el editor: HTTPS `chat.whatsapp.com` y código de invitación, sin query, credenciales, fragmentos ni puertos. Este nombre sustituye a `JAKAWI_WHATSAPP_URL` para el grupo oficial. Aparece en todas las confirmaciones con el verde de WhatsApp, texto **UNIRME AL GRUPO DE WHATSAPP**, `target="_blank"` y `rel="noopener noreferrer"`. Vacío o inválido oculta el botón. El JSON editorial v2/v1 no puede cambiar el grupo, videos, poster, encuadre ni destinos operativos.

Después de cambiar el entorno privado, aplicar exclusivamente a CLUB con `docker compose up -d --no-deps app`, regenerar `config:cache` y reiniciar solo `app`. Compose vuelve a cargar las variables de `env_file` al recrear el servicio; un reinicio por sí solo no lee los cambios del archivo. No modificar SMTP ni abrir el registro durante esa operación.

La incidencia de activación de `javierquirozsueldo@gmail.com` procedía de la serialización Bolivia/UTC del runner, ya corregida conservando el token y su expiración original. La cuenta está activada y verificada; el token ya se consumió y no corresponde reemitir su activación. Las pruebas aisladas reproducen el error de cuatro horas, la reemisión que invalida el token anterior, la expiración de 60 minutos y el uso único. No se reenvía correo LIVE ni se modifica la cuenta administrativa.

Pruebas, capturas y diagnóstico: [entrega de videos y confirmación](artifacts/video-confirmation/REPORT.md). Sello después del commit/push: `python3 scripts/verify-video-confirmation-live.py`; verifica archivos montados, assets HTTPS, hashes de cuentas/permisos/tokens/conversiones y ambos `/up`, con baseline exclusivo de CLUB y sin consultar infraestructura compartida. El resultado `artifacts/video-confirmation/LIVE.json` no se versiona para evitar autorreferencia.


### Campañas configurables por landing

El JSONB conserva por compatibilidad dos ajustes operativos: `whatsapp_group` (`default`, `custom`, `none`) y `confirmation_whatsapp_group` (`default`, `custom`, `none`, además de `inherit` heredado). El editor actual solo gestiona el grupo desde confirmación. La confirmación hereda de inicio el grupo efectivo de su landing, incluido un grupo propio, el fallback del sistema o la ausencia de grupo. Un grupo diferente en confirmación tiene prioridad explícita. Solo se admiten invitaciones HTTPS de `chat.whatsapp.com`, sin credenciales, puertos, parámetros, fragmentos, rutas adicionales ni dominios parecidos. Se validan al guardar y nuevamente al publicar.

`Welcome` omite WhatsApp y los destinos de grupo; `Registered` conserva un único botón **UNIRME AL GRUPO DE WHATSAPP** y el verde oficial. Los enlaces públicos abren otra pestaña con `noopener noreferrer`. Sin grupo válido se ocultan CTA y contador. El dashboard mantiene su fallback global.

Guardar modifica exclusivamente el borrador; publicar copia la configuración al snapshot público. Las dos previews usan el borrador guardado. El POST de registro aceptado fija en el recibo tanto el grupo efectivo como el video efectivo de confirmación, incluidos los fallbacks y los valores vacíos. Editar o republicar la landing, o cambiar posteriormente los defaults, no cambia un recibo vigente. Los recibos anteriores al despliegue se fijan en su primera lectura. Se mantienen protección por sesión/origen, expiración de 15 minutos, estado real de activación, referido y conteo único de conversiones.

El video principal y el de confirmación conservan URL, poster local, crop, reproductor oficial y marco fijo 3:4. Ambos tienen un control para mostrar/ocultar; ocultarlos conserva sus medios guardados. El video de confirmación usa URL específica, después `JAKAWI_CONFIRMATION_VIDEO_URL`, y después ningún video. Los grupos, medios y visibilidad quedan fuera del JSON editorial `jakawi-club-landing-v2`, con importación v1 compatible. No hay migraciones ni dependencias nuevas.

Pruebas focalizadas y evidencia: [informe de campañas](artifacts/campaigns/REPORT.md). Ejecutar secuencialmente los runners existentes con `JAKAWI_EVIDENCE_DIR=/opt/sites/jakawi.club/artifacts/campaigns`: `scripts/test-landings.sh`, `scripts/test-postconversion.sh`, `scripts/check-landings-visual.sh` y `scripts/check-postconversion-visual.sh` (también `--live` tras desplegar). TypeScript/Vite: `bash scripts/build-frontend.sh`. Los fixtures usan esquemas PostgreSQL temporales y navegador interceptado; no crean campañas/cuentas LIVE ni envían correo. `python3 scripts/verify-campaigns-live.py` sella Git/LIVE después del push, verifica ambos `/up` y loopback, assets y código montado, entorno intacto, tablas protegidas y registro cerrado. El sello `artifacts/campaigns/LIVE.json` se excluye de Git para evitar autorreferencia.
