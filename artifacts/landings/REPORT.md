# Creador de Marketing Landings — jakawi.club

Baseline confirmado: `9364f26244050d5202a74f3049c38171c914d001`, rama `main` limpia y sincronizada. Directorio correcto, usuario efectivo root, escritura temporal, Docker y conectividad operativos. Ambos `/up` respondían 200. Implementación y despliegue limitados a este proyecto.

## Funcionalidad

`MarketingLanding` conserva configuración editorial JSONB y un snapshot público independiente. Plantilla local `LEAD_VSL`, rutas `/l/{slug}`, portada `/` vinculada a `config('jakawi.home_landing_slug') = principal` y administración autenticada en `/admin/landings`. Crear, editar, duplicar, guardar, publicar y archivar están implementados. Los slugs son únicos y permanentes después de publicar; la portada no puede archivarse. Publicar exige la revisión guardada actual y bloquea la fila durante la operación.

El editor permite textos por sección y configuración independiente de URL YouTube, poster local, marco 3:4/16:9 y proporción original. Conserva hero responsive, alineación vertical desktop, sticky móvil, selector internacional, formulario, registro, identidad y atribución existentes. No incorpora el CMS ni reglas comerciales de jakawi.com.

Importación/exportación `jakawi-club-landing-v1` con lista cerrada de campos y validación del contenido final. Las importaciones parciales conservan campos omitidos y reemplazan listas completas. No admite HTML, CSS, precios, endpoints, permisos, estado público ni reglas de negocio. Guardar e importar no publican. El preview autenticado usa `Welcome` en marcos reales de 390, 430 y 1440 px, deshabilita registro y no envía eventos.

La campaña es un identificador opcional fuera del JSON; no había entidad de campañas en esta aplicación. Se agregan conteos por landing/campaña publicada sin datos personales, manteniendo los agregados originales y la transacción de registro confirmado.

## Verificación focalizada

- 83 comprobaciones backend nuevas: permisos de invitado/miembro/admin, verificación/activación, concesión/revocación, creación/edición/duplicación/archivo, JSON completo/parcial/inválido, slugs, preview, revisiones, publicación, portada, analítica y registro cerrado. PostgreSQL real en esquema temporal eliminado al terminar; cero usuarios de prueba LIVE (`backend-tests.txt`).
- 73 comprobaciones del flujo existente: registro, activación, login, recuperación, atribución permanente, referidos, analítica y CSRF (`mvp-tests.txt`). Notificaciones simuladas y esquema aislado.
- TypeScript estricto y Vite satisfactorios, ejecutados en secuencia y sin instalaciones (`frontend-build.txt`).
- Cinco grupos Chromium del editor/preview con fixtures de respuestas del kernel real y mutaciones interceptadas: guardar, previsualizar y publicar separados, errores de runtime ausentes y assets sin fallos (`editor-visual.json`).
- Once grupos responsive sobre la portada HTTPS real y fixtures identificados: 390, 430 y 1440 px, marco VSL, texto centrado verticalmente en desktop, sticky sin superponer video/formulario/teclado/diálogo, teléfono internacional, accesibilidad del formulario y estados del reproductor (`responsive/visual-validation.json`). En 430 px el marco más alto coincide inicialmente con la franja inferior: el sticky se oculta y reaparece al salir del video.
- `python3 scripts/validate-local.py` y `python3 scripts/validate-public.py` satisfactorios. Portada y `/l/principal` responden 200. POST HTTPS real a `/registro` con CSRF válido responde 403.

Los previews administrativos se validan con autenticación real en el kernel aislado y fixtures en Chromium; no se crea una cuenta administrativa LIVE para las capturas. La simulación de teclado/VisualViewport no sustituye una prueba física iOS/Android.

## Despliegue

Migración aditiva ejecutada únicamente después de comprobar `current_database() = jakawi_club`, debug deshabilitado y registro cerrado. Añade dos tablas y una columna administrativa con valor inicial false. Crea la portada publicada a partir del contenido y video locales existentes. No modifica usuarios ni referidos.

Se conservaron chunks anteriores, se publicó el manifest al final y se regeneraron cachés. Solo se reinició `jakawi-club-app` para OPcache; Nginx y PostgreSQL conservaron sus contenedores e inicio. No se editaron secretos, Compose, DNS, Traefik ni otros proyectos. La validación HTTPS confirma archivos existentes de Traefik y credenciales sin cambios. Servicios propios saludables; `https://jakawi.club/up` y `https://jakawi.com/up` responden 200.

El rollback privado conserva código, build y dump anteriores dentro de `/srv/sites/jakawi.club/rollback-landings-9364f262/`. [Procedimiento](ROLLBACK.md): revertir código/manifest y conservar datos aditivos; reiniciar solo app.

Commit, main remoto, árbol limpio, archivos montados, bytes de assets HTTPS, salud, usuarios intactos y cierre real se verifican después del push con `scripts/verify-landings-live.py`. El SHA efectivo queda en `LIVE.json`, excluido de Git para evitar autorreferencia.

## Pendientes reales

La base LIVE tenía y conserva cero usuarios. Falta definir/aprovisionar una cuenta administrativa real activada y verificada; `landings:admin` concede o revoca el permiso a cuentas existentes, sin inventar identidades ni abrir registro.

Se conserva el video aprobado `P42gDOTL6Ys`, sin poster aprobado. La restricción histórica de YouTube (código 150 al insertar) está documentada en `../vsl/REPORT.md`; no se repite la sonda externa ni se afirma reproducción real. Un propietario debe habilitar la inserción o aprobar otra fuente y repetir la comprobación correspondiente. No bloquea el creador ni la configuración editorial.
