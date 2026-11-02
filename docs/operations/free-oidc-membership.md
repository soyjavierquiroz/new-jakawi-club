# Acceso gratuito OIDC y estado efectivo de membresía

## Causa comprobada y límite del diagnóstico histórico

LIVE anterior: `b085d1f9f0be8bcaf97ab6a61be23fef0055f319`.
COM confirmado por el propietario: `1f23a8e69d33cb978b8d2077cc62885da987f38c`.
Su contrato `docs/integrations/club-membership-status-v1.md` se consultó por Git en solo lectura.

`SsoAccount::resolve()` rechazaba estrictamente cualquier `email_verified` distinto de `true`, lanzando `RuntimeException('Verified SSO profile required')` antes de persistir la proyección. `JakawiLoginController::callback()` atrapaba la excepción sin registrar motivo y redirigía a login con el mensaje genérico. Además, el callback exigía `hasVerifiedEmail()` dos veces y `/dashboard` y `/mi-cuenta` tenían middleware `verified`.

El runner carga el archivo exacto del commit LIVE anterior, reproduce su excepción con el mismo perfil gratuito/no verificado y después recorre el kernel HTTP real con JWT RS256, nonce, sesiones y PostgreSQL aislado. Antes del cambio el primer guard impide la proyección; después autentica sin compras. No era un rechazo de membresía ni de pago.

La evidencia histórica de CLUB no conservaba el motivo de cada callback ni el sub rechazado. No se dispone de una nueva sesión interactiva real de Drex ni de su UUID confirmado por el propietario. Por ello no se presenta la reproducción como un login LIVE de Drex, ni se atribuye a él una identidad por nombre, correo o proximidad temporal. Para cerrar la comprobación individual, Drex debe reintentar desde COM y confirmar el resultado. No se fabrican códigos, tokens ni sesiones reales.

## Comportamiento

- Identidad exclusivamente por el `sub` firmado COM. Bloqueo transaccional y unicidad existentes; no vincular por email. Una colisión deja email local ausente y sin verificación.
- El email no verificado no impide OIDC ni leer la cuenta. No se inventa `email_verified_at`; un claim posterior `true` solo verifica el mismo correo de la misma identidad.
- Verificación y autorización conservadas en administración, afiliados, edición local, vinculación y operaciones sensibles. Se conservan los controles históricos de vínculo para identidades originadas en CLUB. No se alteran cuentas desactivadas.
- Perfil personal enlazado a `https://jakawi.com/perfil`.
- Consulta HMAC existente por UUID almacenado: `POST /internal/club/memberships/status`. Sin claves nuevas, sin sujetos del navegador, una llamada acotada, sin caché de concesiones. Autenticación independiente de esta consulta.
- `/mi-cuenta`: Gratuito, Estándar y Pionero; estado, fechas efectivas y política oficial Pionero +15 meses desde lanzamiento. Sin calcular ni inventar la fecha oficial.
- Solo estado activo con beneficios exclusivos explícitos habilita su enlace COM. Error, sujeto distinto o respuesta inválida no concede beneficios ni recomienda una recompra. Activación histórica no sustituye vigencia COM para cuentas vinculadas.
- Origen/beneficiario Dossis conservado en las tablas de adquisición originales. El login y la lectura no emiten eventos comerciales, altas CRM, compras ni comisiones.
- Diagnóstico futuro: solo etapa local acotada ante excepción del callback; nunca excepción, token, código, email, sujeto o respuesta remota en logs.

## Validación focalizada

`JAKAWI_EVIDENCE_DIR=/srv/sites/jakawi.club/free-oidc-evidence bash scripts/test-free-oidc.sh`: 45 comprobaciones, esquema PostgreSQL temporal eliminado, proveedor simulado con JWT RS256 reales. Gratuito no verificado, retorno normal/Partner, identidad repetida, colisión/cambio de email, verificación posterior, nonce inválido, HMAC, Pionero/Estándar/vencido/programado, caída COM, sujeto distinto, origen inmutable, cero comisiones/compras/CRM de prueba LIVE.

`JAKAWI_EVIDENCE_DIR=/srv/sites/jakawi.club/free-oidc-evidence/partner bash scripts/test-partner-sso.sh`: 153 comprobaciones del flujo Partner y permisos económicos existentes. Se corrige la expectativa del caso de colisión de email: el correo ausente ya no figura verificado y el middleware sensible lo detiene antes del permiso administrativo.

`bash scripts/build-frontend.sh`: TypeScript y Vite secuenciales, contenedores limitados, dependencias propias de la fuente canónica. Sin reinstalación.

`scripts/check-free-oidc.sh` y `--live`: 10 escenarios visuales, cinco estados a 390/1440 px, fixtures de páginas privadas; sin mutaciones LIVE ni sesión real Drex. Evidencia privada en el directorio anterior.

## Despliegue manual autorizado

Trabajar en `fix/free-oidc-membership`, sin fusionar `main`. Empaquetar con `scripts/package-source-release.sh`; aplicar explícitamente `scripts/deploy-free-oidc.sh <paquete>` tras comprobaciones. LIVE queda en checkout detached del commit revisado; los bind mounts permanecen en `/opt/sites/jakawi.club`. Assets nuevos antes del manifest atómico, chunks anteriores conservados; regenerar rutas/vistas y reiniciar exclusivamente `app`. Sin migraciones, cambios de entorno, comandos económicos, cambios COM/CRM ni infraestructura compartida.

Backup: `/srv/sites/jakawi.club/rollback-free-oidc/{release,manifest}.json`. Rollback manual: checkout del `previous_sha` del respaldo, restaurar atómicamente su manifest, `route:cache`, `view:cache`, reiniciar solo `app` y ejecutar el validador local. Conservar todos los chunks y datos.

Validación posterior: `python3 scripts/validate-local.py`, navegador `--live`, `/up` local/HTTPS, manifiesto y SHA efectivo, snapshot de cuentas/orígenes/ledger antes/después. Sellos privados fuera de Git para evitar autorreferencia. La confirmación individual de Drex queda pendiente de su nuevo acceso real.
