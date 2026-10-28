# Rollback del video oficial

Baseline LIVE: `9c81ffcba1bc47e4e8878e127b50dca3a62ae01c`.
Respaldo exclusivo: `/srv/sites/jakawi.club/rollbacks/vsl-9c81ffc/`, con código Git, assets anteriores y sellos operativos previos. No se copiaron archivos de secretos ni datos de usuarios.

Solo si se solicita revertir:

1. Revertir el commit de publicación VSL mediante un nuevo commit, conservando el historial.
2. Restablecer **solo** `JAKAWI_VIDEO_URL` a vacío en `/srv/sites/jakawi.club/secrets/app.env` (el baseline no tenía video aprobado). Mantener permisos y todos los demás parámetros. No sustituir el archivo completo ni mostrarlo en consola.
3. Restaurar assets desde `build/` del respaldo, conservando chunks actuales y sustituyendo el manifest al final de forma atómica.
4. Ejecutar `docker compose up -d --no-deps --force-recreate app`, después `docker compose exec -T --user www-data app php artisan config:cache` y `docker compose restart app`. Es necesario recrear app para retirar la variable previamente inyectada por Compose.
5. Verificar servicios propios saludables, portada sin reproducción ficticia, assets HTTPS, ambos `/up` = 200 y registro cerrado. Emitir un sello LIVE para el código y configuración restaurados; el sello actual exige el video aprobado y no debe reutilizarse para certificar un rollback sin video.

No ejecutar migraciones, tocar Compose/Traefik/PostgreSQL ni reiniciar contenedores ajenos. Rollback preparado; no ejecutado.
