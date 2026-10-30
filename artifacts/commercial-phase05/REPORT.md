# Fase 05 — atribución comercial COM → CLUB

Implementado exclusivamente en CLUB. Contrato completo para Codex COM:
[HTTP/JSON/HMAC v1](../../docs/integrations/com-commercial-events-v1.md).

- Receptor: `POST https://jakawi.club/internal/commercial/events`.
- Panel: `https://jakawi.club/admin/conversiones-com`, enlace **CONVERSIONES COM**
  en la administración existente, con los permisos actuales de administrador activado
  y verificado. Solo lectura, filtros por partner/campaña, 25 registros por página.
- HMAC SHA-256 comercial exclusivo, timestamp ±300 s, key ID `com-commercial-v1`,
  firma de método/ruta/hash de los bytes. Sin sesiones, secretos en Git o llamadas CRM.
- Tablas aditivas `commercial_events` y `commercial_event_conflicts`: unicidad de
  origen/event_id en PostgreSQL, cuerpo original, hashes y procedencia de firma.
  Reintento igual → 200; primera recepción → 201; contenido distinto → 409,
  preservando tanto el original como la evidencia del conflicto.
- Se conservan IDs originales COM y valores ausentes como null; partner COM no
  se convierte en usuario CLUB. Código CLUB informado se verifica por existencia
  y se conserva UUID histórico. Referencia solo COM se identifica como declarada
  por COM, sin atribuirle verificación independiente.
- No se crean ni modifican usuarios, identidad/referente local, contactos FluentCRM,
  notificaciones, comisiones, pagos o saldos. Landings públicas, registro, SSO y SES
  conservan sus fuentes y configuración. Único cambio en administración de landings:
  un enlace al panel nuevo.

Pruebas y evidencia:

- `bash scripts/test-commercial.sh`: **62 comprobaciones** con kernel HTTP y
  PostgreSQL real en un esquema temporal eliminado al terminar. Incluye solicitudes
  concurrentes desde procesos distintos: un solo aceptado, duplicados y conflictos.
  Comprueba autenticación, contrato, atribución, replay tras eliminar referente,
  permisos, filtros y paginación; 0 usuarios/eventos LIVE, HTTP saliente o notificaciones.
  Resultado: [tests.json](tests.json).
- `bash scripts/build-frontend.sh`: TypeScript estricto y build Vite satisfactorios;
  dependencias existentes, sin instalaciones.
- `bash scripts/check-commercial-visual.sh`: panel a 390/1440 px, siete columnas,
  referencia original, referido verificado, filtros y estado vacío; sin errores JS,
  assets fallidos, overflow de página ni mutaciones LIVE. Datos privados sustituidos
  solo en navegador por fixtures del kernel aislado. [Resultado](browser-preview.json),
  [escritorio](commercial-preview-1440.png), [móvil](commercial-preview-390.png).
- El validador histórico `scripts/validate-local.py` todavía exige registro cerrado;
  la configuración vigente autoriza registro abierto desde la fase anterior. Su
  aserción heredada no se cambia en esta fase; se usa el verificador focalizado de
  recepción comercial para acreditar el despliegue.

Publicación mediante fast-forward/push de main y despliegue manual solo CLUB.
Migración aditiva única; secreto comercial en archivo separado montado en app;
assets anteriores conservados con manifest publicado al final; recreación solo de
app para aplicar mount y OPcache, con config/rutas/vistas regeneradas. Nginx y
PostgreSQL no necesitan reinicio. Respaldo privado de código, assets y cachés en
`/srv/sites/jakawi.club/rollback-commercial-phase05/`.

Después del despliegue: `bash scripts/check-commercial-visual.sh --live` y
`python3 scripts/verify-commercial-live.py`. El sello local ignorado
`artifacts/commercial-phase05/LIVE.json` verifica SHA de main/origin, fuentes
montadas, assets HTTPS, configuración/procedencia protegidas, rechazo sin firma,
aceptación criptográfica de una petición inválida sin insertar evento, rechazo de
bytes alterados y acceso administrativo privado. Las pruebas de panel autenticado
usan fixtures aislados; no se suplanta una sesión administrativa real.

El emisor COM sigue siendo responsabilidad de Codex COM: debe implementar el
contrato y recibir el secreto comercial por el canal privado de secretos. No se
afirma recepción de conversiones reales antes de esa integración. No se desplegó COM.
