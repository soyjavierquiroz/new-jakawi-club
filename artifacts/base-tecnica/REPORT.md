# Base técnica de JAKAWI.CLUB

Validada el 28 de octubre de 2026, hora de Bolivia. Proyecto local operativo, sin publicación pública.

**A. Estructura creada**

- Código y repositorio Git independiente: `/opt/sites/jakawi.club/`.
- Laravel: `app/`; infraestructura: `compose.yaml`, `docker/`, `scripts/`.
- Persistencia exclusiva: `/srv/sites/jakawi.club/{postgres,storage,bootstrap-cache,nginx-logs,secrets}/`.

**B. Versiones instaladas**

Laravel 13.39.0, PHP 8.4.27, Inertia 3 (Laravel 3.5.2 y adaptador React 3.9.1), React 19.3.1, TypeScript 5.9.3, Tailwind CSS 4.3.3, Vite 8.3.5, PostgreSQL 16.15, Nginx 1.30.6 y Composer 2.10.3. Node 22.23.4 se utiliza exclusivamente como herramienta de build en Docker. Imágenes fijadas por digest y dependencias por archivos lock.

**C. Contenedores y consumo aproximado**

| Contenedor | Estado | RAM observada | Límite RAM |
| --- | --- | --- | --- |
| `jakawi-club-app` | healthy | 31 MiB | 256 MiB |
| `jakawi-club-web` | healthy | 2–3 MiB | 48 MiB |
| `jakawi-club-db` | healthy | 35 MiB | 192 MiB |

Consumo observado conjunto: aproximadamente 69 MiB; límites conjuntos: 496 MiB. Reinicio `unless-stopped`, CPU/PID/swap limitados. Los contenedores temporales de instalación y el builder fueron eliminados. No se ejecutaron builds en paralelo.

**D. Migraciones**

Tres migraciones iniciales aplicadas correctamente en el lote 1: usuarios/sesiones, caché y colas. Base `jakawi_club`, rol `jakawi_club` sin superusuario ni permisos para crear bases o roles. Caché y sesiones en PostgreSQL verificadas. No se implementaron registro, referidos ni pagos.

**E. HTTP local y configuración**

`http://127.0.0.1:18082/` devuelve **HTTP 200** en el servidor; respuesta Inertia y los tres assets compilados también devuelven 200. Portada: «JAKAWI Launch Engine» y «Próximamente en jakawi.club».

`APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://jakawi.club`; sesiones, caché y colas usan `database`; `MAIL_MAILER=log`. APP_KEY y contraseñas generadas de forma independiente, fuera de Git y sin mostrarlas en los informes. TypeScript, Vite y validación estricta de Composer correctos.

Único puerto publicado: `127.0.0.1:18082`. PostgreSQL no publica puertos y solo participa en la red interna `jakawi-club-private`. La red de salida `jakawi-club-egress` es propia. Ningún servicio se conecta a Traefik; todos tienen `traefik.enable=false`.

**F. Estado de jakawi.com**

`https://jakawi.com/up` respondió **HTTP 200** antes, durante y al finalizar la implementación. Los 40 contenedores previos conservan ID, fecha de arranque y contador de reinicios; los 44 archivos de infraestructura comparados conservan su hash. Sin modificaciones de otros proyectos ni de la configuración compartida de Traefik.

**G. Archivos principales**

`compose.yaml`, `docker/php/{Dockerfile,php.ini,fpm.conf}`, `docker/nginx/{nginx.conf,default.conf}`, `docker/postgres/01-app-role.sh`, `scripts/{prepare-host.py,build-php.sh,install-dependencies.sh,start-local.sh,validate-local.py}`, `app/composer.lock`, `app/package-lock.json`, `app/resources/js/pages/Welcome.tsx`, `app/resources/views/app.blade.php` y `README.md`.

Evidencia sin secretos: [validation.json](validation.json) y [runtime.json](runtime.json). El commit de esta entrega se obtiene con `git -C /opt/sites/jakawi.club rev-parse HEAD` y se incluye en la respuesta de entrega.

**H. Problemas pendientes**

Sin bloqueos para esta fase. Antes de publicar quedan por definir el backup externo de PostgreSQL/storage y la rotación de logs de Nginx.

**I. Próximo paso recomendado**

Preparar y aprobar la publicación HTTPS de `jakawi.club` y su política de backups como una fase separada. Traefik y DNS permanecen sin cambios en esta entrega.

BASE TÉCNICA JAKAWI.CLUB IMPLEMENTADA.
