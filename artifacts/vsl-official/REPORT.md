# VSL oficial JAKAWI en jakawi.club

Implementación y despliegue: 29 de octubre de 2026, America/La_Paz. Baseline efectivo local/remoto/montado/servido: `f485ba92f585fbf80984af3df7a2a5ecc2f7e828`. Fuente oficial: `130bfac34a645060c0df9767e3994064451349c3`, checkout `/root/jakawi-marketing-portability-audit-v1/repository/`, exclusivamente leído.

## A. Archivos portados

En `app/resources/js/components/media/jakawi-vsl-player/`:

- `JakawiVslPlayer.tsx`, `JakawiVslPlayer.module.css`, `types.ts`.
- `use-vsl-playback.ts`, `use-decorative-progress.ts`.
- `youtube-engine.ts`, `youtube-crop.ts`, `marketing-presentation.ts`, `video-theme.ts`.
- Adaptación del host: `LandingVideo.tsx`, `landing-video-props.ts`, `player-icons.tsx`, `index.ts`, `LICENSE.lucide`.

`use-decorative-progress.ts`, `youtube-crop.ts` y `video-theme.ts` conservan los bytes originales. La procedencia y hashes por archivo están en [provenance.json](provenance.json). Se eliminan las ramas HTML5/HLS/course: no se trasladan `html5-engine.ts`, `hls-source.ts`, `PlaybackControls.tsx` ni `course-presentation.ts`. El estado inicial, `shouldAutoplay` y el contrato de controles se conservan en `types.ts`. Los cuatro iconos usan la geometría oficial Lucide 0.475.0 y su licencia, con SVG local. No se instalan paquetes ni cambian los lockfiles.

## B. Diferencias frente al adaptador anterior

El reproductor anterior esperaba un clic para crear YouTube, intentaba iniciar con audio, contenía el Short dentro del marco y usaba controles externos. Ahora utiliza los overlays, controles, reloj decorativo y crop del VSL oficial. Conserva los atributos de estado necesarios para que el sticky respete el video. Se retiran los estilos globales que imponían el encuadre anterior. Se añaden únicamente los tokens oficiales de scrim/overlay que faltaban. La página de privacidad describe la carga automática silenciada real.

## C. Autoplay muted

El montaje crea `createYoutubeEngine()`. `onReady` ejecuta mute antes de solicitar `playVideo()`. El parámetro del iframe permanece `autoplay: 0`: la solicitud ocurre mediante la API. Solo `onStateChange(1)` confirma playing. Reduced-motion/saveData inhiben la solicitud inicial y sus cambios pueden pausar. Un bloqueo del navegador muestra inicio manual.

Se mantienen `youtube-nocookie.com`, IFrame API, origin dinámico, playsinline y parámetros documentados del original. No hay proxies, descargas, parámetros de evasión ni cambios de fuente.

## D. Activación de audio

El overlay muestra «Enciende tus parlantes / Toca para escuchar» durante reproducción silenciosa y mientras solicita audio. La guarda de solicitudes simultáneas conserva la secuencia oficial: pausa, seekTo(0,true), unMute, volumen 100 solo si estaba en cero, play. El evento audio-activated exige confirmación audible del proveedor. Al bloquearse se vuelve a silenciar y queda el inicio manual; no se finge reproducción ni se restaura la posición anterior. Pausa, continuar, ended y replay mantienen sus estados y preferencia de audio.

Las adaptaciones de recuperación son explícitas: retry recrea un motor fallido, onReady tiene un máximo de 20 s y buffering otro máximo de 20 s. El watchdog de solicitud de 10 s conserva la condición requesting del original; no clasifica buffering como una denegación del navegador. Los errores conservan el código del proveedor y ofrecen un enlace normal «Ver en YouTube».

## E. Barra dummy

Se porta `useDecorativeProgress` con requestAnimationFrame y `vslProgress`: horizonte virtual 90 s, exponente 3, límite 97 %, 100 % solo con ended. Acumula únicamente durante playing; pausa/buffering congelan el reloj; continuar lo conserva; audio, replay y fuente nueva lo reinician. Es aria-hidden y no permite seek. No alimenta retención ni analítica. Los eventos de progreso real siguen separados; la medición local existente atiende exclusivamente playing para video_start.

## F. Geometría 3:4

La configuración predeterminada 3:4 + fuente 9:16 se adapta a embedded-vertical, viewportAspectRatio 9/16, marketingFrame true, fit cover y theme inherit. El CSS oficial impone marco 3:4 a todos los tamaños. El iframe conserva canvas 16:9, ancho 316,049383 % y alto 133,333333 % del marco, centrado, sin distorsión.

Este cover recorta aproximadamente 25 % de la altura del contenido 9:16 interior, repartido entre arriba/abajo. Es un recorte de presentación, no una modificación del archivo. No se certifica el contenido decodificado del Short rechazado. Se conservan las opciones de marco 16:9 y proporción de fuente del editor; la proporción nativa 3:4 también se respeta cuando corresponde.

Chromium comprueba 390/430/1024/1440, crop, una instancia por landing, texto centrado verticalmente a la izquierda en desktop, video a la derecha y orden titular/video/texto en móvil. Se conserva el contenedor existente de club y su sticky, sin copiar todo el layout de jakawi.com.

## G. Plantillas y editor

