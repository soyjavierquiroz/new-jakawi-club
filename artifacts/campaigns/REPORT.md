# Campañas configurables por landing

Entrega del 29/10/2026, Bolivia. Proyecto exclusivo: `jakawi.club`. Baseline Git/LIVE: `ad41c3347aea6ee01f58a19996013a65b0d31693`. Se reutilizan editor, JSONB, borradores, snapshots, CTA y reproductor existentes, sin migraciones ni dependencias nuevas.

## A. Videos

El video principal y el de confirmación conservan sus URL, poster local, proporción de origen y crop X/Y independientes. Ambos usan el reproductor oficial y marco fijo 3:4. Se conserva mostrar/ocultar principal y se añade mostrar/ocultar confirmación sin borrar sus medios. Confirmación: URL específica → `JAKAWI_CONFIRMATION_VIDEO_URL` → sin video. Ocultar tiene prioridad sobre la presencia de URL. No se escribe sobre medios de campañas existentes ni se inventan fuentes.

## B–D. Grupos y fallbacks

El JSONB guarda `whatsapp_group: {mode, url}` y `confirmation_whatsapp_group: {mode, url}`. Defaults compatibles para snapshots anteriores: `default` e `inherit`, aplicados en memoria.

| Grupo de landing | Confirmación heredada | Confirmación diferente | Confirmación oculta |
| --- | --- | --- | --- |
| Predeterminado | Fallback de entorno | Su URL específica | Sin CTA |
| Propio | URL de la landing | Su URL específica | Sin CTA |
| Sin grupo | Sin CTA | Su URL específica | Sin CTA |

El fallback sigue siendo `JAKAWI_WHATSAPP_GROUP_URL`. La validación central existente acepta exclusivamente la forma HTTPS `chat.whatsapp.com/código`, sin credenciales, puertos, query, fragmentos ni rutas adicionales. Guardar y publicar validan por separado; una publicación inválida conserva el snapshot anterior. Los dominios parecidos y los enlaces con redirecciones se rechazan. Se conserva el contrato sintáctico de códigos existente; no se realizan solicitudes a invitaciones para comprobar su vigencia.

`Welcome` utiliza el componente `Community` existente con el grupo efectivo. `Registered` conserva un solo botón **UNIRME AL GRUPO DE WHATSAPP**. Los CTA públicos tienen verde oficial, pestaña nueva y `rel="noopener noreferrer"`. Sin destino válido no aparecen CTA ni redirección de WhatsApp.

## E. Editor, preview y recibo

Se amplía el editor actual con **Grupo de WhatsApp** y, dentro de **Después del registro**, **Grupo de WhatsApp de confirmación**. El formulario muestra el campo URL únicamente al seleccionar grupo propio/diferente. La preview representa el borrador guardado, sin altas, conversiones ni navegación automática; en confirmación también identifica la URL efectiva.

Guardar no modifica `published_configuration`. Publicar copia el borrador validado al snapshot. Las tres campañas de prueba son independientes y solo existen en esquemas PostgreSQL temporales.

El POST aceptado fija el grupo efectivo y el video efectivo en el recibo de sesión, incluidos fallback y valores nulos. Cambiar configuración publicada, campaña o defaults después no altera la confirmación emitida. Los recibos anteriores al despliegue se fijan en su primera lectura. Se mantienen expiración de 15 minutos, protección de origen/sesión, activación real, referido original y ausencia de conversiones por refrescar o repetir un email. La bienvenida genérica también conserva sus medios en sesión.

El JSON editorial mantiene `jakawi-club-landing-v2` y compatibilidad v1. Importar conserva operación y medios; rechaza introducir grupos y URL operativas. La exportación y el brief usan la allowlist editorial existente y excluyen grupos, videos, presentación, destinos y contador.

## F. Validación

Pruebas secuenciales en contenedores limitados, con PostgreSQL temporal y notificaciones falsas:

