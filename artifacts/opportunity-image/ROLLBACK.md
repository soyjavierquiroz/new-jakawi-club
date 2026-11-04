# Rollback RF-LANDING-OPP-IMAGE

El despliegue guarda SHA anterior y manifest en `/srv/sites/jakawi.club/rollback-opportunity-image/`.
Solo si se necesita revertir, desde `/opt/sites/jakawi.club`:

1. Consultar `release.json` del backup privado y ejecutar `git switch --detach SHA_ANTERIOR`.
2. Restaurar `manifest.json` del backup a `app/public/build/manifest.json` sin borrar chunks.
3. Ejecutar `docker compose exec -T --user www-data app php artisan config:cache`, luego `route:cache` y `view:cache` del mismo modo.
4. Ejecutar `docker compose restart app` y `python3 scripts/validate-local.py`.

No hay migración. Conservar imágenes, snapshots y configuración editorial; el código previo no renderiza el campo nuevo. No revertir datos, ejecutar GC como parte del rollback ni tocar COM, CRM, Traefik, secretos o buckets. El script restaura automáticamente código, manifest y cachés si falla su propia comprobación posterior.
