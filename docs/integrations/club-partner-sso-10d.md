# Fase 10D — acceso empresarial desde la única cuenta JAKAWI

`GET /afiliados` inicia el OIDC existente si falta sesión CLUB. La callback conserva
Authorization Code + PKCE, RS256/JWKS, issuer/audience, nonce, state y consumo único.
El sujeto firmado resuelve exclusivamente `users.global_subject_id`. Si no existe,
se crea automáticamente su proyección interna: sin contraseña, formulario, handoff
CLUB→COM, lead, atribución, aceptación de privacidad inventada ni mensaje CRM.
Requiere perfil COM con correo verificado. Una coincidencia de correo no enlaza
cuentas: se conserva la cuenta histórica y la proyección puede tener email nulo.
La migración permite ese caso y registra `sso_provisioned_at`; el sujeto sigue
siendo único e inmutable. Las cuentas existentes se reutilizan por sujeto.

La sesión conserva solo el sujeto acreditado por SSO, nunca roles ni permisos.
El login local lo elimina; una sesión local puede acreditar SSO mediante
`/afiliados/sso`, siempre con la misma identidad global. Las cuentas originadas en
COM no reciben la acción de vinculación duplicada del dashboard. Los chequeos
históricos del handoff de cuentas CLUB se mantienen en su login ordinario.

## Autoridad y validación

`PartnerEconomicAccess` llama desde Laravel a
`POST https://jakawi.com/internal/club/partners/access`, reutilizando
`ClubPurchaseClient` y `CLUB_PURCHASE_HMAC_SECRET`/`club-purchases-v1`.
Contrato COM desplegado: `34adc6a98bb94d6831fcd6b0a2e6153ba0517256`.
Firma exactamente método, path y SHA256 de bytes JSON; timestamp y nonce UUID
nuevos. Sin redirects, cookies, reintentos automáticos ni secretos frontend.

Solo consulta el sujeto de la sesión SSO, correlacionado con el usuario autenticado.
Valida HTTP 200, JSON, sujeto exacto, lista, tipos estrictos y IDs sin duplicados.
Admite owner/manager con `economic_access === true` y estado `published`.
Staff y cualquier otro estado quedan fuera. Cruza IDs por `source=jakawi.com` y
`partner_id` con referencias CLUB `enabled`; nunca usa email ni nombre comercial.

Cada petición protegida a `/afiliados`, incluidas paginación y recargas parciales
Inertia, reconsulta COM. Solo memoiza en atributos de esa solicitud HTTP. No existe
caché durable de autorización. Error, revocación, ausencia o respuesta inválida
retiran los resultados empresariales; el área personal sigue disponible.
Las props empresariales se incluyen siempre en recargas parciales para retirar
cualquier valor anterior. Respuestas privadas `no-store`.

Los resultados y enlaces empresariales solo se leen después de la autorización.
No se añadieron endpoints económicos separados ni escrituras de representantes.
Los endpoints administrativos conservan su guard exclusivo de administración.
La antigua asignación manual devuelve 410 y nunca concede permisos; sus filas
históricas se conservan y son solo informativas en administración.

## Experiencia y alcance

Resultados empresariales en sección propia, selector para varios establecimientos
autorizados y resultados personales separados por los beneficiarios existentes.
`/admin/partners-afiliados` conserva habilitación, enlaces y acceso a reglas.
Dossis 43 sigue siendo `jakawi.com` + `1`, sin cambiar su beneficiario.
No cambia checkout, com_origin, eventos, atribución, ledger, reglas, CRM o medios.

## Verificación y operación

- `scripts/test-partner-sso.sh`: kernel HTTP y PostgreSQL en esquema temporal,
  proveedor OIDC simulado con JWT RS256 firmado y validado por el cliente real;
  roles, fallos, revocación, SSO, identidad, rutas y recargas parciales.
- `scripts/test-partners.sh`: regresión de atribución y economía, con respuesta COM
  simulada en las comprobaciones directas de autorización.
- `scripts/test-public-checkout.sh`: regresión de checkout existente.
- `scripts/build-frontend.sh`: TypeScript y build secuenciales.
- `scripts/check-partner-sso.sh` y `--live`: Chromium 390/1440, selector, separación
  y overflow, con props protegidas aisladas y assets reales; sin sesiones LIVE.
- `scripts/check-partner-access-live.php`: consulta real con HMAC existente al owner
  real Dossis, sin imprimir UUID ni credenciales; prueba también firma incorrecta.

`scripts/deploy-partner-sso.sh` exige commit limpio en origin/main, pruebas y
rollback privado. Aplica solo la migración de proyección, publica assets/código,
regenera cachés y reinicia únicamente app CLUB. Verifica `/up` y base local.
`scripts/verify-partner-sso-live.py` verifica SHA, entrada OIDC, rechazo de callback
sin transacción y hashes de assets publicados.
Rollback: restaurar el commit y manifest del respaldo privado
`/srv/sites/jakawi.club/rollback-partner-sso-10d`, regenerar cachés y reiniciar
solo app CLUB. Conservar columna, email nullable y sujetos proyectados; no revertir
identidades ni datos económicos.
