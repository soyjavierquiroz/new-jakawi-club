# Homologación visual de JAKAWI

Desplegada exclusivamente en `https://jakawi.club`, el 28 de octubre de 2026 (Bolivia). Baseline inicial: `main` y LIVE `5e3ccd77f7611cde8ac807165bda9ffda99523f2`, árbol limpio y sincronizado; blobs, montaje de aplicación en solo lectura, manifest y assets HTTP verificados antes de editar.

## Referencia oficial y adaptación

Consulta de solo lectura en `/root/jakawi-build-v1/repository/`, SHA `0bcda5842a0d1b9df93437784d7b3ad0fbb53fde`:

- `app/resources/css/theme.css`: paleta y contexto `light-conversion`, superficies, bordes, radios, espacios, sombras y CTA WhatsApp.
- `app/resources/css/app.css`: Tailwind 4, familias tipográficas, utilidades y foco.
- `app/resources/js/layouts/marketing-layout.tsx`, `layouts/auth/auth-simple-layout.tsx`, `components/marketing/primitives.tsx`, `pages/coming-soon.tsx` y componentes UI `button`, `input`, `card`: tipografía efectiva, jerarquía, controles y composición editorial.
- `components/city-community-card.tsx`: icono WhatsApp original, sin cambios geométricos.
- `public/jakawi-logo.svg` y `public/jakawi-mark.svg`: originales completos, proporciones y colores sin alterar. No se reconstruyó la marca con texto.

Montserrat 400/500/600/700/800 para marketing y páginas; Instrument Sans 400/500/600 para campos. Los ocho WOFF2 proceden del build oficial existente y coinciden byte a byte con los assets públicos oficiales. Se versionan localmente para conservar independencia. Procedencia y hashes: [assets.json](assets.json).

La única paleta local está en `app/resources/css/jakawi-theme.css`. `app.css` importa los tokens y `jakawi-fonts.css`. Tailwind expone utilidades semánticas con `@theme inline`; las formas usan los radios oficiales de tarjeta (22 px), control (16 px) y chip. El contexto marketing se fija en el elemento HTML para cubrir todas las rutas.

Botones, CTA WhatsApp, inputs, checkbox, mensajes, tarjetas, header, footer y enlace de referido comparten los estilos. Aplicados a landing/registro, bienvenida, login, activación, recuperación/restablecimiento, dashboard/perfil, privacidad y preferencias. Se conserva todo el contenido y las reglas de los flujos; no hay modificaciones en controladores, rutas, modelos, configuración, migraciones ni dependencias.

Adaptaciones de accesibilidad: blanco sobre coral con texto de 20 px y peso 800 (contraste 3.08:1 para texto grande); hover coral derivado hacia tinta. El foco de campos conserva borde coral y añade contorno tinta sobre crema. WhatsApp utiliza exclusivamente sus tokens funcionales (contraste blanco 5.30:1 normal y 4.68:1 hover). Inputs de 16 px/48 px de alto; botones de al menos 52 px; movimiento reducido respetado.

## Validación

- [TypeScript y Vite](frontend-build.txt): correctos, ejecutados secuencialmente mediante `scripts/build-frontend.sh`, sin instalar dependencias. CSS generado con tokens y utilidades semánticas; sin HEX en TSX ni paletas duplicadas en CSS de componentes.
- `scripts/test-mvp.sh`: **46 comprobaciones correctas** en esquema PostgreSQL temporal aislado, eliminado al terminar; notificaciones simuladas y **cero usuarios LIVE de prueba**. Incluye consentimiento, primer referente inmutable, registro, bienvenida, activación y expiración, login/logout, recuperación, perfil, sesiones, CSRF y cierre del registro. Sin migraciones sobre el esquema LIVE.
- [Validación local](local-validation.json) y [pública](public-validation.json): correctas. HTTP/Inertia, TLS/redirecciones, 16 entradas de assets incluidos WOFF2 con MIME correcto, configuración production segura, registro cerrado con CSRF válido, rutas públicas y redirección de dashboard para visitantes.
- [Validación visual](visual-validation.json): **36 comprobaciones** a **390, 768 y 1440 px**, sin overflow ni imágenes rotas, fuentes oficiales, colores efectivos, radios, tamaño de campos, foco de teclado y `prefers-reduced-motion`. Envío interceptado solo en el navegador para comprobar loading, error y desbloqueo sin enviar registros al backend. Los estados privados y la apertura del formulario son fixtures del navegador; no se cambiaron los flags LIVE.
- Capturas comparadas: [landing móvil](landing-390.png), [landing escritorio](landing-1440.png), [oficial móvil](official-390.png), [oficial escritorio](official-1440.png), [login](login-390.png), [dashboard](dashboard-390.png), [bienvenida](registered-390.png) y [privacidad](privacy-390.png). También se incluyen sus variantes de escritorio.
- [Logo e isotipo](asset-http.json): HTTP 200 y contenido idéntico a los originales. `https://jakawi.club/up` y `https://jakawi.com/up`: **200**.

Los validadores ahora admiten assets binarios WOFF2 y su MIME. Se usa el baseline capturado **antes de este trabajo**, conservado localmente e ignorado por Git, en lugar del histórico del MVP: el worker ajeno ya había cambiado su inicio y contador antes de esta tarea. Se verificaron sin cambios **40 contenedores ajenos y 19 archivos protegidos de Traefik**, además de las credenciales, identidad/redes de los servicios propios y ciclo de vida de PostgreSQL. No se operaron otros proyectos ni Traefik.

El runner visual reutiliza Chromium ya instalado, fuentes y bibliotecas de sistema en solo lectura dentro de un contenedor temporal limitado. No instaló herramientas ni publicó puertos. Su primer arranque requirió exponer las fuentes de sistema al contenedor y limitar los procesos del navegador; las comprobaciones finales fueron satisfactorias.

## Publicación, Git y pendientes

Assets publicados secuencialmente desde `public/build-next`; chunks anteriores conservados y manifest reemplazado al final. Solo se limpió la caché de vistas y se reinició `jakawi-club-app` para OPcache. No se crearon infraestructura, redes, migraciones ni servicios permanentes. Copia privada previa de frontend/assets: `/srv/sites/jakawi.club/releases/identity-before/`; restaurar solo esos recursos y el commit correspondiente si se necesita revertir esta identidad, sin tocar datos ni infraestructura.

Commit descriptivo y push normal a `origin/main`; el SHA efectivo, coincidencia de blobs montados y assets HTTP se sellan después del commit en `artifacts/identity/LIVE.json` (local e ignorado, para evitar autorreferencia). Un push por sí solo no se considera evidencia de despliegue.

**Registro público CERRADO**, `APP_DEBUG=false`, correo `log` y habilitación de correo transaccional desactivada. Video, evento/Zoom y WhatsApp conservan sus estados vacíos hasta disponer de contenido aprobado. **Sin pendientes visuales bloqueantes**; estos contenidos y la apertura no forman parte del cambio visual. `jakawi.com` conserva código, tema, contenedores e infraestructura sin modificaciones.
