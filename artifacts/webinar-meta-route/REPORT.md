# Meta webinar: activación por ruta

La entrada /ads/meta/webinar dependía de jakawi_ads_consent=yes; sin esa preferencia
Laravel no habilitaba el SDK y React ofrecía un paso adicional de activación.
El alias canónico _fbq ya estaba corregido y se conserva.

El webinar habilitado carga Meta por su ruta, sin UTMs ni cambios a preferencias
compartidas. No muestra el bloque de preferencias ni emite consent/grant.
La visita usa un scope de sesión propio; consented_at queda NULL y no se inventa
consent_version. Migración aditiva de nulabilidad, sin reescribir evidencia previa.
El down es deliberadamente vacío para no inventar fechas al revertir código.
Las rutas / y /principal siguen sin Pixel, incluso después de visitar el webinar.
La activación técnica por ruta no constituye una afirmación de consentimiento legal.

CompleteRegistration conserva el UUID persistido compartido por browser y Laravel
CAPI en la cola ads existente, únicamente para cuentas nuevas guardadas.
No hay Lead ni Purchase del registro; reenvíos/correos existentes no convierten.
La retirada explícita desde Privacidad revoca también el scope separado del webinar
y bloquea sus entregas pendientes. Una nueva entrada al webinar aplica la política
por ruta sin modificar la preferencia almacenada para otros funnels/proveedores.

Verificación aislada: 33 comprobaciones Laravel/PostgreSQL en esquema temporal,
API y notificaciones simuladas; ocho escenarios de navegador a 390/1440 con un
init, un PageView, alias canónico, deduplicación y ausencia del paso adicional.
TypeScript y Vite correctos; 106 comprobaciones de regresión de checkout/publicidad
existente también correctas, con proveedores simulados y sin cambios a esos flujos.
No se crean registros falsos LIVE.

La evidencia LIVE se guarda localmente en esta carpeta después del despliegue:
real-browser.json, helper-ui.json y LIVE.json. HTTP 200 de facebook.com/tr demuestra
transporte; la recepción/deduplicación en Events Manager de una conversión auténtica
no se sustituye con un registro ficticio. No se modifica DeliverAdEvent ni su cola.
