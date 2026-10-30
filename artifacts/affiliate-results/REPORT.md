# Visibilidad de resultados · JAKAWI.CLUB

Contadores por enlace y día de Bolivia en la ruta corta activa, mediante un solo
upsert atómico. Solo se guardan enlace, día y número de aperturas. Sin cookies,
IP, email, User-Agent o identificador del visitante. HEAD, prefetch/previews
conocidos y páginas pausadas/finalizadas/retiradas no se cuentan. Los fallos del
contador permiten continuar el redirect válido. No hay reconstrucción histórica.

Mis resultados se integra como cuarta pestaña de `/afiliados`, con clics totales
y últimos siete días, registros CLUB con referente individual permanente, canjes
COM verificados y comisiones aprobadas por moneda. Las oportunidades propias
muestran sus clics. Clics y conversiones se presentan separados; no se anuncian
personas únicas ni registros COM sin evidencia. No se modifica el motor económico.

`/afiliados#mis-conversiones` se canoniza a
`/afiliados?tab=results#mis-conversiones` sin mostrar Explorar durante la carga.
Se conserva el historial de navegación, incluidos Back/Forward, filtros y anclas.
El dashboard apunta directamente a la URL correcta. Las páginas de conversiones
y comisiones mantienen la sección visible en sus enlaces.

Pruebas focalizadas aprobadas:

- 64 comprobaciones nuevas: agregados, cambio de día de Bolivia, límites de siete
  días, 24 GETs reales entre tres procesos concurrentes, privacidad, estados,
  HEAD/prefetch, cero cookies/sesiones públicas, fallos de escritura, registros
  individuales, recibos/reintentos, canjes y aprobaciones reales del motor aislado.
- 65 comprobaciones existentes de atribución: código y UUID simultáneos,
  cancelaciones, eventos tardíos, deduplicación por referencia, privacidad,
  paginación y conservación de identidad. El admin mantiene eventos sin afiliado.
- Query Builder y Eloquent verificados contra esquemas PostgreSQL aleatorios
  antes de migraciones o escrituras de fixtures. Ningún usuario ni canje de prueba LIVE.
- TypeScript y Vite en contenedores limitados, secuenciales, sin dependencias nuevas.
- Chromium a 390/1440 con fixtures del kernel interceptados: ancla heredada,
  ancla visible, URL canónica, Back/Forward, hashchange, dashboard, restauración de
  filtros, clics por oportunidad, importes por moneda y ausencia de desbordamientos.

El despliegue usa únicamente la nueva migración aditiva, copia de assets con
manifest al final, cachés y reinicio de `app`. No requiere reinicios de web/db,
cambios en Traefik ni cambios en COM, CRM, SSO, registro, landings o SES.

[Contrato y operación](../../docs/integrations/club-affiliate-results.md).
La verificación final `LIVE.json` (no versionada para evitar autorreferencia)
compara Git remoto/local, código montado, assets HTTPS, `/up` público/local,
enlaces, tablas protegidas y entorno contra el baseline privado de esta entrega.
Las verificaciones de enlace LIVE usan exclusivamente HEAD/prefetch; no fabrican clics.

Entrega desplegada: migración aplicada, cachés/manifest publicados y app saludable.
Validación local aprobada y revisión 390/1440 repetida con assets LIVE, sin errores.
La sonda de salud contempla el breve arranque de PHP-FPM tras reiniciar app.
