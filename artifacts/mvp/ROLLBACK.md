# Rollback del MVP — solo jakawi.club

Copia privada previa: `/srv/sites/jakawi.club/releases/mvp-before/` (código, assets, SHA previo y dump PostgreSQL; no subir ni imprimir su contenido).

Primero activar mantenimiento con `docker compose exec -T --user www-data app php artisan down --retry=60`. Después revertir el commit del MVP con `git revert <SHA_MVP>`; nunca usar force push. Recuperar los assets previos extrayendo **solo** `app/public/build` del archivo privado `app.tar.gz` en este repositorio. Mantener vendor, node_modules, storage, secretos y base de datos.

Ejecutar en secuencia desde `/opt/sites/jakawi.club`:

```bash
docker compose exec -T --user www-data app php artisan config:cache
docker compose exec -T --user www-data app php artisan route:cache
docker compose exec -T --user www-data app php artisan view:cache
docker compose exec -T web nginx -t
docker compose exec -T web nginx -s reload
docker compose restart app
docker compose exec -T --user www-data app php artisan up
curl --fail https://jakawi.club/up
curl --fail https://jakawi.com/up
```

La migración es aditiva y puede permanecer aplicada con el código anterior. **No ejecutar migrate:rollback ni restaurar el dump sobre datos nuevos.** El dump es una contingencia privada de recuperación y no forma parte del rollback normal. La reversión de esquema eliminaría identidad y atribuciones; requiere una decisión específica posterior. La contraseña permanece nullable incluso en `down()` para no inventar credenciales.

No tocar Traefik, DNS, certificados, otras redes ni contenedores ajenos. El registro sigue cerrado con la configuración por defecto. Después del rollback verificar portada y assets, y publicar el commit de reversión si corresponde.
