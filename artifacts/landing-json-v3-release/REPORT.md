# Integración y despliegue del importador JSON v3 — CLUB

Original: `b36e8dcbaaf05c55d41bc59a22367cd8633e7755`.
Cherry-pick: `ad8905f40b43669899f5250d9f0a8bd7c829af3a`.
Integración sobre main vigente: `fca4a3b86821edf66c532b250847ad2f4dfc2f84`.
Base LIVE previa: `e1368f662cc0f12db63d6f57ebadc5825ced5b49`.
El SHA final LIVE se registra en `LIVE.json`, excluido de Git para evitar autorreferencia.

## Integración y validación previa

La comprobación inicial encontró main/origin/main en `45b26e7` y cambios comerciales
ajenos sin commit. Mientras se preparaba la integración aislada, esa entrega se
confirmó como `1bdcae6` y terminó desplegada/sellada en `e1368f6`. Se incorporaron
ambos commits antes de avanzar, sin editar, guardar ni descartar cambios ajenos.
El único conflicto fue la adición simultánea de reglas en `.gitignore`; se
conservaron ambas listas. No hubo conflictos materiales de aplicación.

Respecto de la base vigente solo cambian los cuatro archivos de aplicación v3:
`LandingContent.php`, `LandingAdminController.php`, `landing-editorial.ts` y
`LandingEditor.tsx`. Se conservan catálogo, resultados, clics, comisiones, enlaces
cortos, identidad y registro. Los dos documentos oficiales se conservan byte a
byte respecto del commit original en `docs/landings/`.

Pruebas secuenciales y acotadas, con esquemas PostgreSQL temporales y servicios
externos simulados:

| Comprobación | Resultado |
| --- | --- |
| Importador v3, v1/v2, WEBINAR, snapshots y previews | 379 PASS |
| Registro, confirmación, atribución y recibos | 134 PASS |
| Catálogo | 75 PASS |
| Resultados/clics de afiliados | 64 PASS |
| Comisiones | 82 PASS |
| Receptor comercial | 62 PASS |
| Total PHP | **796 PASS** |
| TypeScript y Vite en contenedores limitados | PASS |
| Chromium con build preparado | **15 grupos PASS** |

Evidencia: [tests.json](tests.json) y [browser-results.json](browser-results.json).
Los fixtures y capturas no se versionan. No se instalaron dependencias ni se
ejecutaron benchmarks. Los commits posteriores a las pruebas solo añaden
documentación/evidencia de despliegue, sin cambiar los archivos de aplicación.

## Operación

Preparación completada; despliegue pendiente en este punto del historial.
Rollback exclusivo CLUB preparado en `/srv/sites/jakawi.club/rollback-landing-json-v3/`:
archivo de fuente previo, assets, cachés y baseline de hashes. Sin copias de secretos
al repositorio ni contenido de usuarios en la evidencia.

Procedimiento previsto: avance de main mediante fast-forward, copia de chunks
nuevos conservando todos los anteriores, cambio atómico de manifest, regeneración
de cachés y reinicio exclusivamente de `app`. Sin migraciones, sincronización
comercial, cambios de secretos ni modificaciones de infraestructura.

`check-release.py` realiza la verificación de solo lectura del runtime: importación
del ejemplo al formulario en memoria, exportación de una landing existente sin
escribirla, roundtrip, disponibilidad del editor y registro abierto. Compara
landings/snapshots, tablas protegidas, fuentes ajenas, secretos, contenedores,
documentos oficiales y assets HTTPS con el baseline. No crea sesiones de prueba
administrativas, registros de personas, publicaciones ni correos.
