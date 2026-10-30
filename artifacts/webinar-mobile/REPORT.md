# WEBINAR: móvil, video antes del copy y sticky compacto

Base: `d4a3efe832aadfdd58e099cd7f8bf8fe551ed3b1`.

Cambios limitados a cinco fuentes: Header con clase opcional WEBINAR, renderer,
helper de CTA, clase del sticky y CSS móvil. El hero conserva DOM titular/video/copy;
el override móvil recupera ese orden y oculta CTA hero/nota asociada sin huecos.
Header de 56 px más borde, acceso de 44 px; logo mantiene proporción.
Sticky: hero.cta también en WEBINAR histórico, botón mínimo de 44 px, coral,
sombra leve, bounce máximo de 3 px durante una parte de cada ciclo de 7 s,
desactivado con reduced-motion. Safe-area, reserva inferior y scroll padding
permiten acceder al contenido y controles sin cubrirlos al desplazarse.
Conserva ocultación por formulario/teclado/modal/fullscreen y navegación al único
formulario. Desktop, LEAD_VSL, WAITLIST, confirmación, player/crop y JSON intactos.

Pruebas PHP en esquemas aislados, correo fake. Navegador con proveedor y mutaciones
interceptadas: geometría a 390/430/1440, preview/público, orden, header, crop 3:4,
CTA oculto móvil/visible desktop, sticky/bounce/reduced-motion, controles accesibles,
registro, importación v3 y regresión de plantillas. No acredita reproducción real.

Rollback de código/assets/cachés en `/srv/sites/jakawi.club/rollback-webinar-mobile`.
Despliegue habitual sin migraciones: fast-forward, conservar chunks, manifest al final,
cachés y reinicio solo app. Verificador compara contenido/snapshots/usuarios/tablas
comerciales/secretos y fuentes ajenas, además de HTTPS y salud CLUB.
`validate-local.py` conserva un ID histórico de app anterior a esta tarea;
la comprobación de esta entrega usa el baseline vigente capturado antes de editar.

Pruebas previas: 419 landings + 134 postconversion PASS; TypeScript/build PASS;
23 grupos de navegador PASS con assets nuevos, sin mutaciones LIVE.
