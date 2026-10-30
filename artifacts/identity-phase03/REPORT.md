# Fase 03 — integración de identidad CLUB → COM

Continuación: [Fase 04 — cliente SSO CLUB](../identity-phase04/REPORT.md).

## Contrato y alcance

Se consume el contrato técnico `/root/jakawi-identity-v1/repository/docs/integrations/CLUB_COM_IDENTITY_API_V1.md` de COM, SHA `11e04f3c91fd4096257a01f01c48eba3396f59e2`. Único origen API: `https://jakawi.com/internal/identity`. Rutas utilizadas: POST `/handoffs`, GET `/handoffs/{handoff_id}`, GET `/links/{club_user_id}`. No hay callback ni intercambio de sesiones.

La columna preparada `users.global_subject_id` UUID nullable/única se aplica junto a las tablas exclusivas `club_identity_*` de evidencia, broker de confirmación, operaciones y proyección de vínculo. Un sujeto ya asignado no puede borrarse o sustituirse; la revocación modifica el estado del vínculo, conservando su UUID. PK bigint, UUID público, contraseña, roles, referente y atribución existentes se conservan.

Solo se cambia CLUB. No se edita ni despliega COM, FluentCRM, SMTP/SES, landings, confirmaciones comerciales, WhatsApp, registro público o sus ajustes.

## Flujo privado

El dashboard presenta **ACTIVAR MI ACCESO A JAKAWI.COM** para cuentas activadas y verificadas. Su POST autenticado, con CSRF y límite de frecuencia, constituye la decisión explícita de compartir nombre/correo/teléfono y conectar el acceso. No se crea ningún handoff por registrar o activar un lead.

CLUB conserva la evidencia de activaciones nativas futuras mediante el evento emitido dentro de la transacción que consume su token. Las cuentas históricas, incluido un administrador verificado manualmente, no reciben evidencia inventada a partir de timestamps. Si falta evidencia, el botón envía una confirmación de correo privada mediante un broker Laravel independiente de 15 minutos. El titular inicia sesión, abre el correo y confirma mediante POST de un solo uso. No cambia contraseña, roles, activación previa ni tokens de recuperación existentes. Luego vuelve al dashboard y solicita explícitamente el acceso COM.

La operación persiste una vez el JSON exacto, cifrado con Laravel, con prueba de activación firmada y consentimiento. Reintentos y recuperación tras respuestas perdidas mantienen ID y bytes. Cada intento HTTP usa timestamp/nonce/firma nuevos. Hay dos intentos de transporte acotados y, después, reintento explícito del usuario sobre la misma operación durable. Un 429 bloquea peticiones durante `Retry-After` para toda la credencial. Antes de reenviar se consulta el vínculo y el handoff. Solo si COM no conoce una operación cuya prueba ya expiró se permite generar una nueva con consentimiento explícito.

`PROVISIONED`/`LINKED` requieren issuer, contrato y correlaciones correctos, UUID válido, método/timestamps y estado de vínculo autorizado. Solo entonces se guarda el sujeto. `AUTH_REQUIRED` redirige exclusivamente a `https://jakawi.com/identity/club/link/{handoff_id}`; no guarda sujeto ni interpreta el email como autorización. COM exige su login y consentimiento nativos. Al volver al dashboard, CLUB consulta el UUID estable y recupera la autorización. Consultar estado nunca provisiona. La UI muestra **Cuenta JAKAWI conectada**, acceso al login propio de COM y estados de espera, conflicto, expiración o revocación.

## Credenciales

Las credenciales existentes de `/opt/sites/jakawi.com/identity-club.env` se instalaron por acceso autorizado en `/srv/sites/jakawi.club/secrets/com-identity.env`, root 0600, fuera de Git. Compose las inyecta solo a la aplicación CLUB; no se copian al entorno SMTP existente. Se validan origen fijo, claves distintas entre sí y del HMAC CRM. El navegador recibe exclusivamente estado y URL de consentimiento; nunca claves, assertion, cuerpo de outbox, cookies o contraseñas de COM.

## Pruebas

- `bash scripts/test-identity-link.sh`: 55 comprobaciones del kernel HTTP y PostgreSQL aislado: evidencia/token, firma, cifrado, correlación, vínculo, revocación, protección de UUID, CSRF, redirección inválida, cuenta equivocada, reintentos y backoff.
- `python3 scripts/test-identity-com-contract.py`: 21 comprobaciones contra la imagen inmutable realmente desplegada de COM, ejecutada en contenedores limitados y PostgreSQL desechable exclusivos de esta prueba. Se usa el cliente CLUB real, redirigiendo únicamente su transporte hacia la instancia aislada. Se comprueban provisión/idempotencia y login/CSRF/consentimiento nativos de cuentas fixture; los invariantes COM verifican una única cuenta nueva, conservación íntegra de usuarios originales y ausencia de nuevos permisos, membresías o entregas CRM. No se usa ni suplanta al piloto.
- Regresiones aisladas: MVP 73, embudo vigente 34 y cola CRM 34. Notificaciones simuladas; ninguna cuenta o correo de prueba LIVE.
- TypeScript y Vite pasan mediante el builder limitado existente. QA de navegador en 390/1440 usa páginas privadas fixture, assets reales y todas las mutaciones interceptadas. Evidencia en `browser.json` y capturas `private-*.png`.

Los validadores históricos documentados en los preparativos siguen fuera del criterio de aceptación: esperan registro cerrado o WhatsApp en captación, comportamientos ya sustituidos antes de esta fase. No se cambia el embudo para satisfacerlos.

## Despliegue y piloto

Se despliega exclusivamente CLUB: backup privado, migraciones aditivas, recreación de `app` para inyectar el nuevo archivo privado, cachés y assets con chunks anteriores conservados, y reinicio de `app` para OPcache. El sello `LIVE.json`, excluido de Git, verifica commit/push, código montado, assets HTTPS, ambas migraciones, `/up`, entorno SMTP intacto, tablas protegidas y ausencia de reinicios/recreaciones de COM/CRM.

Piloto: `javierquiroztv@gmail.com`. No se crea ningún administrador ni se genera autorización en nombre del propietario. El acceso operativo está en `https://jakawi.club/dashboard`: al faltar evidencia histórica de token, el titular confirma primero el correo privado y luego completa login/consentimiento real en COM. El estado observado del piloto se guarda en `LIVE.json` sin tokens ni datos de sesión. Esta fase no envía recuperación ni confirmación al piloto sin que pulse la acción.

Rollback: restaurar solo código/manifest/cachés anteriores de CLUB y retirar la funcionalidad de UI. Conservar columnas, sujetos y tablas de evidencia/operaciones. No ejecutar rollback general de migraciones ni borrar asignaciones de identidad. Los respaldos y baseline están en `/srv/sites/jakawi.club/rollback-identity-phase03/`, fuera de Git.
