# Videos 3:4 y confirmación JAKAWI

Entrega del 29/10/2026, Bolivia. Alcance exclusivo: `/opt/sites/jakawi.club` y persistencia propia. Se partió de `main` sincronizado (`c0169e6`) conservando las modificaciones previas de SMTP, activación y contrato fuera de este commit. No se repitieron auditorías generales, correo SES, instalaciones ni migraciones.

## A–D. Comportamiento implementado

- Marco visual fijo 3:4 en LEAD_VSL, WEBINAR, WAITLIST, confirmación y previews. Sin control de tamaño del marco. Se conserva `JakawiVslPlayer` y su motor oficial: autoplay muted respetando preferencias, audio desde cero, overlay, progreso dummy, pausa, replay, reintento y error del proveedor.
- Editor: URL de YouTube, tipo compatible, poster PNG/JPEG/WebP local existente, proporción de origen 9:16/3:4/16:9, encuadre X/Y y mostrar/ocultar video principal. Cover mantiene las proporciones. En contenido vertical incrustado, el encuadre se limita al área útil para evitar mostrar bandas del lienzo.
- `confirmation_video` guarda URL, poster, origen y encuadre propios. Está separado de los textos `after_registration` y del JSON editorial. La URL específica tiene prioridad sobre `JAKAWI_CONFIRMATION_VIDEO_URL`; sin una URL válida se oculta el reproductor público y el admin informa del estado vacío.
- El recibo backend existente guarda el snapshot de confirmación y medios. Se reutilizan `Registered`, rutas y guardas para las tres plantillas. Estado pendiente: «Registro recibido» y «Revisa tu correo». Activación, verificación y sesión vinculada siguen siendo necesarias para afirmar «Cuenta activada». Refrescar no suma conversiones.
- `JAKAWI_WHATSAPP_GROUP_URL` se resuelve desde `config/jakawi.php`, fuera de JSON editorial. Solo permite HTTPS `chat.whatsapp.com` con código de invitación, sin credenciales, puertos, query ni fragmentos. El botón aparece en cada confirmación cuando existe un grupo válido, con texto exacto, verde oficial, pestaña nueva y `noopener noreferrer`. Vacío lo oculta. Se conserva el contador opcional existente con cancelación y protección contra reinicio por refresh.
- El seed histórico `P42gDOTL6Ys` no se usa como default ni fallback. Sus snapshots heredados se normalizan en memoria, sin reescribir configuración publicada ni cuentas. No se inventa contenido sustituto.
- Importación/exportación mantiene `jakawi-club-landing-v2` y parches v1. Se rechaza que JSON editorial cambie medios, crop, grupo, destinos, banderas, permisos o publicación. No se añadieron dependencias ni un segundo reproductor.

## E. Activación: causa y estado real

La evidencia previa de emisión y corrección identifica un error del runner: cambió la zona global PHP a Bolivia antes de serializar timestamps del broker. El token quedó fechado cuatro horas antes de lo esperado por Laravel UTC. No fue un fallo de SES, de ruta ni de parámetros; el broker `users` conserva la caducidad de 60 minutos.

El runner ya había sido corregido conservando el token original y su vigencia; el usuario terminó activado y verificado y el propietario confirmó logout/login manual y dashboard. La lectura focalizada actual confirma una sola cuenta normal, contraseña establecida y ausencia de token pendiente. Un enlace ya consumido no se reutiliza ni se reemite como activación. No se borraron usuarios ni se enviaron nuevos mensajes.

Las nuevas pruebas aisladas reproducen la serialización antigua de cuatro horas; comprueban emisión UTC al presentar fechas Bolivia, ruta y email correctos, GET sin consumo, expiración antes/después de 60 minutos, reemisión que invalida el anterior, rechazo en la ruta de recuperación y consumo atómico de un solo uso. Se conservan la identidad y el permiso administrativo de `javierquiroztv@gmail.com`, sin SSO ni modificaciones en COM.

## F. Pruebas

[Resultados focalizados](tests.json): 91 comprobaciones de confirmación/activación, 148 de landings, 73 de MVP y 75 de unidades VSL/teléfono. PostgreSQL usa esquemas temporales eliminados al terminar; notificaciones simuladas. Ninguna cuenta LIVE nueva ni correo real. TypeScript estricto y Vite completados secuencialmente en los contenedores limitados existentes.

