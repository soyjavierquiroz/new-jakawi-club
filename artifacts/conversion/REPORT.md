# Landing de conversión JAKAWI

Implementada y desplegada exclusivamente en `jakawi.club`, sobre el baseline `b4527e06012ddd1e9de769b21282a232fb9a0a99`. Registro público cerrado. No se modificaron DNS, Traefik, otros proyectos, datos reales de usuarios ni credenciales.

## Componentes y archivos

- `app/resources/js/components/marketing/primitives.tsx`: MarketingHero, MarketingCTA, MarketingBenefits, MarketingFAQ y MarketingFinalCTA. Adaptación selectiva de las primitivas oficiales y el cierre de `offer/blocks.tsx`, con contratos locales y sin OfferConversionTemplate ni su grafo de dependencias.
- `app/resources/js/components/media/jakawi-vsl-player/`: JakawiVslPlayer, motor YouTube oficial adaptado y tipos locales. Se conservaron parseo seguro, API, eventos reales, privacidad mejorada y controles VSL; se eliminaron cursos, Bunny, HLS, encuadres verticales y barra decorativa. No hay métricas de retención ni CTAs condicionados al progreso.
- `app/resources/js/components/forms/`: formulario Inertia existente, selector de país y adaptador E.164. Usa `libphonenumber-js@1.13.15` con metadatos max, sin tablas manuales ni geolocalización. Bolivia +591 inicial, búsqueda sin distinguir acentos, prefijos, banderas SVG locales, diálogo accesible, flechas/Enter/Escape y retorno de foco. Pegar un número extranjero no cambia el país; las extensiones no se aceptan.
- `app/public/marketing/flags/`: 245 assets SVG de `country-flag-icons@1.6.20`, licencia MIT y hashes. No añade dependencia al runtime. `scripts/prepare-country-flags.sh` permite recuperar la copia estática usando un contenedor limitado.
- `app/resources/js/pages/Welcome.tsx`, `Privacy.tsx` y `app/resources/css/app.css`: composición, privacidad y estilos con tokens oficiales, Montserrat, Instrument Sans y contexto light-conversion. CTAs de hero y cierre apuntan a `#registro`.
- `app/config/jakawi.php`, `.env.example` y `app/app/Support/LaunchSettings.php`: URL YouTube y poster configurables desde el mecanismo Laravel existente, con validación de fuente y poster local.
- `app/app/Services/MarketingAnalytics.php`, `MarketingEventController.php`, `RegistrationController.php`, `app/routes/web.php` y migración `2026_10_28_000002_create_marketing_event_totals.php`: conteos propios y confirmación transaccional del registro.

El contenido sigue el recorrido hero → VSL → beneficios → formulario → FAQ → CTA final. Conserva “JAKAWI — VIVE MÁS. GASTA MENOS.” No incorpora testimonios, precios ni descuentos inventados. El formulario usa `/registro`, con nombre, teléfono internacional, email y privacidad. No se modificaron la validación Laravel existente, los servicios de atribución, activación ni endpoints de cuentas.

## Video y contenido aprobado

No hay URL aprobada configurada; LIVE muestra un estado de presentación próxima, sin botón simulado, iframe, thumbnail remoto ni peticiones a YouTube. El player integrado inicia la carga al hacer clic, usa youtube-nocookie, 16:9, pausa/reanudación, sonido, repetición, pantalla completa, loading, error y retry. Solo un evento `playing` del proveedor origina `video_start`.

Pendientes: URL de YouTube y poster aprobados. La fecha del encuentro y los enlaces de comunidad continúan pendientes de confirmación; no se inventaron. La reproducción con contenido real LIVE queda pendiente de esa URL. Los estados del proveedor se probaron con fixtures aislados del navegador, sin publicar un video de prueba.

