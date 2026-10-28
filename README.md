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
