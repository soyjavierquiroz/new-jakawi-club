# WEBINAR: refinamiento de header, progreso, fecha y contador

Base: `64f82b4eb0acc937fc05bb6258065c986cd38b74`.
Cuatro fuentes de producción: CSS, Welcome, WebinarComposition y primitives.

- Eliminada la franja superior y su componente/CSS; sin reemplazo.
- Header WEBINAR: 48 px móvil / 60 px desktop más borde, logo proporcional y
  enlace de cuenta con área táctil mínima de 44 px. Otras páginas intactas.
- Progreso decorativo verde del sistema mediante selector scoped a WEBINAR.
  No se modifica el player, su proveedor, crop, lógica ni el CTA coral.
- Countdown: cuatro cajas crema-amarillo, números con idéntica fuente, peso,
  tamaño, interletraje, altura y alineación; sin excepción para días. Conserva
  fecha real, único contador y estados pendiente/vencido.
- Fecha de campaña en Bolivia como microcopy inmediatamente después del CTA
  desktop, en lugar del bloque repetido de encuentro. Sin fecha se omite;
  en móvil se oculta junto con el CTA hero y se conserva la tarjeta de evento.

Pruebas PHP focalizadas en esquemas temporales y correo fake. Navegador a
390/430/1440, preview/público: ausencia de barra, header, progreso verde,
CTA coral, fecha condicional, números/cajas homogéneos sin overflow,
hero/sticky/registro/importación y regresión de plantillas. Proveedor y
mutaciones interceptados; no se acredita reproducción real.

Rollback CLUB en `/srv/sites/jakawi.club/rollback-webinar-refinement`.
Fast-forward, chunks anteriores conservados, manifest al final, cachés y reinicio
solo app; sin migraciones. Verificador protege editorial/snapshots/usuarios/
tablas comerciales/secretos y fuentes ajenas, servicios y assets HTTPS.
El validador local histórico conserva un ID anterior de app; se contrasta el
baseline vigente de esta entrega. Sello final en LIVE.json ignorado.

Pruebas previas: 419 landings + 134 postconversion PASS; TypeScript/build PASS;
23 grupos de navegador PASS, sin mutaciones LIVE.