`Welcome.tsx` utiliza el adaptador tipado local `LandingVideo`. LEAD_VSL y WEBINAR con show_video montan un único reproductor. WAITLIST, WEBINAR sin video y fuente ausente no cargan YouTube. La configuración proviene del snapshot de landing; no contiene IDs de video hardcodeados en el renderer.

URL, poster local y encuadres siguen independientes. Autoplay no estaba expuesto en el editor; su default muted pertenece al renderer. No se añade al JSON editorial. `jakawi-club-landing-v1` conserva la misma allowlist y exclusiones operativas. Crear, duplicar, importar/exportar, guardar, preview y publicar siguen usando los endpoints existentes; /{slug} y /l/{slug} conservan su contrato. No hay migraciones ni actualizaciones de usuarios/landings LIVE.

## H. YouTube 150 y fuente real

Se conserva `https://www.youtube.com/watch?v=P42gDOTL6Ys`, procedente de la configuración existente. En las sondas LIVE finales de 390 y 1440 px se observa onReady, estados del proveedor sin iniciar/buffering y onError(150), tiempo real 0 antes/después, muted true. La UI termina en error y ofrece el enlace visible. No hay evento playing ni avance: reproducción y audio reales NO acreditados.

Las pruebas de estado utilizan fixtures identificados; las sondas finales utilizan la API, reloj y conexión nativos, sin respuestas simuladas del proveedor. [live-browser.json](live-browser.json), [live-browser-run.txt](live-browser-run.txt) y capturas [390](real-provider-390.png) / [1440](real-provider-1440.png). Una sonda intermedia también observó buffering sin avance; su recuperación motivó la clasificación separada de timeout descrita en D.

El antecedente del mismo error en el origen permanece documentado en su auditoría. No se afirma que jakawi.com reproduzca actualmente ese video. Según la [referencia oficial](https://developers.google.com/youtube/iframe_api_reference#onError), 150 equivale a 101; cambiar de host no acredita autorización de inserción.

## I. Pruebas

| Control | Resultado |
| --- | --- |
| TypeScript + Vite secuenciales | PASS, sin instalar dependencias |
| Motor, props, preferencias, curva, timeouts y teléfono | 60 comprobaciones PASS |
| Backend creador/editor/rutas/JSON/plantillas | 139 comprobaciones PASS, esquema PostgreSQL temporal |
| Cuentas, registro cerrado, referidos y CSRF | 73 comprobaciones PASS, esquema PostgreSQL temporal |
| Editor/preview real con respuestas de prueba e interceptación de mutaciones | 13 grupos PASS, 390/430/768/1440 |
| VSL/layout/estados/progreso/sticky/formulario/teléfono | 14 grupos PASS, 390/430/1024/1440, antes y después del despliegue |
| Proveedor real | Rechazo 150; NO se acredita reproducción/audio |
| Validadores local y HTTPS | PASS |

Las pruebas del VSL distinguen comando play de evento playing, audio solicitado de confirmado, doble clic, mute/unmute, seek cero, pausa/buffering, replay, cap 97/ended 100, reintento de API, error 150, reduced-motion, saveData y preview sin métricas. El reloj virtual se usa solo en fixtures de progreso. El formulario se verifica con envíos interceptados, búsqueda/teclado de países, foco de errores y E.164; no crea cuentas LIVE. Los antiguos entry points responsive delegan en estas pruebas. Los abortos de peticiones de previews sustituidas se tratan como cancelaciones esperadas.

## J. Despliegue e integridad

Publicación exclusiva de assets de club, conservando todos los chunks anteriores y reemplazando manifest al final mediante rename atómico. Reinicio exclusivo de jakawi-club-app para OPcache. [deployment.json](deployment.json) conserva los hashes. Sin migraciones, cambios de entorno, Traefik, DNS, Nginx, PostgreSQL ni otros contenedores.

Los validadores comprueban ambos /up HTTP 200, HTTPS/assets, APP_DEBUG=false, registro público cerrado y aislamiento. El verificador final compara los usuarios y registros de landing con un baseline fresco de esta operación; el baseline histórico del editor ya difería antes de modificar. No se sobrescribieron datos para satisfacer un baseline antiguo.

## K. Git y SHA LIVE

Repositorio exclusivo `new-jakawi-club`, rama main, push normal a origin/main. El SHA final se sella después del commit/push mediante `scripts/verify-vsl-live.py`, en `LIVE.json` ignorado para evitar autorreferencia. El sello exige árbol limpio, HEAD/origin/main/remoto iguales, todos los archivos montados coincidentes con Git y assets HTTPS idénticos al manifest. También comprueba rutas, cierre del registro, salud, hashes de la fuente y configuración de Traefik, identidad/redes de contenedores ajenos y datos de club sin cambios.

[Rollback preparado](ROLLBACK.md), no ejecutado.

## L. Pendientes reales

- Verificar la autorización de inserción del video aprobado con su propietario/proveedor y repetir una sonda real con playing, tiempo avanzando y mute/unmute confirmados. No sustituirlo por una fuente inventada.
- Pruebas físicas Safari/iPhone y Chrome Android para gesto/audio, inline, preferencias del sistema y teclado. Chromium no las certifica.

No quedan pendientes esenciales de implementación del VSL solicitado; estas limitaciones de proveedor/dispositivo permanecen explícitas.
