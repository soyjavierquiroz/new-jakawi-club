# Optimización del editor de Marketing Landings — jakawi.club

Baseline verificado antes de intervenir: `95bb8a4d65a4ca01e5419799dbb47d67404e0f5c`, main limpio, origin/main sincronizado, archivos montados y assets HTTPS coincidentes. El verificador histórico de landings tenía una comparación de usuarios desactualizada; el baseline nuevo confirma una cuenta existente y registro cerrado. Se conserva una copia privada de código/manifest para rollback. No se consultó código ni backend de jakawi.com.

## A. URLs y compatibilidad

`GET /{slug}` sirve únicamente landings publicadas con slug válido. Se registra al final y reserva tanto namespaces explícitos como las raíces de rutas existentes de todos los métodos. Las nuevas rutas literales reservan su espacio automáticamente; una prueba añade un endpoint futuro y confirma el rechazo del slug. `GET /l/{slug}` devuelve 301 conservando query solo para landings públicas existentes; las inexistentes, borradores y archivadas responden 404.

Los enlaces del listado/editor apuntan a URLs raíz. `/login` conserva 200, GET `/registro` conserva 405, POST `/registro` sigue cerrado, `/r/...` conserva su redirección y `/up` sigue activo. No se modifica autenticación ni atribución.

## B. Portada integrada

`/` y `/principal` leen el mismo registro y snapshot publicado y usan `Welcome`, igual que la preview. Guardar conserva la versión pública; publicar aplica el mismo cambio a ambas rutas. `principal` no admite cambio de slug ni archivo; no hay endpoint de eliminación. El listado destaca **PORTADA PRINCIPAL**. No se crean copias de portada ni se cambia contenido LIVE durante este despliegue.

## C. Editor

Dos columnas desde 769 px: contenido a la izquierda y preview automática del último borrador guardado a la derecha. Hasta 768 px utiliza pestañas Editor/Preview. Controles 390, 430 y Desktop (viewport real 1440), escala visual para ajustar al panel y enlace para abrir a tamaño completo. La preview utiliza el renderer público, con registro y analítica deshabilitados.

Grupos de identificación, mensaje, presentación, medios, beneficios, registro, FAQ, cierre y publicación/campaña. Identificación, mensaje y plantilla abiertos inicialmente; opciones restantes plegadas. Guardar y previsualizar permanecen cerca del contenido; guardar y publicar siguen siendo independientes. Estados de cambios sin guardar, borrador, publicación y cambios pendientes. Comparación de snapshots independiente del orden de claves JSONB.

## D. Brief y JSON

Tres herramientas visibles al inicio, también al crear una landing. Brief propio con identidad, objetivo, campaña, plantilla, contenido actual, condiciones conocidas y estado real del registro. Declara el público no documentado y prohíbe inventar ofertas/testimonios/fechas. No incluye identidad del administrador, credenciales o medios. No hay integración de IA.

Importación por texto o archivo de hasta 64 KiB, esquema `jakawi-club-landing-v1`, errores por campo y aplicación explícita al formulario. Tanto el endpoint nuevo como el histórico de importación son de validación sin persistencia. Los campos omitidos se conservan y las listas completas se reemplazan. Rechaza propiedades desconocidas y operativas, video/poster/campaña/estado, HTML, tipos y listas inválidos. Exportación, copia y descarga incluyen los valores actuales sin guardar; excluyen medios y operaciones. El endpoint histórico de exportación sigue representando el borrador guardado. Los archivos antiguos que contienen video deben retirar esa sección antes de importar.

## E. Plantillas

- **LEAD_VSL:** composición original, VSL vertical/horizontal configurable, beneficios, formulario, FAQ, cierre y sticky móvil.
- **WEBINAR:** hero de encuentro, agenda/contenido aprobado en beneficios, video opcional, formulario y FAQ. Fecha/hora solo desde la configuración confirmada existente; no crea eventos Zoom.
- **WAITLIST:** hero compacto, formulario antes de hasta tres beneficios, FAQ opcional y sticky. Conserva textos adicionales y video para volver a otra plantilla.

Todas reutilizan componentes, tokens, teléfono internacional, registro, referidos y snapshots. Cambiar de plantilla no borra contenido; duplicar genera otro borrador. La presentación se almacena dentro del JSON existente. Snapshots anteriores reciben LEAD_VSL en memoria; no hay migraciones.

## F. Pruebas y capturas

- `backend-tests.txt`: 139 comprobaciones con kernel HTTP real y PostgreSQL en un esquema temporal; eliminado al terminar. URLs/reservas/alias, permisos, portada, publicación, JSON parcial/completo/inválido y plantillas.
- `mvp-tests.txt`: 73 comprobaciones focalizadas de login, registro cerrado, activación, referidos y CSRF; sin usuarios LIVE.
- `frontend-build.txt`: TypeScript estricto y Vite secuenciales en contenedores limitados, sin instalaciones.
- `editor-visual.json` y `browser-tests.txt`: Chromium con respuestas administrativas obtenidas del backend aislado y mutaciones interceptadas. Dos columnas, pestañas, preview real, JSON local, errores, copia, descarga, archivo y las tres plantillas en 390/430/768/1440. Sin escrituras LIVE ni eventos desde preview.

Capturas principales: [editor desktop](editor-1440.png), [editor móvil](editor-390.png), [preview móvil](editor-preview-390.png), [errores JSON](json-field-errors.png). Las capturas `renderer-*-1440.png` muestran cada plantilla a tamaño real usando fixtures; nunca se publican landings de prueba.

La regresión responsive pública y las verificaciones finales de despliegue se añaden al completar la operación.

## G. Registro y referidos

Se conserva el registro público cerrado y la cuenta existente. No se modifican controladores/modelos de autenticación, registro o referidos. Las pruebas aisladas verifican el comportamiento existente sin enviar notificaciones reales ni crear usuarios LIVE.

## H–J. Despliegue, Git y pendientes

Preparado para despliegue exclusivamente de jakawi.club, conservando chunks anteriores y publicando el manifest al final. Sin migraciones, cambios de secretos, infraestructura, Traefik, DNS, jakawi.com ni servicios compartidos. [Rollback](ROLLBACK.md). El SHA LIVE se sellará después del commit/push con `scripts/verify-editor-live.py`, que comprueba Git limpio/sincronizado, archivos montados, assets HTTPS, rutas, salud y registros sin cambios. `LIVE.json` queda ignorado para evitar autorreferencia.

Pendientes ajenos a este cambio: restricción histórica de inserción de YouTube del video aprobado, documentada en `../vsl/REPORT.md`; no se afirma reproducción real ni se cambia la fuente. No hay público objetivo segmentado, poster ni fecha de encuentro documentados en el creador; no se inventan. Las pruebas Chromium no sustituyen una revisión física iOS/Android.
