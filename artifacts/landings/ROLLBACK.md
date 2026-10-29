# Rollback del creador de landings

Baseline: `9364f26244050d5202a74f3049c38171c914d001`. Copia privada exclusiva: `/srv/sites/jakawi.club/rollback-landings-9364f262/` (código, assets y dump de `jakawi_club`). No contiene artefactos de otros proyectos.

Preferir un commit de reversión de los archivos de aplicación de este cambio, conservando la migración y los datos nuevos. Publicar el manifest anterior desde `build.tar`, regenerar las cachés y reiniciar únicamente `app`:

```bash
cd /opt/sites/jakawi.club
# Después de revisar y aplicar la reversión del código de esta entrega:
tar -xf /srv/sites/jakawi.club/rollback-landings-9364f262/build.tar app/public/build/manifest.json
docker compose exec -T --user www-data app php artisan config:cache
docker compose exec -T --user www-data app php artisan route:cache
docker compose exec -T --user www-data app php artisan view:cache
docker compose restart app
python3 scripts/validate-public.py
```

Mantener los chunks actuales y anteriores: no borrar `public/build/assets`. No ejecutar `migrate:rollback` ni restaurar el dump de rutina: eliminarían borradores o métricas posteriores. La base anterior admite la nueva columna y las tablas aditivas sin afectar usuarios ni referidos. El dump privado es una medida de recuperación manual, no el procedimiento normal.

La reversión del validador local debe acompañar la aplicación o conservar la comprobación de las seis migraciones; la migración aditiva permanece aplicada. No usar los rollbacks de publicación inicial, no retirar routers y no tocar Traefik, DNS, secretos ni otros contenedores.