[Editor y preview](editor-visual.json): 16 grupos, viewport 390, 430, 768 y 1440; URL modificada desde controles, poster/crop, frame fijo, guardado/publicación independientes, renderer real y confirmación sin eventos. [Confirmación y formulario](browser.json): 27 grupos a 390, 430 y 1440; con/sin video, grupo válido/ausente, color/enlace seguro, consentimiento, E.164, referido, errores, login existente, countdown y refresh. Todos los POST de navegador se interceptan. Las fixtures visuales y las notificaciones simuladas no acreditan reproducción o envío reales.

La regresión del VSL oficial conserva pruebas de autoplay muted, eventos del proveedor, audio desde cero, dummy, pause/buffering/replay, preferencias, crop, URL nueva, ocultar video, preview sin analítica y error 150. Evidencia: [VSL](vsl/browser.json), 14 grupos a 390, 430, 1024 y 1440 px. El rechazo 150 nunca acredita playback real.

## G. Capturas

- [Video de confirmación móvil 390](confirmation-video-390.png), [430](confirmation-video-430.png), [desktop 1440](confirmation-video-1440.png).
- [Editor desktop](editor-1440.png), [móvil](editor-390.png), [preview de confirmación](confirmation-preview-430.png).
- [Portada LIVE móvil](live-390.png), [desktop](live-1440.png).
- [Sin video](thanks-without_video-390.png), [grupo configurado](thanks-whatsapp-countdown-390.png).
- [VSL muted móvil](vsl/vsl-muted-390.png), [desktop](vsl/vsl-muted-1440.png).

Capturas de estados protegidos/medios: fixtures identificadas del navegador con el renderer real; no son nuevos registros de producción ni reproducción física acreditada.

## H. Git, despliegue y rollback

Los assets se publicaron con manifest atómico conservando los chunks anteriores; se regeneró `config:cache` y se reinició únicamente `app`. La [validación local](local-validation.json) pasó con los tres contenedores CLUB saludables, mismos IDs y entorno intacto. El [navegador con assets LIVE](live-browser.json) pasó 30 grupos, incluidos los tres viewports sobre HTTPS real; el registro permanece cerrado. La [regresión VSL con assets LIVE](vsl/live-browser.json) pasó 14 grupos; no se sondeó una fuente real inexistente ni se acreditó playback. CLUB y COM `/up` y loopback respondieron HTTP 200; POST público de registro con CSRF válido devolvió 403. La comparación privada confirmó hashes completos idénticos de usuarios, permisos administrativos, tokens, landings, migraciones y conversiones; entorno SMTP intacto. El único reinicio fue el de CLUB app.

El sello final se genera después del push mediante `python3 scripts/verify-video-confirmation-live.py` y queda en `LIVE.json` ignorado. Verifica HEAD/origin/main, blobs montados, assets HTTPS, chunks anteriores conservados, `/up` CLUB/COM y loopback, registro cerrado (POST 403), guardas de confirmación y hashes completos de cuentas, permisos, tokens, migraciones, landings y conversiones contra el baseline privado de esta entrega.

Persistencia privada: `/srv/sites/jakawi.club/rollback-video-confirmation/`. Guarda baseline exclusivo de CLUB y assets anteriores; no contiene un volcado público de usuarios. El despliegue cambia assets y caché, reinicia únicamente `app` para OPcache y no recrea servicios. No toca Traefik, infraestructura compartida, COM ni CRM. Registro cerrado y SMTP sin cambios.

Rollback: revertir únicamente los commits de esta entrega sobre `main`, reconstruir secuencialmente y publicar el manifest con los chunks retenidos; regenerar `config:cache` y reiniciar solo `app`. La copia privada `build/manifest.json` permite volver al frontend anterior si falla la publicación de assets. No ejecutar rollbacks de base de datos, no borrar cuentas y no alterar SMTP ni redes.

## I. Pendientes reales

El propietario debe aportar una fuente con inserción permitida para el video principal y los videos explicativos (por landing o default operativo), posters aprobados si los desea y el enlace real de grupo en `JAKAWI_WHATSAPP_GROUP_URL`. Esos campos permanecen sin contenido ficticio. Falta acreditar playback real de una fuente aprobada y las pruebas físicas iPhone/Safari y Android. Abrir el registro requiere una instrucción posterior; `JAKAWI_REGISTRATION_OPEN=false` se conserva.

Para cambiar el entorno privado, recrear exclusivamente CLUB app con `docker compose up -d --no-deps app`, regenerar `config:cache` y reiniciar `app`. El reinicio solo no vuelve a leer `env_file`. No usar la operación para modificar credenciales SMTP o abrir el registro. La recuperación administrativa aplazada y el piloto futuro de identidad COM permanecen fuera de esta entrega.
