# Visibilidad de resultados en CLUB

`/afiliados?tab=results` incorpora **Mis resultados**, manteniendo Explorar, Mis
enlaces e Historial. Los enlaces propios muestran además sus clics por oportunidad.

## Clics

La ruta corta existente sigue siendo stateless, sin cookies ni sesiones. Solo
un GET hacia un destino válido de una oferta actualmente promocionable incrementa
`affiliate_link_daily_clicks`, con tres columnas: `link_code`, `day`, `clicks`.
La PK `(link_code, day)` y un único `INSERT … ON CONFLICT … clicks + 1` impiden
pérdidas entre procesos concurrentes. El día usa `America/La_Paz`. Los siete días
comprenden hoy y los seis anteriores, ambos incluidos.

HEAD, Purpose/Sec-Purpose/X-Purpose/X-Moz de prefetch/prerender/preview,
X-Inertia-Prefetch y previews/crawlers conocidos no incrementan. Tampoco lo hacen
pausas locales/COM, finalizadas, futuras, retiradas, códigos inexistentes o
destinos inválidos. Se mantiene el redirect temporal con atribución CLUB real.
El contador añade una escritura SQL, sin cola ni llamadas externas. Si solo
falla la escritura de telemetría, el redirect continúa; el log es un aviso genérico,
sin request, enlace, IP, email ni excepción de base de datos.

No se guardan datos del visitante, User-Agent ni identificadores de sesión en
los agregados. Los encabezados se inspeccionan en memoria. Nginx ya excluye de
access log las rutas cortas comerciales; no se cambia su configuración. Las
invitaciones CLUB originales de doce caracteres mantienen su ruta y comportamiento.

No hay backfill ni deduplicación por persona: son aperturas, no usuarios únicos.
Los enlaces CLUB de ocho caracteres cuentan su apertura antes de pasar por la
ruta de invitación original; no cuentan dos veces el salto posterior.

## Evidencia de resultados

- Clics: suma de agregados de enlaces cuyo propietario es el afiliado autenticado.
- Registros **CLUB**: cuentas con `users.referred_by_id` permanente, establecido por
  el registro existente. No se infieren registros COM ni se usan coincidencias de email.
- Canjes: operaciones COM `confirmed`, con referencia de tipo `redemption`, código
  CLUB y UUID verificados a favor del afiliado, mediante la consulta existente.
  Otros tipos de operación siguen en la lista de conversiones, sin etiquetarlos
  como canjes. Se aplica el último estado y una sola fila por referencia comercial.
- Comisiones: sumas decimales exactas por moneda, solo aprobadas sin revisión
  pendiente, con UUID y código del afiliado. Mismo criterio que el resumen del
  libro existente, sin tocar el motor de comisiones.

La administración conserva todos los eventos entregados por COM, incluidos los
que no tienen afiliado. El receptor, su firma y su verificación no cambian.

## URLs y navegación

La pestaña activa está en `?tab=explore|links|history|results`. Los enlaces del
dashboard y las páginas de conversiones/comisiones usan su URL visible y ancla.
Un visitante que abra `/afiliados#mis-conversiones` pasa mediante `replace` a
`/afiliados?tab=results#mis-conversiones` y abre esa sección. El fragmento solo
llega al navegador: el componente lo resuelve al montar/navegar y ante hashchange.
Los filtros se restauran desde las props de su URL. Back/Forward usa el historial
normal de Inertia y también restaura las anclas.

## Pruebas y operación

- `bash scripts/test-affiliate-results.sh`: esquema aleatorio, Query Builder y
  Eloquent verificados antes de migrar o escribir fixtures. Incluye 24 GETs entre
  tres procesos, límites de fecha, privacidad, estados, ausencia de cookies,
  fallo del contador, recibos/reintentos y proyección económica real aislada.
- `JAKAWI_EVIDENCE_DIR=/opt/sites/jakawi.club/artifacts/affiliate-results/attribution bash scripts/test-affiliates.sh`:
  regresión de atribución, cancelaciones, eventos sin referido y deduplicación.
- `bash scripts/build-frontend.sh`: TypeScript/Vite secuenciales y limitados.
- `bash scripts/check-affiliate-results-visual.sh`, luego `--live`: 390/1440,
  ancla heredada, hashchange, Back/Forward, filtros restaurados y métricas.
  Datos privados interceptados del kernel aislado; ningún usuario, canje o clic
  de prueba en LIVE.
- `bash scripts/deploy-affiliate-results.sh`: migración aditiva exclusiva, assets
  conservando chunks anteriores, cachés y reinicio solo de app. Sin cambios al
  timer del catálogo, SSO, registro, SES, COM, CRM ni infraestructura compartida.
- `JAKAWI_EVIDENCE_DIR=/opt/sites/jakawi.club/artifacts/affiliate-results python3 scripts/validate-local.py`.
- `python3 scripts/verify-affiliate-results-live.py`: SHA Git/remoto, código
  montado, HTTPS/assets, `/up`, datos y fuentes protegidas. Solo HEAD/prefetch
  sobre el enlace real, sin inflar contadores con aperturas de herramientas.

Rollback privado: `/srv/sites/jakawi.club/rollback-affiliate-results/`. Restaurar
su código/assets, regenerar cachés y reiniciar únicamente app. Conservar tabla
y métricas; no hacer rollback de la migración ni borrar datos históricos.
