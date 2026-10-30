# MVP de afiliados CLUB

Panel: `https://jakawi.club/afiliados`, desde el dashboard privado. Campaña inicial:
**Dossis 43 — Café y antojo**, con enlace personal construido desde el código real
permanente del usuario CLUB. Copiar, selección alternativa y compositor WhatsApp.

La vista muestra fecha Bolivia, partner, tipo, estado confirmado y referencia
comercial. Solo código y UUID de referente CLUB verificados; sin atribución por
email o identidad global. Una fila por operación COM, tomando su último estado
por fecha del negocio. Cancelaciones y entregas fuera de orden cubiertas.
Contador total de operaciones confirmadas propias y paginación de 25. El admin
original conserva los eventos históricos y sus permisos.

Validación completada antes de publicar:

- 64 comprobaciones HTTP en PostgreSQL temporal: enlace, elegibilidad, dos
  afiliados, receptor firmado, sin código, sin duplicados, cambios de estado,
  privacidad, navegación y paginación. Cero cuentas/eventos LIVE creados.
- 62 comprobaciones de regresión del receptor comercial, concurrencia y admin.
- TypeScript y Vite correctos con las dependencias existentes y límites propios.
- Chromium a 390/1440 px: navegación, enlace completo, copiar, fallback,
  compositor WhatsApp, conversiones, hora Bolivia y estado vacío; sin errores JS,
  assets fallidos ni desbordamiento. Fixtures solo del navegador, sin sesión LIVE.

Evidencias: `tests.json`, `browser-preview.json`, `affiliate-preview-*.png`.
El cierre operativo usa `check-affiliates-visual.sh --live` y
`verify-affiliates-live.py`; el SHA exacto, checks HTTPS y estado de `/up` se sellan
en `LIVE.json` ignorado. No se identifica la ilustración de fixtures como una
venta real: al iniciar el trabajo no existían eventos comerciales LIVE.

Sin migraciones, dependencias nuevas ni cambios de compose. Solo CLUB; no cambia
COM, CRM, SES, SSO ni landings. No comisiones, saldos ni obligaciones económicas.
[Contrato y operación](../../docs/integrations/club-affiliates-mvp.md).