Configuración operativa detallada en `README.md`. Referencias primarias consultadas: [API de YouTube](https://developers.google.com/youtube/iframe_api_reference), [libphonenumber-js](https://github.com/catamphetamine/libphonenumber-js/blob/master/README.md) y [banderas estáticas](https://github.com/catamphetamine/country-flag-icons/blob/master/README.md). La procedencia del código oficial está en `provenance.json`.

## Analítica y datos

`POST /medicion` acepta únicamente `landing_visit`, `cta_click` y `video_start`, con ubicaciones permitidas, CSRF y rate limit. `registration_completed` solo se escribe desde el servidor, en la misma transacción que crea la cuenta. Los duplicados por email no cuentan como otra conversión.

`marketing_event_totals` contiene solo día (Bolivia), evento, ubicación y total. No incluye cuentas, nombres, teléfono, email, IP, códigos ni URLs de referido. El navegador se deduplica por sesión/día/ubicación mediante un digest HMAC en la caché local, con vigencia de 24 horas. Los agregados no pretenden medir retención ni resistir fraude. No se integran Meta Pixel, GA4 ni envíos de eventos a jakawi.com.

La migración es aditiva: crea únicamente la tabla de agregados. Los datos PostgreSQL, las atribuciones y las credenciales se conservan. No se crearon usuarios de prueba LIVE.

## Validación

- TypeScript y Vite: aprobados, secuencialmente en contenedores limitados. `frontend-build.txt`.
- 34 pruebas de unidad: parseo de YouTube, configuración nocookie/manual, eventos reales frente a comandos, errores, bloqueo, disposición, recuperación de API y normalización/validación telefónica. `unit-tests.txt`.
- 73 comprobaciones de backend en esquema PostgreSQL temporal eliminado al terminar, con correo simulado: configuración de YouTube/poster, analítica, deduplicación, conversión transaccional, validación internacional, consentimiento, referidos, duplicados, activación, login, recuperación, CSRF y cierre público. `backend-tests.txt`.
- 9 grupos de comprobaciones de navegador en 390, 768 y 1440 px: render, 16:9, ausencia de overflow, marca/fuentes, CTA único, cierre, selector/búsqueda/teclado/foco, E.164, envío interceptado, feedback y VSL diferido/loading/error/controles/eventos. Reduced-motion activo. Sin errores JavaScript ni assets HTTP fallidos. El fallo de red de YouTube del fixture es intencional. `visual-validation.json` y capturas PNG.
- HTTP/HTTPS, TLS, cookies, assets locales y banderas, `/medicion` con CSRF y `/registro` 403 con CSRF válido: aprobados. `local-validation.json` y `public-validation.json`.

Se corrigieron el retorno de foco con Escape, el foco del primer campo inválido tras desbloquear el formulario y la visibilidad de banderas sin depender de emojis del sistema.

## Despliegue y aislamiento

Se publicaron assets conservando los chunks anteriores y reemplazando el manifest al final. Se regeneraron cachés propias y solo se reinició `jakawi-club-app` para renovar OPcache. Nginx y PostgreSQL propios conservan su ciclo de vida. Los tres servicios están saludables. PostgreSQL continúa sin puertos publicados; APP_DEBUG=false y registro público cerrado, protegido además por la preparación de correo transaccional.

Los 40 contenedores ajenos conservan sus IDs y redes; 39 conservaron también el ciclo de vida durante la tarea. Se observó que `jakawi-worker-1` aumentó su RestartCount de 11 a 12, con inicio `2026-10-28T14:32:35.159703601Z` (10:32 en Bolivia), compatible con un reinicio automático por su política. No se ejecutó ninguna acción sobre ese servicio. Los validadores registran esa observación y mantienen los controles estrictos de identidad, redes, salud y ciclo de vida de web/db de jakawi.club.

Los 21 archivos de configuración de Traefik capturados permanecen idénticos. La referencia oficial consultada en solo lectura permanece idéntica por hashes. `https://jakawi.com/up` responde 200. No hubo cambios propios en jakawi.com ni consumo de APIs privadas.

Rollback preparado y no ejecutado: `ROLLBACK.md`. El sello `LIVE.json` se genera tras commit/push, fuera de Git para evitar autorreferencia. Verifica HEAD = origin/main = remoto, árbol limpio, archivos montados, hashes de assets HTTPS, salud, cierre, integridad de usuarios y configuración protegida. También actualiza el punto de entrada del IDE en `artifacts/identity/LIVE.json`.
