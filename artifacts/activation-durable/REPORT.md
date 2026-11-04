# Activación de cuenta sin caducidad — CLUB

Estado de validación: **PASS**. Despliegue autorizado exclusivamente en CLUB. El SHA aplicado y la verificación pública se guardan en `LIVE.json` y `validation.json` junto a este informe.

Los nuevos enlaces de cuentas pendientes no caducan por tiempo. Se invalidan al activar la cuenta, emitir un reemplazo o revocar la invitación. Cada token contiene 32 bytes aleatorios; la base conserva exclusivamente su hash SHA-256. Emisión, revocación y consumo se serializan mediante bloqueo de la cuenta; consumo y activación comparten transacción. `ActivationTokens::revoke($user)` permite revocar la invitación existente sin emitir otra.

GET muestra el formulario, sin consumir ni autenticar. POST establece y confirma la contraseña, consume el token y confirma el éxito en `/login`. El usuario ingresa normalmente. Las respuestas mantienen `no-store` y `no-referrer`; el acceso Nginx existente oculta los tokens y las excepciones SMTP no se registran.

Los enlaces del broker anterior y los enlaces inválidos, reemplazados o consumidos muestran directamente «¿Tu enlace ya no funciona? Te enviamos uno nuevo.», con correo prellenado cuando viene en la URL y botón «ENVIAR NUEVO ENLACE». No requieren otro registro. Se ofrece «Ingresar» a quien ya activó su cuenta. El reenvío conserva la respuesta genérica y limita solicitudes a dos por correo y cinco por IP por minuto. El broker de recuperación de contraseña conserva su tabla y su expiración de 60 minutos.

El correo usa remitente visible JAKAWI, asunto «¡Bienvenido a JAKAWI! Activa tu cuenta», texto español, botón coral con texto blanco y URL alternativa. El formulario muestra contraseña, confirmación, control para mostrar/ocultar y botón de activación; permite pegar y usar gestores de contraseñas.

## Validación

- **32 PASS** de integración sobre esquemas PostgreSQL temporales: 45 días de antigüedad, GET, primer y segundo uso, errores corregibles, reemplazo, revocación, reenvío, límites, cuenta activada, login y separación del broker de contraseña. Dos procesos con POST simultáneos producen exactamente un consumo exitoso.
- **38 PASS** del flujo webinar Meta, incluyendo activación y reenvío: se conserva el único CompleteRegistration, su event_id, atribución y entrega CAPI simulada. No se emiten nuevas conversiones.
- TypeScript y build Vite: **PASS**.
- Correo, recuperación y formulario a **390 y 1440 px: PASS**; sin desbordamiento ni errores de recursos/JavaScript. Capturas generadas con HTML real del kernel en aislado y correo Blade renderizado, sin envíos reales ni usuarios LIVE de prueba.

Capturas: [correo 390](email-390.png), [correo 1440](email-1440.png), [recuperación 390](recovery-390.png), [recuperación 1440](recovery-1440.png), [formulario 390](activation-390.png), [formulario 1440](activation-1440.png).

No se modifican landing principal, landing Meta, confirmaciones compartidas, video, WhatsApp, origen, atribución, Pixel/CAPI ni código de CompleteRegistration. No se cambia COM, CRM, TikTok, secretos ni infraestructura. La única migración LIVE crea `account_activation_tokens`; no modifica usuarios ni tokens históricos. Se publica código/manifest y se reinicia exclusivamente la aplicación CLUB.

Rollback operativo: SHA y manifest anteriores en `/srv/sites/jakawi.club/rollback-activation-durable`. Conservar la tabla aditiva y todos los assets al volver al SHA anterior; no ejecutar rollback de migraciones ni borrar datos. Los enlaces nuevos requieren el código de esta entrega.
