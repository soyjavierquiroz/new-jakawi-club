# Rollback del formulario simplificado — solo jakawi.club

Baseline: `d8cadaec85de70fc431b504b409f90c7a56a7bd0`. Copia privada de código y manifest anterior: `/srv/sites/jakawi.club/rollback-registration-d8cadaec85de/`. No contiene secretos ni base de datos. Todos los chunks anteriores se conservan en `app/public/build/assets/`.

1. Revisar y revertir el commit de esta entrega mediante `git revert`; conservar trabajo posterior. Publicar la reversión con push normal a `origin/main`. No usar reset ni push forzado.
2. Si no hubo despliegues posteriores, restaurar atómicamente el manifest anterior con el comando siguiente. Si hubo cambios posteriores, reconstruir la reversión mediante `bash scripts/build-frontend.sh` y publicar sus chunks y manifest conservando los anteriores.

```bash
cd /opt/sites/jakawi.club
cp /srv/sites/jakawi.club/rollback-registration-d8cadaec85de/manifest.json app/public/build/manifest.rollback.json
mv app/public/build/manifest.rollback.json app/public/build/manifest.json
docker compose restart app
python3 scripts/validate-local.py
python3 scripts/validate-public.py
```

El archivo privado `code.tar` permite recuperar archivos concretos si Git no está disponible; no extraer indiscriminadamente sobre cambios posteriores. No hay migraciones ni escrituras de cuentas/landings que revertir. No restaurar PostgreSQL, modificar secretos, abrir registros, retirar routers ni reiniciar servicios compartidos. No tocar jakawi.com, Nginx, PostgreSQL, Traefik o DNS.
