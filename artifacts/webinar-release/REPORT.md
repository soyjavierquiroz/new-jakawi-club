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

Estado de esta sección: pendiente de completar al verificar LIVE.

Respaldo privado preparado en `/srv/sites/jakawi.club/rollback-webinar-mvp/`:
fuente anterior, assets anteriores, cachés y baseline de hashes. No se copian
secretos a Git, logs, imágenes ni artefactos públicos.

No se necesitan migraciones, cambios de configuración, instalaciones ni reinicios
de web o PostgreSQL. El despliegue previsto usa fast-forward de main, conserva
los chunks antiguos, cambia el manifest al final y reinicia exclusivamente app.

El sello final `LIVE.json` se excluye de Git para evitar autorreferencia del SHA.
Las pruebas de navegador posteriores usan assets HTTPS reales con fixtures de
páginas administrativas y toda mutación interceptada. Se comprueban además las
portadas reales, registro abierto y acceso SSO disponible, sin enviar altas LIVE.
No se acredita reproducción real de YouTube, entrega SES ni login completo del
proveedor COM.
