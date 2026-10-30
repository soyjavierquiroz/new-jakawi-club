# Fase 07A — enlaces cortos permanentes

Piloto Dossis 43 implementado en CLUB. URL corta persistente de ocho caracteres
aleatorios, única por afiliado y oferta, sin IDs secuenciales. El destino vigente
se resuelve al visitar el enlace; la URL original queda conservada. Atribución
con el código CLUB real, HTTP 302 y `no-store`, sin sesión, cookies, clics,
conversiones ni datos personales del visitante. Nginx omite el acceso comercial
en su log propio. Las invitaciones CLUB de doce caracteres se conservan.

Estados `active`, `paused`, `finished`; los dos últimos muestran el aviso público
y un botón explícito para explorar COM. Ningún reemplazo silencioso de oferta.
Administración existente en `/admin/ofertas-comerciales`, con destino, estado y
consulta paginada de enlaces asociados. `/afiliados` presenta el enlace corto,
copia, WhatsApp, destino largo secundario e historial de ofertas finalizadas.
Las consultas y reglas de conversiones y comisiones siguen intactas.

Validación:

- 72 comprobaciones aisladas de enlaces, incluyendo concurrencia real,
  colisiones, códigos reales, idempotencia, parámetros, actualización de slug,
  estados, 404, permisos, destinos aprobados y conservación de datos históricos.
  Rutas compiladas desde el router de Laravel, conservando el esquema aislado.
- Regresiones: 64 afiliados, 62 receptor comercial y 82 motor económico.
- TypeScript y Vite correctos, sin dependencias nuevas. Nginx `-t` correcto.
- Navegador a 390/1440: avisos pausado/finalizado/404, panel, copia, WhatsApp,
  historial y administración; sin desbordes ni errores JS o assets fallidos.
  Fixtures desde kernel HTTP aislado; formularios interceptados sin escrituras LIVE.
- Estado final LIVE sin cuentas de prueba, ventas, eventos ni reglas nuevas. Sin APIs COM nuevas,
  importación de catálogo, cambios en COM, CRM, SSO, captación, receptor o pagos.

Publicado exclusivamente CLUB mediante fast-forward y push normal de `main`.
Migración aditiva aplicada, dos enlaces de cuentas reales preparados sin ventas
ni eventos. Primer enlace operativo: `https://jakawi.club/r/Ms8xvfMr`; HTTPS 302
al destino Dossis 43 con el código CLUB verificado, `no-store` y sin cookies.
`/up` local y HTTPS devuelven 200. Assets anteriores conservados y manifest nuevo
publicado al final. Solo PHP se reinició; Nginx CLUB recibió una recarga sin
reinicio para omitir los accesos comerciales. PostgreSQL y web no se recrearon.
Operación de despliegue: 10,79 segundos, incluido push. Navegador con assets LIVE
satisfactorio en 390/1440, con formularios interceptados. Tablas protegidas de
usuarios, eventos, reglas, libro, auditoría y trabajos coinciden con el baseline.
Commit de implementación: `67fb6969abe8b3f90a1938cca375f77b5402bcae`.
SHA efectivo final y comprobación remota: sello local `LIVE.json` generado
mediante `python3 scripts/verify-short-links-live.py` después del último push.
Respaldo privado: `/srv/sites/jakawi.club/rollback-short-links-07a/`.
El sello `LIVE.json` local ignorado contiene el SHA efectivo posterior al push,
la URL corta verificada, `/up`, hashes de fuente montada, assets HTTPS y datos
protegidos. Ver operación en `docs/integrations/club-short-links-07a.md`.

Incidencia corregida durante una validación adicional: `route:cache` arranca otra
aplicación Laravel y reemplazó el resolver estático de Eloquent, provocando tres
altas de fixture en la base LIVE. Se retiraron únicamente esas tres cuentas con
selección cerrada por ID, nombre, dominio example.test y ventana de creación.
Los hashes de usuarios, eventos, reglas, libro, auditoría y trabajos volvieron
exactamente al baseline previo. No hubo ventas, conversiones ni comisiones.
El arnés ahora compila las rutas directamente y comprueba explícitamente que
Query Builder y Eloquent conservan el esquema temporal antes de crear fixtures.
