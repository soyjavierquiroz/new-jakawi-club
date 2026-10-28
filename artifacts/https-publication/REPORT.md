# Publicación HTTPS de JAKAWI.CLUB

28 de octubre de 2026, hora de Bolivia. Aplicación existente publicada con la autorización explícita de esta fase.

**A. Estado público**

`https://jakawi.club/` y `https://jakawi.club/up` devuelven HTTP 200. `https://www.jakawi.club/`, `http://jakawi.club/` y `http://www.jakawi.club/` redirigen con HTTP 301 a `https://jakawi.club/`. La redirección de `www` preserva ruta y query. Inertia y los tres assets de React/Vite responden 200; las URLs generadas por Laravel utilizan HTTPS.

**B. HTTPS y certificado**

Certificado de Let's Encrypt, emisor YE2, con SAN `jakawi.club` y `www.jakawi.club`; misma huella en ambos nombres. Cadena y hostname verificados con el almacén de confianza del sistema, sin omitir validación TLS. Expira el 25 de enero de 2027 a las 23:54:21, hora de Bolivia; renovación automática gestionada por el resolver existente `letsencrypt`, HTTP-01. No se manipuló el almacenamiento ACME.

**C. Red y routers**

Solo Nginx participa en la red externa compartida `web`, además de sus redes propias. El file provider carga `/opt/infra/traefik/dynamic/jakawi-club.yaml`; copia versionada en `docker/traefik/jakawi-club.yaml`.

- `jakawi-club-https`: `Host(jakawi.club)`, entrypoint `websecure`, resolver `letsencrypt`, certificado con ambos nombres.
- `jakawi-club-www-https`: `Host(www.jakawi.club)`, entrypoint `websecure`, redirección permanente al dominio principal.
- `jakawi-club-http`: ambos nombres en entrypoint `web`, redirección permanente a HTTPS.
- Servicio `jakawi-club`: `http://jakawi-club-web:80`, alcanzable por nombre desde el contenedor de Traefik.

`traefik.enable=false` en los tres servicios; no se habilitó Docker provider. Laravel reconoce HTTPS mediante trusted proxies restringidos a la subred de `web`. `APP_URL`, entorno production, debug desactivado y cookies Secure verificados. Credenciales intactas.

**D. Contenedores**

Los tres contenedores están running/healthy. Se recreó únicamente `jakawi-club-web` para incorporar la red. PHP-FPM recibió una recarga gradual interna para aplicar el código; su contenedor y PostgreSQL conservaron ID, fecha de arranque, contador de reinicios y redes.

PostgreSQL solo utiliza la red privada y no publica puertos. El acceso local continúa en `127.0.0.1:18082`; no se añadieron otros puertos de aplicación. No se ejecutaron migraciones ni builds.

**E. Continuidad de jakawi.com y del servidor**

`https://jakawi.com/up` devuelve HTTP 200. Los 40 contenedores ajenos, incluido Traefik, conservan ID, fecha de arranque, contador de reinicios y redes. Los 19 archivos previos de configuración global/dinámica de Traefik conservan su hash. No se modificaron otros proyectos, routers existentes, DNS ni credenciales.

**F. Archivos propios de esta entrega**

- `compose.yaml`: incorporación exclusiva de Nginx a `web`.
- `app/bootstrap/app.php`: trusted proxies.
- `docker/traefik/jakawi-club.yaml`: plantilla versionada y nuevo archivo dinámico exclusivo instalado.
- `scripts/validate-public.py` y ajuste de redes permitidas en `scripts/validate-local.py`.
- `README.md`, `.gitignore` y evidencias de esta carpeta.

Evidencia sin secretos: [validation.json](validation.json). Baseline local excluido de Git. [Rollback](ROLLBACK.md) preparado y no ejecutado: elimina solo el archivo dinámico propio y la conexión de Nginx a la red compartida.

**G. Git**

Cambios registrados en el repositorio independiente de `/opt/sites/jakawi.club/`. El commit completo se incluye en la entrega y se consulta con `git rev-parse HEAD`. Ningún secreto ni archivo privado de producción se incorpora al repositorio.

**H. Pendientes**

Sin bloqueos de publicación. Continúan pendientes operativos ya documentados: backup externo de PostgreSQL/storage y rotación de logs de Nginx. La portada conserva `noindex` mientras solo presenta el aviso de próximo lanzamiento.

No se implementaron registro, referidos, membresías ni pagos.

JAKAWI.CLUB PUBLICADO POR HTTPS.
