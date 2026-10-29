# Rollback del editor optimizado

Baseline confirmado antes de editar: `95bb8a4d65a4ca01e5419799dbb47d67404e0f5c`.
Copia privada exclusiva: `/srv/sites/jakawi.club/rollback-editor-95bb8a4d65a4/` (`code.tar` y `manifest.json`). Se conservan todos los chunks anteriores en `app/public/build/assets/`.

Revertir el commit de esta entrega con Git y publicar esa reversión mediante push normal. El archivo privado de código permite recuperar los archivos si Git no estuviera disponible; no extraerlo indiscriminadamente sobre cambios posteriores.

Después de revisar/aplicar la reversión del código:

```bash
cd /opt/sites/jakawi.club
cp /srv/sites/jakawi.club/rollback-editor-95bb8a4d65a4/manifest.json app/public/build/manifest.rollback.json
mv app/public/build/manifest.rollback.json app/public/build/manifest.json
docker compose exec -T --user www-data app php artisan route:cache
docker compose exec -T --user www-data app php artisan view:cache
docker compose restart app
python3 scripts/validate-local.py
python3 scripts/validate-public.py
```

No hubo migraciones ni escrituras de usuarios/landings LIVE durante este despliegue. No restaurar PostgreSQL, eliminar datos, retirar routers ni reiniciar Nginx, PostgreSQL o Traefik. Una landing editada posteriormente con las plantillas nuevas requiere volver a LEAD_VSL y publicar antes de retirar el nuevo renderer; conservar sus datos y revisar manualmente ese caso antes de revertir. No tocar secretos, DNS, jakawi.com ni servicios compartidos.
