# Kit IA — despliegue CLUB

Original: `d852ef21325d8dcbb8ba6914e65ae70114acf5f3`.
Base main/origin/main/LIVE: `9663f542ff6a5115c931520ab7d51786a350188b`, limpia.
Integración por fast-forward; sin conflictos ni cambios ajenos pendientes.

Pruebas previas repetidas: **419 PASS**, TypeScript y Vite **PASS**. La implementación
incluye **18 grupos de navegador PASS**. Sin dependencias nuevas ni cambios del
contrato, ejemplo, importador, registro, servicios externos o datos LIVE.

Rollback preparado en `/srv/sites/jakawi.club/rollback-landing-ai-kit/`.
Despliegue: montaje de documentos de solo lectura, assets conservando chunks,
cachés y recreación exclusiva de `app`. Sin migraciones ni cambios de secretos.
El SHA final y la verificación de integridad quedan en `LIVE.json` (ignorado).
Resultados resumidos: `tests.json`; ejecución en `deployment.json` al desplegar.

Despliegue completado: mantenimiento **6,39 s**, 19 archivos nuevos de assets,
montaje aplicado y recreación exclusiva de `app`. Chromium con assets LIVE:
**21 grupos PASS**, incluidos listado/editor, cuatro descargas visibles, fallback
sin ZIP, importación v3, previews y portada con registro abierto. Las mutaciones
administrativas del navegador se interceptan; no se modificó contenido editorial.
Afiliados/resultados con assets LIVE a 390/1440: PASS, sin errores JS ni assets
fallidos. `validate-local.py` pasó hasta la comparación del baseline histórico,
que rechaza el cambio de ID de `app`; es esperado por la recreación autorizada del
montaje. No se modifica ese baseline ni se fuerza el test. `verify.py` comprueba
el montaje real, salud de los tres servicios, web/db sin cambios, hashes de datos
protegidos y secretos, descargas/ZIP y assets HTTPS contra el baseline de esta entrega.

Acceso: `/admin/landings`, bloque **Crear con IA**; también en el editor junto a
Importar/Exportar. Descargas autenticadas en `/admin/landings/crear-con-ia/`
con `prompt`, `example`, `schema` o `kit`. Los tres documentos y el ZIP contienen
solo archivos oficiales, sin datos de usuarios ni campañas.
