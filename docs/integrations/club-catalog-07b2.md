# Catálogo comercial CLUB · Fase 07B2

Fuente pública: `GET https://jakawi.com/api/catalog/v1/offers`.
[Contrato COM v1](COM_CLUB_CATALOG_V1.md), copiado en lectura del documento
indicado por COM. SHA de publicación COM comunicado: `b372212663f7cbc81f473550881864af19d0e146`.

`catalog:sync` recorre `links.next` completos, sin cursor `updated_at`. Valida
identidades, metadatos, número de filas/páginas, duplicados, destinos y formato
antes de escribir. La sustitución se aplica en una transacción. Un error, ciclo,
respuesta truncada, página fallida o cambio de conteo conserva todo el catálogo
válido previo; registra solo resultado/fecha/conteos. El contrato COM no ofrece
snapshot entre páginas: se converge con el próximo recorrido íntegro. Un lock
PostgreSQL impide sincronizaciones COM simultáneas.

`commercial_offers.source_id` conserva el ID COM (`benefit:<ID>` o
`experience:<ID>`), con unicidad por origen. La clave interna `dossis-43` se vincula
a `benefit:1` sin cambiar ninguna FK, enlace, usuario, conversión o comisión.
Los cambios de slug actualizan solamente el destino vigente. `original_url`
permanece. La sincronización no crea enlaces personales.

Disponibilidad efectiva compartida por catálogo, filtros, generación y redirección:

| COM | CLUB |
| --- | --- |
| available / scheduled | Promocionable |
| upcoming | Próximamente |
| paused / unavailable | No disponible |
| expired / ended / archived | Finalizada |
| Ausente tras recorrido íntegro | Retirada; conserva registro/enlaces |

Publicación pausada/archivada y fin de vigencia restringen promoción. La pausa
local solo restringe; desmarcarla no anula COM. `scheduled` no promete cupos ni
reservas. Los enlaces detenidos muestran su información actual en CLUB, sin
redirección automática a otra oferta. No hay consultas al feed en visitas/clics.

`/afiliados`: Explorar (búsqueda nombre/todos los Partners, filtros, 12 tarjetas
por página), Mis enlaces y Historial. Las dos últimas conservan las conversiones
y el libro de comisiones **del afiliado completo**, sin inventar asociación por
oferta que el contrato comercial histórico no proporciona. Las consultas de
enlaces, accesos rápidos, conversiones y comisiones están limitadas al usuario.
El POST autenticado `/afiliados/enlaces/{offer}` exige cuenta activa/verificada,
código real y promoción disponible, y crea/reutiliza la pareja afiliado/oferta
con la unicidad existente. Los enlaces COM añaden el `club_ref` real conservado.

Captación CLUB usa origen separado y categoría `Captación`. Solo se incluye la
landing principal publicada porque el mecanismo actual `/r/{código CLUB}` dirige
a esa landing y captura su referente real. Su enlace corto pasa por esa ruta;
no usa `club_ref`. Una landing archivada deja de redirigir incluso antes del
próximo recorrido. Otras landings quedan excluidas hasta tener un mecanismo
personal válido. Los editores y las rutas públicas no cambian.

Administración existente: `/admin/ofertas-comerciales`, paginada; muestra origen,
ID, estados COM/efectivo, destino, última sincronización válida/intento y enlaces
asociados. Solo permite modificar la pausa local.

## Operación y validación

- `bash scripts/test-catalog.sh`: HTTP kernel real, PostgreSQL con esquema aleatorio;
  conexiones Query Builder/Eloquent verificadas antes de migrar o crear fixtures.
- `bash scripts/build-frontend.sh`: TypeScript/Vite secuenciales y limitados.
- `bash scripts/check-catalog-visual.sh`, y `--live` tras publicar assets:
  navegador 390/1440 con fixtures interceptados, sin sesiones ni escrituras LIVE.
- `python3 scripts/validate-local.py`.
- `python3 scripts/verify-catalog-live.py`: SHA remoto/local, código montado, assets,
  enlaces anteriores, tablas protegidas y salud CLUB; sello sin autorreferencia.

Regresión de atribución existente: `JAKAWI_EVIDENCE_DIR=/opt/sites/jakawi.club/artifacts/catalog-07b2/attribution bash scripts/test-affiliates.sh` (65 comprobaciones aisladas).

La nueva migración es aditiva. `bash scripts/deploy-catalog.sh` aplica exclusivamente esta entrega de CLUB. Tras aplicarla, ejecutar `catalog:sync`, publicar
`build-next` conservando los chunks anteriores y el manifest al final, cachear
rutas/configuración/vistas y reiniciar solo `app` para OPcache.

Programación exclusiva CLUB: `scripts/systemd/jakawi-club-catalog.{service,timer}`;
`OnCalendar=*:0/10`, persistente. Invoca `scripts/sync-catalog.sh` en contenedor
propio efímero, 192 MiB/0.35 CPU, sin herramientas globales. `systemctl status
jakawi-club-catalog.timer` muestra la programación; `journalctl -u
jakawi-club-catalog.service` muestra únicamente conteos/mensaje seguro.

Rollback privado: `/srv/sites/jakawi.club/rollback-catalog-07b2/`. Detener el timer,
restaurar assets/código del baseline y regenerar cachés/reiniciar solo app. Antes
de volver al controlador 07A, pausar conservadoramente sus ofertas comerciales
(`status=paused`) para impedir que estados heredados anuncien disponibilidad de
COM. No revertir la migración ni borrar catálogo o enlaces históricos.
