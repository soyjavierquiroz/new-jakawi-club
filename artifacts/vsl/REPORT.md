# Video oficial VSL — jakawi.club

Baseline LIVE verificado antes de modificar: `9c81ffcba1bc47e4e8878e127b50dca3a62ae01c`. Git limpio, main sincronizado, archivos montados y assets HTTPS coincidentes. Ambos sitios respondían 200 y los servicios propios estaban saludables.

## Configuración y presentación

Video aprobado: https://youtube.com/shorts/P42gDOTL6Ys (ID `P42gDOTL6Ys`). Configurado exclusivamente mediante `JAKAWI_VIDEO_URL` en la persistencia privada de jakawi.club. Laravel valida y publica `https://www.youtube.com/watch?v=P42gDOTL6Ys`; React continúa recibiendo `launch.video_url`, sin URL ni ID hardcodeados en componentes.

JakawiVslPlayer mantiene su motor, carga bajo clic y controles existentes. Se añade únicamente el encuadre de fuente `9:16` a sus opciones de geometría, dentro de la presentación 3:4 en móvil y desktop. El iframe se centra sin escalar ni recortar y conserva bandas laterales. Se corrigió el dimensionamiento intrínseco de grid detectado durante la primera comprobación: el iframe ahora se posiciona dentro del marco con dimensiones contenidas.

Se conservan hero de dos columnas desde 1024 px, texto centrado verticalmente y alineado a la izquierda; orden móvil, CTA sticky, fuentes, tokens, logo y selector internacional. No se modifica formulario, registro, referidos, autenticación ni endpoints. El poster sigue opcional y sin archivo aprobado configurado.

## Comprobación real y restricción del proveedor

**Configuración desplegada; reproducción insertada rechazada por YouTube.** Se hicieron clics reales sobre la landing pública a 390 y 1440 px, sin interceptar YouTube y con caché desactivada. La sonda confirma ausencia de fixtures, iframe del ID correcto, marco 3:4 y fuente 9:16 contenidos. Se observa la API nativa, conservando sus callbacks y respuestas reales.

En ambos tamaños YouTube devuelve **150**. La API identifica `P42gDOTL6Ys`, duración 8 segundos, estado sin iniciar y tiempo que permanece en cero. **No se acredita reproducción real.** Según la [documentación oficial de la API](https://developers.google.com/youtube/iframe_api_reference#onError), 150 equivale a 101: el video no permite reproducción insertada. No se atribuye este código a un problema de layout ni de conexión.

YouTube oEmbed responde 200 y reconoce el video. Separadamente, la consulta directa al watch desde el servidor devuelve LOGIN_REQUIRED con verificación antibot. Esa respuesta no sustituye el diagnóstico 150 obtenido de la API real insertada.

Pendiente externo: comprobar/habilitar «Permitir inserción» en YouTube Studio para este video y repetir la sonda real. La [ayuda oficial](https://support.google.com/youtube/answer/171780?hl=es) sitúa esa opción en Contenido → Detalles → Mostrar más. No se cambió la cuenta de YouTube, se sustituyó el video ni se intentó eludir la restricción. La publicación no se declara plenamente reproducible mientras persista ese rechazo.

## Pruebas y evidencias

- TypeScript y Vite secuenciales, contenedores limitados existentes: `frontend-build.txt`.
- 34 pruebas focalizadas del motor YouTube/teléfono: `unit-tests.txt`.
- 73 comprobaciones backend en esquema PostgreSQL aislado: `backend-tests.txt`. El fixture sin video se hace explícito para que no herede el nuevo video de producción. Sin usuarios de prueba LIVE ni cambios en datos reales.
- 14 grupos Chromium en 390, 768, 1024 y 1440 px: `../conversion/visual-validation.json` y `visual-run.txt`. Se mantienen foco, reduced-motion, sticky, formulario, selector y estados VSL de carga, reproducción, pausa, fullscreen, error y retry mediante fixtures identificados. También se conserva el estado honesto sin URL configurada.
- Sonda real final, separada de esos fixtures: `real-provider-run.txt` y `real_playback` dentro del JSON visual. La primera sonda se descartó por reutilizar una respuesta de prueba en caché; las evidencias finales exigen ausencia de fixture y coincidencia del iframe.
- Capturas: `../conversion/hero-390.png`, `hero-1440.png`, `real-video-390.png`, `real-video-1440.png`; resto de tamaños y estados en ese directorio.
- Metadatos públicos y respuesta directa del proveedor: `provider-check.json`.
- Validaciones local y pública: `local-validation.json` y `public-validation.json`. HTTPS, assets, servicios saludables, ambos `/up` = 200 y POST /registro = 403 con CSRF válido.

## Despliegue, Git y aislamiento

Se publicó el build conservando chunks anteriores y cambiando el manifest atómicamente. Se recreó **solo app** para inyectar la nueva variable, se regeneró config:cache y se reinició únicamente app para OPcache. Sin cambios de Compose, infraestructura compartida, Traefik ni otros proyectos; sin migraciones LIVE.

Se verifican todos los parámetros del env salvo el cambio autorizado de JAKAWI_VIDEO_URL, hashes de credenciales independientes, digest de usuarios y estado de contenedores ajenos. El baseline local conserva la identidad anterior y posterior de app para documentar su recreación autorizada; las referencias de los demás servicios no se actualizan después del despliegue.

Commit, main remoto y SHA LIVE se sellan después del push en `LIVE.json` (excluido de Git), también en los puntos de entrada existentes de conversion/identity. El sello verifica archivos montados y bytes de assets servidos y conserva explícitamente `video_playback_verified=false` y código 150; no confunde despliegue de configuración con reproducción aprobada.

**Registro público cerrado; APP_DEBUG=false.** Credenciales y usuarios conservados. Rollback preparado en [ROLLBACK.md](ROLLBACK.md).
