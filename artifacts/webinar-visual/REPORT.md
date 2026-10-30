# WEBINAR: jerarquía visual y evento

Base: `9108f6e8fa8627f9888e51a48d8ff3878b869932`.
Cuatro fuentes de producción: CSS, Welcome, WebinarComposition y primitives.

- Franja verde de evento antes del header, con fecha de campaña en Bolivia o
  «WEBINAR GRATUITO · PRÓXIMAMENTE», y acceso al único registro con foco compartido.
- La sección de evento conserva su único timer, fecha real, estado vencido y
  ausencia de contador sin fecha. Título editorial, iconos de calendario/reloj/
  online y cajas legibles para días/horas/minutos/segundos. No afirma Zoom.
- Beneficios reutilizan Arrow/Play/RotateCcw existentes, decorativos y sin claves
  nuevas. Oportunidad oscura con acento coral; textos JSON intactos.
- Pasos conectados horizontal/vertical, con numeración e icono decorativo;
  orden y texto intactos. Formulario blanco, campos legibles, título/CTA claros;
  no se modifica su componente ni funcionamiento ni se añaden promesas/consentimientos.
- Overrides scoped a WEBINAR; sin cambios de player, crop, sticky, header,
  contratos/importador, LEAD_VSL/WAITLIST, confirmación ni lógica comercial.

Pruebas PHP en esquemas temporales, sin correo ni usuarios LIVE. Navegador a
390/430/1440, preview/público y estados con/sin fecha/vencido, colores, columnas,
iconos, pasos, formulario, CTA/sticky, importación v3 y páginas existentes.
Proveedor y mutaciones interceptados; no se acredita reproducción real.
`validate-local.py` sigue comparando un ID histórico anterior de app; el baseline
capturado para esta entrega comprueba el contenedor vigente y resto del stack.

Rollback CLUB en `/srv/sites/jakawi.club/rollback-webinar-visual`.
Despliegue fast-forward, chunks previos conservados y manifest al final, cachés y
reinicio solo app; sin migraciones. Verificación de tablas/editorial/snapshots/
usuarios/secretos, fuentes ajenas, salud y assets HTTPS. Sello final LIVE.json ignorado.

Pruebas previas: 419 landings + 134 postconversion PASS; TypeScript/build PASS;
23 grupos de navegador PASS con assets nuevos, sin mutaciones LIVE.
Se corrigió el tamaño de días del countdown para cifras largas antes del despliegue.

Código desplegado: `2b4ef11570e0c9f5b123776e85edbedb776e881e`.
LIVE: 26 grupos PASS, incluidos contador fechado a 390/430/1440, home/principal
y login. Solo app reiniciada, 20 assets nuevos, chunks anteriores conservados.
Contenido, snapshots, usuarios, tablas comerciales y secretos intactos.
Cero registros, correos reales o publicaciones de campañas.
