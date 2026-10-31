# Fase 10D — acceso Partner automático por SSO

`/afiliados` usa identidad OIDC verificada y consulta permisos económicos vigentes
COM por petición. Owner/manager requieren permiso true y establecimiento publicado
más referencia CLUB habilitada. Staff, revocación y fallo retiran solo los
resultados empresariales. Las asignaciones locales históricas ya no autorizan.

Primera entrada: proyección interna automática por sujeto, sin formulario,
contraseña ni handoff duplicado. Nunca se enlaza por correo. Las identidades
existentes se reutilizan por sujeto. Selector para varios establecimientos y
resultados personales separados, conservando referentes y beneficiarios.

## Validación

- 153 comprobaciones 10D: kernel HTTP, esquema PostgreSQL aislado y JWT RS256
  del proveedor simulado, validado por la biblioteca OIDC real.
- 41 comprobaciones de atribución/economía y 57 del checkout existente.
- TypeScript y build correctos. Chromium 390/1440: selector, separación y ausencia
  de overflow, excepciones y assets fallidos; props protegidas aisladas.
- Endpoint real COM: HMAC backend aceptado, owner existente Dossis 43 autorizado,
  firma incorrecta rechazada. Sin credenciales ni UUIDs en evidencia pública.
- COM LIVE: `34adc6a98bb94d6831fcd6b0a2e6153ba0517256`.

Sin fixtures LIVE ni modificaciones COM/CRM. Los ensayos de roles, revocación y
SSO son aislados; no se inició sesión en nombre del owner LIVE.

Operación y rollback: [contrato 10D](../../docs/integrations/club-partner-sso-10d.md).
El SHA LIVE se entrega tras verificar despliegue, `/up` y assets publicados.
