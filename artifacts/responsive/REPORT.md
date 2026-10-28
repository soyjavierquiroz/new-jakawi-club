# Landing responsive de conversión — jakawi.club

Baseline verificado antes de editar: `228659790c781ca9ec6dd1570e3f69809c96fb3f`. Git limpio, HEAD = origin/main = remoto, archivos Git montados en PHP y hashes de assets HTTPS coincidentes. El sello anterior tenía referencias de contenedores obsoletas; se conservó en el rollback y se capturó una nueva referencia antes de modificar código. La landing VSL, formulario internacional y motor YouTube ya existían efectivamente en LIVE y se reutilizaron.

## Resultado

- **Mobile:** eyebrow y titular centrados; un único video 3:4 inmediatamente después; descripción y CTA debajo. Márgenes de 16 px a 390 px, sin alturas fijas ni espacios excesivos antes del video.
- **Desktop desde 1024 px:** dos columnas, bloque de texto alineado a la izquierda y centrado verticalmente contra el reproductor; video con ancho máximo de 26 rem. Una ventana baja permite scroll natural.
- **Sistema visual:** logo original, Montserrat, Instrument Sans, crema/tinta/coral y radios/sombra de los tokens locales. Sin colores HEX en TSX, dependencias nuevas ni cambios de infraestructura.
- **Acción principal:** todos los CTA llevan a `#registro`, ahora situado en la tarjeta del formulario existente. La región recibe foco con `preventScroll`, seguida de una única navegación suave o instantánea según reduced-motion. No se enfoca un campo deshabilitado ni se abre el teclado para leer el aviso de cierre.

## MobileStickyCTA

Componente reutilizable con `targetId`, activo solo por debajo de 768 px y montado exclusivamente en la landing. Botón coral de ancho completo «QUIERO SER PARTE», superficie oficial, z-index 20, safe area y espacio compensatorio permanente al final.

IntersectionObserver oculta la acción al entrar el formulario en pantalla, con margen preventivo inferior. También detecta el reproductor activo visible y la franja que ocuparía el CTA sobre el video, incluso sin URL configurada. Se oculta ante inputs en edición, reducción de VisualViewport compatible con teclado, diálogo modal, foco del reproductor y fullscreen. Vuelve a aparecer al terminar esas condiciones. Los observadores y listeners necesarios solo se conectan en móvil y se limpian al cambiar de breakpoint o desmontar; sin polling ni listeners de scroll.

No se añade una ubicación nueva al backend de métricas: el CTA móvil comparte la categoría `final` existente. No se mide retención ni se cuentan clics como registros.

## Reproductor y formulario

JakawiVslPlayer conserva el motor/API y sus controles. Solo se amplía la geometría mediante `aspectRatio` (`3:4` o `16:9`) y `sourceAspectRatio` (las mismas opciones). La landing usa presentación 3:4; el encuadre de fuente predeterminado es 16:9 para conservar segura una fuente horizontal. Ajustar `sourceAspectRatio` al formato aprobado cuando se incorpore el video. El iframe no se escala para llenar ni se recorta; el poster usa `object-fit: contain` y el mismo marco. Fullscreen conserva la proporción dentro del viewport.

**LIVE no tiene URL ni poster aprobados.** Se muestra el estado próximo, sin botón de reproducción ficticio, iframe ni solicitudes YouTube. Se conservan `JAKAWI_VIDEO_URL` y `JAKAWI_VIDEO_POSTER` y su validación local existente. No se modifica configuración ni se publica contenido de prueba.

El selector internacional existente conserva Bolivia +591 inicial, banderas locales, búsqueda, teclado, diálogo, retorno de foco y normalización E.164. Los campos visibles mantienen al menos 48 px. No se cambian endpoints, validación de registro, usuarios, referidos, pagos ni migraciones. No se crean cuentas LIVE.

**Registro público cerrado.** Aviso próximo y fieldset deshabilitado; POST /registro con CSRF válido devuelve 403. Se mantienen ambas condiciones de apertura y correo SMTP real aprobado.

## Evidencia

- TypeScript y Vite secuenciales en los contenedores limitados existentes: `../conversion/frontend-build.txt`.
- 34 pruebas focalizadas del motor YouTube y teléfono: `../conversion/unit-tests.txt`.
- 73 comprobaciones backend en esquema PostgreSQL temporal, eliminado al terminar: `../conversion/backend-tests.txt`. Incluyen activación, login, dashboard, recuperación, referidos y cierre; cero usuarios de prueba LIVE.
- Chromium local reutilizado mediante el sistema de capturas existente, sin instalaciones: 390, 768, 1024 y 1440 px, más escritorio bajo y móvil bajo. `../conversion/visual-validation.json` y `visual-run.txt` documentan geometría, un solo player/formulario, fuentes, tokens, ausencia de overflow, foco, reduced-motion, sticky, selector, errores/loading y ciclo de vida VSL mediante fixtures aislados del navegador.
- Capturas: `../conversion/landing-390.png`, `landing-768.png`, `landing-1024.png`, `landing-1440.png`; estados de formulario, selector, fullscreen y CTA en el mismo directorio. Capturas de viewport: `hero-390.png` y `hero-1440.png`.
- `python3 scripts/validate-local.py` y `python3 scripts/validate-public.py`: resultados en `../conversion/local-validation.json` y `public-validation.json`. HTTPS, assets, /up = 200 en ambos dominios y registro cerrado.

## Despliegue y Git

Solo se publican assets de jakawi.club: se conservan chunks anteriores y se cambia el manifest de forma atómica. Únicamente se reinicia app para OPcache. Sin migraciones, instalaciones, cambios de Compose/Traefik, reinicios ajenos ni escrituras en jakawi.com. Configuración protegida, credenciales y digest de usuarios se comparan con el baseline actual.

Commit y SHA LIVE final se registran después de commit/push en `../conversion/LIVE.json` y `../identity/LIVE.json` (excluidos de Git para evitar autorreferencia). El verificador exige árbol limpio, HEAD = origin/main = remoto, blobs montados, entrada HTML y assets HTTPS coincidentes, servicios saludables, continuidad de jakawi.com, usuarios intactos y registro cerrado.

## Limitaciones reales

Faltan video y poster aprobados; la reproducción real LIVE queda pendiente de esos contenidos. Las pruebas YouTube son fixtures exclusivos de navegador. El teclado y VisualViewport se verifican mediante simulación Chromium; no sustituyen una comprobación física en iOS/Android con su teclado y safe area nativos. No se dispone de una captura/URL de la landing de pádel en esta solicitud; se aplica la composición explícitamente indicada con la identidad local oficial.
