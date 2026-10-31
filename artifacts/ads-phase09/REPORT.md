# Fase 09 — publicidad propia de CLUB

Las cuatro rutas `/ads/{meta|tiktok}/{membresia|activar}` reutilizan controladores, CMS, componentes, QR e intención existentes. Noindex en HTML/HTTP y canonical orgánico. El CTA conserva proveedor; POST y conciliación conservan `club_reference`. Orgánico no carga SDKs publicitarios. Al salir de rutas autorizadas se elimina el runtime mediante navegación de documento.

Preferencia publicitaria explícita e independiente en `/preferencias`, accesible en Ads y Privacidad. Sin consentimiento no se almacenan identificadores de campaña/clic ni se crean visitas publicitarias. La ruta y las UTMs no autorizan SDKs. Referidos y registration_origin no se modifican. Visitas y payloads cifrados con Laravel; identificadores externos leídos sin manipular la cookie segura de consentimiento. Retención configurable 1–90 días, 30 por defecto, limpieza con scheduler CLUB existente. Revocación por ámbito de consentimiento compartido entre visitas y plataformas bloquea browser y jobs, incluyendo compras invitadas; no retira eventos ya recibidos por proveedores.

Lead/CompleteRegistration nace después de persistir datos de comprador en la intención. InitiateCheckout nace una vez dentro de la creación idempotente. Purchase nace exclusivamente del receptor HMAC `membership.activated`: nunca por sync HTTP, QR, comprobante, review ni identity_linked. Antes de entregar Purchase se confirma importe aprobado en COM por su referencia estable; se utiliza BOB y fecha original firmada. Nunca se atribuye por email. La unicidad de payment_reference existente y `(provider, club_reference, event_name)` evita una segunda compra por pago. No se alteran contratos ni servicios COM.

ID UUID persistido compartido entre browser y server; nombres idénticos por proveedor. PageView/Pageview exclusivamente browser. Meta CAPI y TikTok Events API directas vía Laravel HTTP, sin SDK backend ni dependencias nuevas. Email SHA-256 lowercase/trim; teléfono Meta solo dígitos y TikTok E.164 con `+`, ambos SHA-256. No se envían comprobantes, referencias bancarias, claim_secret ni credenciales del comprador.

Outbox mínimo en PostgreSQL y jobs Laravel en `ads`, atendidos por el worker/timer CRM existente (`crm,ads`). Estados received, pending, sent, accepted, rejected; intentos, código HTTP/código numérico del proveedor, sin guardar cuerpos o mensajes externos. Reintentos temporales acotados y fecha original inmutable. Lectura/creación publicitaria aislada con savepoints para que un error no revierta la compra. No hay servicio, contenedor persistente ni motor de eventos nuevo.

## Configuración pendiente

Solo en `/srv/sites/jakawi.club/secrets/app.env`, credenciales propias CLUB:

- `META_PIXEL_ID`, `META_CAPI_ACCESS_TOKEN`, `META_ADS_ENABLED=false`.
- `TIKTOK_PIXEL_ID`, `TIKTOK_EVENTS_ACCESS_TOKEN`, `TIKTOK_ADS_ENABLED=false`.
- `ADS_PRIVACY_READY=false`, `ADS_RETENTION_DAYS=30`.

Los flags y readiness predeterminados son false. No se configura ningún secreto durante esta entrega. Para habilitar posteriormente: credenciales CLUB, revisión de preferencias/texto de privacidad y ajustes de eventos automáticos/advanced matching de cada Pixel, flags y readiness explícitos; recrear solo app para leer env_file, config:cache y reiniciar solo app según README. Nunca usar credenciales de otros proyectos. Eventos anteriores a siete días no se transmiten. Sin readiness/credenciales no hay llamadas reales. Verificación externa y deduplicación en ambos Events Manager quedan pendientes de credenciales; no se afirman probadas en plataformas.

## Verificación y operación

`bash scripts/test-ads.sh`: runner existente de checkout con migraciones y esquema PostgreSQL temporal; APIs fake, cero fixtures/pagos bancarios LIVE. `bash scripts/build-frontend.sh`: TypeScript y Vite secuenciales con límites existentes. `bash scripts/check-ads.sh`: Chromium a 390/1440, scripts de proveedores simulados, aislamiento por consentimiento/proveedor, ID browser/server y navegación Inertia. Evidencia en tests.json/browser-preview.json.

Preparación: `python3 scripts/prepare-ads-release.py`. Commit/push antes de `bash scripts/deploy-ads.sh`: migración aditiva exclusiva Ads, manifest al final, cachés y reinicio solo app. Validar `JAKAWI_BASELINE_PATH=/srv/sites/jakawi.club/rollback-ads-phase09/baseline.json JAKAWI_EVIDENCE_DIR=/opt/sites/jakawi.club/artifacts/ads-phase09 python3 scripts/validate-local.py`, navegador `bash scripts/check-ads.sh --live` y `python3 scripts/verify-ads-live.py`. LIVE.json excluido de Git para evitar autorreferencia.

Rollback: conservar migración y datos, recuperar código del SHA baseline y manifest privado de `/srv/sites/jakawi.club/rollback-ads-phase09`, regenerar config/rutas/vistas y reiniciar solo app. No borrar tablas ni revertir pagos. No tocar Traefik ni otros proyectos.

Fuentes oficiales consultadas: [Meta CAPI](https://developers.facebook.com/docs/marketing-api/conversions-api/using-the-api/), [SDK oficial Meta](https://github.com/facebook/facebook-python-business-sdk), [TikTok Events API](https://business-api.tiktok.com/portal/docs?id=1771100779668482), [TikTok normalización](https://business-api.tiktok.com/gateway/docs/index?doc_id=1771100865818625), [TikTok Pixel](https://ads.tiktok.com/gateway/docs/index?doc_id=1701890973258754).

Resultado antes del despliegue: 81 comprobaciones backend y 8 escenarios de navegador (390/1440 px), TypeScript y build satisfactorios. Proveedores siempre simulados en pruebas.
