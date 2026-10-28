# Rollback de la publicación HTTPS

Preparado para esta entrega; no ejecutarlo si la publicación funciona. No modifica DNS, ACME, Traefik global, otros routers, PHP-FPM ni PostgreSQL.

Desde `/opt/sites/jakawi.club`, como administrador:

```bash
set -e
rm -- /opt/infra/traefik/dynamic/jakawi-club.yaml
git show e6cd33140d601b42a06e53d574115aa9ddbc62d7:compose.yaml > compose.yaml
docker compose config --quiet
COMPOSE_PARALLEL_LIMIT=1 docker compose up --detach --no-deps --wait --wait-timeout 60 web
curl --fail http://127.0.0.1:18082/
curl --fail https://jakawi.com/up
```

El Compose restaurado quita únicamente la incorporación de `web` a la red externa compartida. Se recrea solo `jakawi-club-web`; Laravel y PostgreSQL conservan sus redes, nombres, credenciales y datos. La configuración de trusted proxies puede permanecer en el código al retirar el acceso público.

Si el Compose cambia después de esta entrega, retirar manualmente solo `traefik` de `services.web.networks` y de `networks`, en lugar de restaurar el archivo completo del commit indicado.

Traefik detecta la eliminación del archivo mediante file provider. No reiniciarlo ni borrar el certificado emitido: el almacenamiento ACME se administra automáticamente.
