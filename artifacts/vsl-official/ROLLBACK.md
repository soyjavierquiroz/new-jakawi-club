# Rollback del VSL oficial — solo jakawi.club

Baseline: `f485ba92f585fbf80984af3df7a2a5ecc2f7e828`. No ejecutar automáticamente.

1. Revertir el commit de esta entrega con `git revert` en `new-jakawi-club`, conservando cualquier trabajo posterior. No usar reset ni push forzado.
2. Reconstruir en secuencia con `bash scripts/build-frontend.sh`. Copiar los chunks de `app/public/build-next/` a `app/public/build/` conservando todos los anteriores y publicar `manifest.json` mediante reemplazo atómico al final. Durante esta operación, el manifest previo también está conservado en `artifacts/vsl-official/previous-build/manifest.json`, ignorado en Git.
3. Reiniciar únicamente `jakawi-club-app` si hace falta renovar OPcache. No recrear Nginx/PostgreSQL ni cambiar Traefik, DNS o secretos.
4. Verificar `/`, `/principal`, alias `/l/principal`, registro cerrado con CSRF, assets y ambos `/up` HTTP 200.

No hay migraciones ni cambios en registros de usuarios/landings que revertir. Los chunks nuevos se pueden conservar para sesiones abiertas. El Short aprobado conserva cualquier restricción de inserción impuesta por YouTube; el rollback de interfaz no altera esa autorización.
