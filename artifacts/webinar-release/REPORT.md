# Integración y despliegue WEBINAR MVP

Original WEBINAR: `1cefba008037f9bc75df3b99b8616644e14bec70`.
Commit integrado: `97d69cbe7a79ae15f68ed3a3fa13e46a045db89c`.
Base vigente de main/origin/main y LIVE: `19cd737be5d5f4f07999de3e17862f0643318f07`.

La inspección inicial partió de `6c9bf28`. Durante la preparación, main avanzó con
`67fb696` y `19cd737` (enlaces cortos y su verificación LIVE). Se volvió a aplicar
WEBINAR sobre ese main, conservando íntegros los cambios económicos y los enlaces
cortos. Conflicto exclusivamente en `.gitignore`: se conservaron todas las reglas.
No hay conflictos materiales en la aplicación. No se usa reset ni push forzado.

Las únicas fuentes de aplicación que cambian respecto a esa base son las diez
previstas por WEBINAR. SSO, CRM, receptor comercial, rutas, paneles económicos,
modelo económico, enlaces cortos, infraestructura y dependencias permanecen iguales.

## Validación previa

- Landings: **303 PASS**; v1/v2, LEAD_VSL, WAITLIST, WEBINAR, snapshots, orden,
  visibilidad, fecha, preview, permisos, guardado de borrador y publicación aislada.
- Registro y confirmación: **134 PASS**; referido, UTM, activación con notificaciones
  simuladas, grupo posterior, video y snapshot del recibo, sin conversiones duplicadas.
- Motor económico: **82 PASS**. Afiliados: **64 PASS**. Receptor comercial: **62 PASS**.
  Enlaces cortos: **72 PASS**, incluida concurrencia real en el esquema temporal.
- Identidad CLUB: **55 PASS** con HTTP simulado y solicitudes externas bloqueadas.
  No se ejecuta un login real ni se crea un runtime de COM para esta entrega.
- Total PHP: **772 comprobaciones**, en esquemas PostgreSQL temporales.
- TypeScript y Vite: **PASS**, sin instalaciones. Build preparado en el worktree,
  incluyendo todos los cambios vigentes de main.
- Chromium con build preparado: **12 grupos WEBINAR PASS**, a 390/430/1440 px;
  paneles económicos y enlaces cortos a 390/1440 px sin errores JS, assets fallidos
  ni desbordes. Las mutaciones se interceptan únicamente en el navegador.
- `git diff --check`, sintaxis de runners y compilación del verificador: **PASS**.
- Antes de desplegar, los hashes de todas las tablas protegidas LIVE siguen iguales
  al baseline, incluida `marketing_landings`, usuarios, tokens, identidad y economía.

Incidencias de pruebas: una expectativa anterior pedía redirección para
`/r/no-existe`; los enlaces cortos vigentes restringen las invitaciones CLUB a
12 caracteres y devuelven 404 para ese formato. Se actualizó solo la prueba y se
comprobó además que un código CLUB inexistente de 12 caracteres sigue redirigiendo.

`validate-local.py` falla en la línea 52 antes del despliegue porque espera el
registro cerrado. El registro LIVE está abierto, como exige la configuración
vigente. No se cierra ni se altera el validador para hacerlo pasar.

## Operación

**Despliegue completado exclusivamente en JAKAWI.CLUB.** Fuente y validaciones
promovidas a main mediante fast-forward y push normal en
`c83f0196d59ee7a513d819e3451c945869c053dc`. El commit final de documentación y
capturas conserva exactamente esa fuente de aplicación; su SHA efectivo se
registra en `LIVE.json` y en `git rev-parse HEAD`, sin autorreferencia.

Mantenimiento: **4,72 segundos**. Se copiaron los chunks nuevos, se conservó cada
asset anterior y se reemplazó el manifest al final. Solo se reinició
`jakawi-club-app`. Sin migraciones, cambios de secretos/configuración,
instalaciones ni recreación de contenedores.

Verificación posterior:

- `/up` local y HTTPS: **200**. App, web y PostgreSQL CLUB: **healthy**.
- **414 archivos montados** coinciden con Git; **28 assets** de manifest coinciden
  byte a byte entre disco y HTTPS, incluido el editor WEBINAR nuevo.
- El controlador de creación en el runtime LIVE ofrece WEBINAR completo con
  nueve secciones y fecha vacía. Las rutas administrativas mantienen sus permisos.
- Navegador con **assets LIVE**: **15 grupos WEBINAR PASS**, con previews/administrador
  mediante fixtures y toda mutación interceptada. Incluye `/` y `/principal` reales
  a 390/1440 px, registro abierto y acceso de login SSO disponible.
- Paneles de comisiones/reglas/afiliados y enlaces cortos con **assets LIVE** a
  390/1440 px: sin errores JS, assets fallidos ni desbordes. Datos de fixture;
  formularios administrativos interceptados, sin sesiones LIVE privadas.
- Inicio SSO real de CLUB: redirección con callback registrado, PKCE S256, state
  y nonce. Callback inválido: rechazo hacia login. No se sigue la redirección a COM
  ni se realiza un intercambio de tokens o login real del proveedor.
- Los hashes de `marketing_landings` completos (borrador, textos, configuración,
  estado y snapshot publicado), usuarios, tokens, identidad, ofertas/enlaces,
  eventos comerciales, reglas, libro y auditoría permanecen iguales al baseline.
- Fuentes de SSO, CRM, receptor, economía y enlaces cortos intactas; secretos intactos.
  Web, PostgreSQL y contenedores vecinos conservan ID, inicio y contador de reinicios.
- `validate-local.py` sigue fallando exactamente en la misma línea 52 por registro
  abierto; se conserva ese estado operativo, sin intentar forzar la prueba.

**No se creó ni publicó ninguna landing WEBINAR, ni se modificó contenido editorial
existente, la landing principal o su snapshot publicado.** La única landing
preexistente conserva su fila íntegra. No hubo altas de prueba LIVE, correos,
publicación CMS, llamadas a CRM ni cambios de COM o Traefik.

Evidencia: [pruebas](tests.json), [operación](deployment.json),
[navegador WEBINAR LIVE](webinar-live/browser-results.json),
[paneles económicos LIVE](commission-tests/browser-live.json),
[enlaces cortos LIVE](short-links/browser-live.json).

Respaldo privado preparado en `/srv/sites/jakawi.club/rollback-webinar-mvp/`:
fuente anterior, assets anteriores, cachés y baseline de hashes. No se copian
secretos a Git, logs, imágenes ni artefactos públicos.

No se necesitan migraciones, cambios de configuración, instalaciones ni reinicios
de web o PostgreSQL. El despliegue realizado usó fast-forward de main, conservó
los chunks antiguos, cambió el manifest al final y reinició exclusivamente app.

El sello final `LIVE.json` se excluye de Git para evitar autorreferencia del SHA.
Las pruebas de navegador posteriores usan assets HTTPS reales con fixtures de
páginas administrativas y toda mutación interceptada. Se comprueban además las
portadas reales, registro abierto y acceso SSO disponible, sin enviar altas LIVE.
No se acredita reproducción real de YouTube, entrega SES ni login completo del
proveedor COM.
