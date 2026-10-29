# Rollback de formulario y posconversión — solo jakawi.club

Baseline: `c315fe625b402fc2408f4d5ccb983ecc4e2a0908`. Assets anteriores y baseline privado: `/srv/sites/jakawi.club/rollback-postconversion-c315fe625b40/`. Código anterior disponible en Git. Los chunks previos siguen en `app/public/build/`.

1. Revisar y revertir el commit funcional `9f47397655da3fba5afe7c803f15b75207d1b553` mediante `git revert`, conservando cambios posteriores. Commit/push normal; nunca reset ni push forzado.
2. Sin despliegues posteriores, restaurar el manifest anterior atómicamente. Si los hubo, reconstruir el frontend de la reversión con `bash scripts/build-frontend.sh` y publicar sus chunks/manifest conservando los existentes.
3. Regenerar rutas y reiniciar solo `app`:

```bash
cd /opt/sites/jakawi.club
cp /srv/sites/jakawi.club/rollback-postconversion-c315fe625b40/build/manifest.json app/public/build/manifest.rollback.json
mv app/public/build/manifest.rollback.json app/public/build/manifest.json
docker compose exec -T --user www-data app php artisan route:cache
docker compose restart app
python3 scripts/validate-local.py
python3 scripts/validate-public.py
```

No hay migraciones ni datos LIVE que restaurar. El código anterior ignora los campos aditivos de configuración si posteriormente se guardaron borradores; no borrar esos datos. Las sesiones de gracias son temporales. Mantener registro cerrado y `APP_DEBUG=false`. No restaurar PostgreSQL, cambiar secretos, retirar routers ni reiniciar Nginx, PostgreSQL o Traefik. No tocar jakawi.com ni otros proyectos.
