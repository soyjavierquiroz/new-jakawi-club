# Rollback de la landing

Baseline: `b4527e06012ddd1e9de769b21282a232fb9a0a99`.
Copia local preparada antes de editar: `/srv/sites/jakawi.club/rollbacks/conversion-b4527e0/` con `baseline.tar` (solo Git) y `build/` (manifest y assets anteriores). No contiene secretos ni copias de usuarios.

Aplicar únicamente si se solicita volver a la landing anterior:

1. Revertir el commit de implementación en una nueva revisión de `main`, conservando la migración aditiva `2026_10_28_000002_create_marketing_event_totals.php`. Conservar también la expectativa de cinco migraciones de `scripts/validate-local.py`; la tabla queda sin uso. No ejecutar `migrate:rollback`, no borrar totales ni modificar usuarios.
2. Mantener todos los chunks existentes y copiar los assets anteriores desde el respaldo. Reemplazar el manifest por el del respaldo al final, de forma atómica.
3. Regenerar `config:cache`, `route:cache` y `view:cache` con el usuario `www-data` del servicio `app`.
4. Reiniciar solamente `app` para renovar OPcache y esperar su estado `healthy`.
5. Comprobar `/`, `/up`, assets, cierre de `/registro` y continuidad de `https://jakawi.com/up`. Publicar el commit de reversión sin push forzado.

No tocar DNS, Traefik, Nginx, PostgreSQL ni otros contenedores. Los datos reales, atribuciones y credenciales permanecen en sus rutas originales. El rollback no se ejecutó.
