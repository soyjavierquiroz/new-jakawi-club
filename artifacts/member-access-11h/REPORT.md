# Fase 11H — acceso a beneficios después del pago

Implementación exclusiva de CLUB. Contrato COM desplegado `jakawi-club-member-access-v1`, SHA COM `9805b13a907157ec8c7d2abd9662ba3cddd652ac`, consultado en lectura en su `artifacts/phase11g/CONTRATO-CLUB.md`. Sin cambios en COM, CRM, conciliación, IA, tarifas ni ledger LIVE.

## Comprador

`/mi-cuenta` y `/dashboard` muestran una sola tarjeta principal cuando existe activación confirmada: «¡Tu membresía JAKAWI está activa!». La acción prioritaria permite activar el acceso o entrar a beneficios. Sin tarjeta de identidad contradictoria ni enlace a un login COM separado.

El POST `/cuenta/beneficios` exige sesión CLUB activada/verificada, CSRF, límite de frecuencia y bloqueo de sesión. Resuelve la compra original desde backend; ignora referencia, UUID y claim del navegador. Compra invitada asignada: solo dueño original. Invitada sin usuario original: requiere la capacidad original en sesión y consentimiento explícito; tras asociación, recuperación por sujeto COM autorizado. No hay recuperación por coincidencia de datos personales.

Sin identidad: reutiliza `IdentityLink`, evidencia criptográfica de correo y consentimiento. Una identidad nueva usa el aprovisionamiento existente; una existente requiere el consentimiento autenticado de COM. Si falta evidencia de correo se reutiliza su confirmación existente. Sin claim original, sesión legítima o autorización requerida, el flujo se detiene y conserva la compra. Una compra invitada sin usuario que pierda su sesión antes de vincularse no puede recuperarse por email: no se agregó un bypass.

Tras autorización, backend solicita el ticket con HMAC de compras, nonce nuevo, bytes exactos y claim del intent cifrado. El endpoint de COM completa la asociación de compra legítima y emite sus eventos existentes. CLUB no llama creación de compra, membresía ni cookies COM. Valida contrato, ticket hexadecimal, destino y entrada exactos. Respuesta JSON privada/no-store, ticket solo en memoria del navegador y navegación `window.location.replace(entry_url + '#' + ticket)`. Sin ticket en Inertia, HTML, sesión, almacenamiento, URL query o evidencia.

COM controla los 90 segundos, consumo único, confirmación de otra cuenta abierta y apertura real de `/beneficios`. Se permite solicitar otro ticket sin repetir compra. Los rechazos se traducen a mensajes comprensibles. Doble clic bloqueado en el navegador.

## Caso Janny y afiliado

Verificación LIVE exclusivamente de lectura: compra `97cb9a04-9f79-448d-be20-7a0b2bbb795d`, membresía 2 aprobada, identidad pendiente; dueño CLUB activado/verificado, UUID original coincidente, claim y evidencia de activación existentes. Cumple los prerrequisitos locales para iniciar el consentimiento desde su cuenta. No se vinculó manualmente, no se emitió ticket real ni se suplantó su sesión. Su finalización LIVE necesita su consentimiento y, si COM reconoce una cuenta existente, su autorización autenticada allí.

El referente original coincide. Un lead; cero entradas de comisión para esta adquisición. La evidencia persistida certifica afiliado PAID (`EFFECTIVE_PAID_MEMBERSHIP`), pero primera adquisición `unknown` por `BUYER_IDENTITY_UNRESOLVED`. Activación posterior al corte y tres reglas de adquisición vigentes. El bloqueo observado es identidad del comprador/evidencia de primera adquisición pendiente, no ausencia de referente ni fecha anterior a reglas. No se promete importe.

El panel muestra «Comisión pendiente de verificación» solo ante evidencia pendiente real del beneficiario y sin entrada en ledger. El evento firmado existente de identidad cambia el contexto de caché para reconsultar evidencia aunque el handoff ya haya agregado el sujeto localmente. Las reglas, fechas, aprobación manual e idempotencia se conservan. No se reevalúan datos LIVE manualmente durante el despliegue.

## Verificación y límites

- `scripts/test-member-access.sh`: esquema PostgreSQL temporal, kernel real, HMAC exacto, nonce único, CSRF, sesión, consentimiento, claim incorrecto, identidad nueva/existente/autorizada, destino ajeno rechazado, retorno con nueva sesión y compra invitada sin dueño. Sin fixtures LIVE.
- `scripts/test-public-checkout.sh`: regresión focalizada de compra/aviso/comprobante y protección entre cuentas.
- `scripts/test-acquisition-evidence.sh`: reevaluación inmediata tras evento firmado con aliases ya presentes, cache de UNKNOWN, estado pendiente visible e idempotencia. Importes usados por fixtures de reglas existentes, sin cambios LIVE.
- `scripts/check-member-access.sh` y `--live`: navegador con assets preview/LIVE y datos privados interceptados. 390/1440 px, tarjeta única, consentimiento, doble clic, reintento y navegación por fragmento. La pantalla COM se simula en esta prueba: no acredita una sesión real de Janny.
- Vencimiento, reutilización, cambio de otra cuenta COM y sesión final pertenecen a COM 11G. Su evidencia desplegada registra 28 pruebas de acceso/compras (299 aserciones), además de identidad y regresión comercial. Esta fase no ejecuta ni altera el entorno COM. No se declara una nueva prueba integral LIVE de estos casos.

Despliegue: `scripts/deploy-member-access.sh`, publicación atómica del manifest conservando assets previos, cachés de rutas/vistas y reinicio exclusivo de `app` CLUB. Sin migraciones ni cambios de entorno. Validación local con baseline propio. Sello SHA/manifest/health en `LIVE.json` local excluido de Git.

Rollback privado: `/srv/sites/jakawi.club/rollback-member-access-11h/{baseline.json,manifest.json}`. Restaurar el código del SHA registrado y manifest previo, regenerar rutas/vistas y reiniciar solo app. No revertir datos ni retirar assets que puedan estar en uso.
