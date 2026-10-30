# Afiliados CLUB — MVP

Panel privado: `https://jakawi.club/afiliados`, desde **MIS ENLACES** y **MIS
CONVERSIONES** en `/dashboard`. Exige autenticación, correo verificado y activación
CLUB; usa la elegibilidad local existente. No provisiona identidad COM ni cambia
sus permisos comerciales. La campaña inicial es **Dossis 43 — Café y antojo**.

El enlace es `https://jakawi.com/l/dossis-43-cafe-y-antojo?club_ref=` seguido del
`users.referral_code` real de la cuenta autenticada. El mecanismo de creación de
usuarios existente emite ese código; la migración original también completó las
cuentas anteriores. PostgreSQL exige código único, no nulo y permanente. No hay
usuarios elegibles sin código en el despliegue. No se sustituye ni se genera un
código temporal al consultar el panel. Copiar utiliza Clipboard API con selección
manual si el navegador deniega acceso; WhatsApp abre el compositor con el enlace
completo y el nombre del partner, sin enviar automáticamente mensajes.

## Conversiones

Se consulta la tabla existente `commercial_events`; no se duplica ni modifica.
La clave de operación es `(source, com_reference_type, com_reference_id)`. Se elige
el último evento COM por `occurred_at`, luego `received_at` e `id` para desempatar.
Después se exige `conversion_status=confirmed`, `club_referral_code` propio y
`referrer_club_uuid` propio verificado por el receptor. No se usan email,
`global_subject_id` ni referencias COM sin código CLUB para atribuir.

Una operación produce una sola fila aunque tenga varios eventos. Un estado más
reciente cancelado o pendiente deja de contar como confirmado; una entrega tardía
con fecha de negocio anterior no revive la conversión. Un evento posterior con
otra atribución o sin atribución no permite contar el evento anterior para otro
afiliado. No se infieren códigos ausentes. El contador total incluye todas las
operaciones COM confirmadas propias, no solo la campaña destacada; la interfaz
lo indica. Paginación de 25 operaciones, hora de Bolivia y ausencia de partner
explícita. El panel administrativo `/admin/conversiones-com` sigue mostrando
los eventos históricos originales y mantiene sus permisos existentes.

No hay importes, comisiones, saldos, pagos ni nuevas obligaciones económicas.
No se modifica COM (SHA comunicado por el propietario:
`55bfc36c8fde1724a535b88c29065d0faef66b04`), CRM, SES, SSO ni las landings.

## Pruebas y despliegue

- `bash scripts/test-affiliates.sh`: kernel HTTP y receptor HMAC reales con
  esquema PostgreSQL temporal; afiliados distintos, enlace, atribución,
  estados, reintentos, conflictos, orden temporal, aislamiento y paginación.
- `bash scripts/test-commercial.sh`: regresión focalizada del receptor y admin.
- `bash scripts/build-frontend.sh`: TypeScript y Vite, secuenciales y limitados.
- `bash scripts/check-affiliates-visual.sh`: fixtures del kernel solo en navegador,
  navegación privada, copiar, fallback, enlace WhatsApp, datos, vacío y responsive
  a 390/1440 px. Después de publicar repetir con `--live` para los assets LIVE.
- `python3 scripts/verify-affiliates-live.py`: SHA Git/origin, código montado,
  assets HTTPS, `/up`, paneles protegidos y receptor HMAC con JSON incompleto
  rechazado (no inserta eventos). Sello ignorado `artifacts/affiliate-mvp/LIVE.json`.

Implementación revisada en worktree aislado. Publicar mediante fast-forward de
`main` y push normal. Sin migraciones ni cambios de compose. Copiar los assets
conservando chunks anteriores y publicar el manifest al final; regenerar cachés
y reiniciar únicamente `app` para renovar OPcache. Respaldo privado:
`/srv/sites/jakawi.club/rollback-affiliate-mvp/` (código, build y baseline).
Rollback: restaurar ese código/manifest, regenerar cachés y reiniciar solo `app`;
conservar datos y eventos. No fabricar conversiones ni cuentas LIVE.
