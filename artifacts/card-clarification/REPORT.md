# Simplificación de tarjetas V3 — CLUB

`condition` conserva su nombre y contrato JSON V3. El editor muestra **Aclaración (opcional)**, sin `required`, y el backend admite ausencia, vacío, null o texto aunque exista métrica. Estado de oferta continúa opcional.

La aclaración solo se renderiza si tiene contenido: 13 px, peso 400, color secundario y margen discreto, sin espacio reservado cuando está vacía. Se corrige la especificidad de la regla WEBINAR que agrandaba todos los párrafos. El componente compartido por público y preview elimina porcentajes repetidos al combinar `metric.value` con unidad `%`; no reescribe contenido guardado ni unidades Bs.

Validación focalizada: 53 comprobaciones HTTP/JSON V3 con esquema PostgreSQL temporal eliminado; borrador, preview y publicación aislados, con/sin aclaración y estado, datos V3 anteriores, porcentajes y Bs. TypeScript y Vite PASS. Navegador 390/1440: porcentaje único en 15/30/50, condición ausente y presente, 13 px/400, campos opcionales y preview. Resultados en `tests.json`, `build.json` y `browser.json`.

Despliegue autorizado únicamente CLUB desde baseline `0b0a5c1`: código y assets, chunks anteriores preservados, backup de SHA/manifest en `/srv/sites/jakawi.club/rollback-card-clarification`. Reinicio exclusivo de `app`; sin migraciones ni publicación editorial. Hash de configuración antes/después idéntico. SHA y resultado en `LIVE.json`.

Rollback: desde `/opt/sites/jakawi.club`, volver al `previous_sha` de `release.json` en el backup indicado, restaurar su `manifest.json` en `app/public/build/manifest.json` y ejecutar `docker compose restart app`. Conservar assets y datos.
