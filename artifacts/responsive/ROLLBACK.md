# Rollback responsive

Baseline LIVE verificado: `228659790c781ca9ec6dd1570e3f69809c96fb3f`.
Respaldo local: `/srv/sites/jakawi.club/rollbacks/responsive-2286597/`, con código Git, build anterior y sellos locales previos. No contiene secretos ni datos de usuarios.

Solo si se solicita revertir:

1. Revertir el commit responsive mediante un nuevo commit en main, conservando el historial. No restablecer ni borrar datos.
2. Restaurar los assets de `build/` del respaldo conservando los chunks actuales; sustituir el manifest al final de forma atómica. Alternativa: reconstruir el commit revertido mediante `bash scripts/build-frontend.sh` y publicar de la misma manera.
3. Reiniciar únicamente `docker compose restart app` para renovar OPcache. Estos cambios no requieren regenerar configuración/rutas ni ejecutar migraciones.
4. Esperar app saludable; comprobar portada, assets HTTPS, /up, POST /registro cerrado con CSRF válido, login/dashboard y https://jakawi.com/up = 200. Sin tocar Traefik, Compose, PostgreSQL ni otros contenedores.
5. Publicar el commit de reversión sin push forzado y emitir un sello LIVE ajustado a la versión restaurada. No reutilizar expectativas visuales 3:4 para el baseline anterior 16:9.

Rollback preparado; no ejecutado.
