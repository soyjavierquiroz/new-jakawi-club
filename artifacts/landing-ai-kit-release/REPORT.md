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

Acceso: `/admin/landings`, bloque **Crear con IA**; también en el editor junto a
Importar/Exportar. Descargas autenticadas en `/admin/landings/crear-con-ia/`
con `prompt`, `example`, `schema` o `kit`. Los tres documentos y el ZIP contienen
solo archivos oficiales, sin datos de usuarios ni campañas.