- Landings/editor/publicación: **267 comprobaciones**, incluidas las tres landings y las nueve combinaciones de confirmación, validación al guardar/publicar, preview, aislamiento y JSON v1/v2.
- Recibos/confirmación: **130 comprobaciones**, con snapshot inmutable de grupos/videos específicos, fallbacks, nulos y ocultación; protección, referido y conversiones.
- MVP: **73 comprobaciones** de registro, acceso, activación, recuperación y referido. Se fijaron defaults vacíos en el entorno de prueba para que el caso de configuración vacía sea independiente del contenido operativo del servidor.
- Reproductor/teléfono: **75 comprobaciones**.
- TypeScript estricto y Vite: correctos, sin reinstalación de dependencias.
- Navegador público/confirmación: **63 grupos** a **390, 430 y 1440 px**, incluidos CTA único, verde oficial, pestaña segura, videos 3:4 y ausencia de overflow. Respuestas de recibos generadas por el kernel real aislado; mutaciones y proveedor interceptados en navegador. No acreditan playback físico ni apertura LIVE.
- Editor/preview: **22 grupos**, evidencia en [editor-visual.json](editor-visual.json), con edición de grupos, validación nativa de dominio, guardado, preview efectiva, exportación sin URL operativas y ocultación de video conservando la fuente.

Evidencia visual: [grupo propio móvil](campaign-custom-430.png), [confirmación heredada móvil](campaign-thanks-custom-inherit-390.png), [sin grupo](campaign-none-390.png), [editor desktop](campaign-editor-1440.png). Los fixtures usan invitaciones sintéticas exclusivamente dentro de pruebas interceptadas.

## G. Despliegue y Git

Procedimiento exclusivo de CLUB: publicar assets nuevos conservando todos los chunks previos, sustituir el manifest al final mediante rename atómico y reiniciar únicamente `jakawi-club-app` para OPcache. No recrear servicios, ejecutar migraciones, modificar Traefik, tocar COM/CRM ni regenerar configuración de entorno innecesariamente.

El sello final `LIVE.json`, ignorado para evitar autorreferencia, se genera con `python3 scripts/verify-campaigns-live.py` después del push normal a `origin/main`. Compara HEAD/origin/main/remoto, árbol limpio, blobs de código montados y assets HTTPS, integridad del entorno, IDs/redes/estado de contenedores propios, hashes de usuarios/permisos/tokens/landings/migraciones/conversiones y registro cerrado con POST 403. Comprueba `/up` de CLUB y COM y el loopback de CLUB. Consultar ese sello para el SHA completo final.

Los cambios previos de SMTP, activación, contrato y validador local están preservados íntegramente en el stash **pre-campaigns preserved SMTP activation work 2026-10-29**, incluidos archivos sin seguimiento. No se mezclan en los commits de campañas. Su SHA se conserva en el baseline privado y el sello final. El respaldo de assets y baseline está en `/srv/sites/jakawi.club/rollback-campaigns/`.

Rollback: revertir exclusivamente los commits de campañas, reconstruir con el runner existente y publicar el manifest previo conservado en el respaldo privado; reiniciar únicamente `app`. No ejecutar rollback de base de datos ni modificar secretos o banderas.

## H. Pendientes concretos para apertura

1. Configurar y publicar los videos y grupos reales aprobados de cada campaña desde el editor. El grupo y el video de confirmación predeterminados actuales no tienen una URL válida; permanecen así, sin contenido ficticio.
2. Acreditar reproducción real con fuentes que permitan inserción y revisar iPhone/Safari y Android físicos. El navegador automatizado usa proveedor interceptado.
3. Completar los datos del responsable, contacto y conservación indicados en la política de privacidad vigente y la preparación operativa de apertura.
4. Obtener instrucción expresa para abrir y entonces habilitar las banderas operativas existentes. En esta intervención ambas permanecen **false**, `app.env` y SMTP se conservan byte por byte y no se envía ningún mensaje SES.

CAMPAÑAS JAKAWI CONFIGURABLES POR LANDING.
