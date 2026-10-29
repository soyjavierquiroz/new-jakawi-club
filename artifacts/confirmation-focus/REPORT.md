# Confirmación centrada en WhatsApp

Entrega del 29/10/2026, Bolivia. Proyecto exclusivo: JAKAWI.CLUB. Baseline: `ed1c92b`. Se reutilizan `Registered`, los recibos y el reproductor oficial, sin dependencias ni migraciones.

La confirmación elimina header, navegación y acciones secundarias. Centra la composición editorial, actualiza únicamente el titular predeterminado a **¡Felicidades por dar el siguiente paso!**, conserva textos personalizados y añade el SVG de correo. El único CTA es **UNIRME AL GRUPO DE WHATSAPP**, con verde `#25D366`, bounce periódico y respeto a `prefers-reduced-motion`.

Solo `ended` del reproductor explicativo provoca navegación real con `window.location.assign`, una vez por montaje y hacia el grupo validado del recibo. No intervienen el contador anterior, la barra decorativa, la duración, el montaje ni la pausa. Error, preview y sesiones administrativas no redirigen. El enlace manual permanece disponible cuando la navegación no se completa. Sin grupo no hay CTA ni redirección; sin video se conserva la confirmación informativa. El VSL principal y el player global no cambian.

El editor conserva título, mensaje, instrucciones, video, poster, crop y grupos de confirmación. Retira los controles de acciones secundarias y contador; sus datos heredados permanecen por compatibilidad. No se modifican registro, SMTP, afiliados ni COM. `app.env` se verifica byte por byte mediante su hash privado.

TypeScript estricto, build Vite y sintaxis PHP correctos. La prueba focalizada pasa **42 grupos antes de desplegar y 42 sobre assets HTTPS LIVE**, en **390/430/1440 px**: nueve combinaciones de grupos, CTA único, centrado, SVG, 3:4, bounce, movimiento reducido, textos personalizados, `ended` repetido, pausa/error/preview/admin y ausencia de navegación desde el VSL principal. [Evidencia LIVE](live-browser.json).

Las señales de YouTube están interceptadas y atraviesan el motor oficial; las solicitudes de navegación reales se responden con HTTP 204 dentro del navegador. Las capturas muestran recibos sintéticos sobre assets LIVE, sin abrir invitaciones, crear cuentas ni enviar correos. No acreditan reproducción física del proveedor.

Capturas: [móvil 390](live-confirmation-390.png), [móvil 430](live-confirmation-430.png), [desktop 1440](live-confirmation-1440.png).

Despliegue: chunks anteriores conservados, manifest cambiado mediante rename atómico y reinicio exclusivo de `jakawi-club-app`. `/up` HTTPS y loopback responden HTTP 200. Después del push normal, `python3 scripts/verify-confirmation-live.py` genera el sello ignorado `LIVE.json` con SHA completo, igualdad Git/remoto, árbol limpio, código montado, assets HTTPS, entorno intacto y servicios CLUB saludables.

Rollback privado: `/srv/sites/jakawi.club/rollback-confirmation-focus/`. Revertir exclusivamente este cambio, reconstruir con el runner existente, publicar assets y manifest anterior, y reiniciar solo `app`. No aplicar rollback de base de datos ni cambiar configuración operativa.
