# Fase 06E — autoridad económica CLUB

Corte autorizado, estable y versionado en `app/config/commissions.php`:
`2026-10-30T07:45:11.067511-04:00` (`2026-10-30T11:45:11.067511Z`).
COM retiró su motor de afiliados en el SHA
`68f63c8f080b52f4eddaf14d25deea5dd257ab4d`. El propietario confirmó que
no existen obligaciones monetarias reales anteriores pendientes. CLUB es la
única autoridad de nuevas comisiones desde ese corte.

## Elegibilidad y fechas

Solo las operaciones COM auténticas cuya primera confirmación económica sea
**estrictamente posterior** al corte pueden evaluarse. En el instante exacto
no son elegibles. Un evento anterior recibido después del corte sigue excluido;
una actualización posterior de una operación ya confirmada antes no la renueva.
Una operación pendiente antes y confirmada por primera vez después sí puede
ser elegible. Una confirmación anterior entregada fuera de orden invalida la
candidatura y exige revisión si ya tenía una valoración.

La fecha procede de `occurred_at` en el cuerpo original autenticado y almacenado
por el receptor HMAC existente. Se interpreta con zona y seis decimales tanto
en PHP como en PostgreSQL. El binding original del receptor puede truncar los
microsegundos de su columna `occurred_at`: por eso el motor económico utiliza
los bytes originales, sin cambiar el receptor ni su contrato. El orden económico
es fecha original e ID interno en empates exactos, nunca recepción HTTP.

Se exige estado COM confirmado vigente, código y UUID del referente CLUB
verificados, regla activa del partner y evento reales con vigencia aplicable,
importe fijo exacto y moneda. No se deduce atribución desde identidad global o
correo. No se crean reglas ni importes predeterminados. Sin regla activa, una
candidatura conserva regla, importe y moneda nulos y no crea obligación con monto.

## Unicidad y aprobación

Una entrada por `(source, com_reference_type, com_reference_id)`, bloqueo
transaccional de operación y restricciones únicas PostgreSQL. Un evento posterior
no crea una segunda comisión. El evento remunerable, afiliado, regla, snapshot,
importe y moneda permanecen inmutables. Los importes y sumas son `NUMERIC(20,4)`
y strings separados por moneda; no se usa coma flotante.

La aprobación sigue siendo administrativa y manual, con motivo, actor, fecha
y control de versión. Exige candidatura elegible, regla aplicada todavía activa,
importe y moneda exactos, sin discrepancias pendientes. La revaluación que cambie
el registro obliga a recargar antes de aprobar. Dos aprobaciones concurrentes
producen una sola decisión. Los descartes son definitivos.

Cancelaciones y cambios de atribución o partner conservan la evidencia y señalan
revisión. Las valoraciones cuestionadas se separan de los totales pendientes y
aprobados. La auditoría es acumulativa e inmutable. No existen pagos automáticos,
exportaciones bancarias ni saldos retirables.

Se retiraron la acción, filtros, campos y restricciones de conciliación histórica.
`2026_10_30_000003_simplify_commission_approval.php` solo simplifica el esquema de
CLUB y conserva los controles de aprobación: no importa, concilia ni migra datos
legacy. No existe endpoint nuevo ni consulta al motor histórico de COM.

## Paneles y operación

- `/admin/reglas-comisiones`: corte explícito, reglas reales en borrador,
  activación manual y versiones inmutables.
- `/admin/comisiones`: fecha del hecho económico, exclusión por corte, sin
  referente, sin regla/obligación, regla inactiva, aprobación manual y revisión.
- `/afiliados`: enlaces existentes intactos, conversiones comerciales originales,
  corte, comisiones propias y totales exactos por moneda.

`commissions:sync` sigue siendo una recuperación idempotente de recibos locales:
no importa datos externos, no crea eventos, no aprueba ni paga. Aplica el mismo
corte; los recibos anteriores nunca devengan comisiones.

Validación focalizada: `bash scripts/test-commissions.sh`, `test-commercial.sh`,
`test-affiliates.sh`; esquemas PostgreSQL temporales y HMAC de prueba, sin cuentas,
operaciones ni reglas LIVE. `bash scripts/build-frontend.sh` ejecuta TypeScript y
Vite en secuencia. `bash scripts/check-commissions-visual.sh` comprueba los tres
paneles a 390 y 1440 px con fixtures del navegador y envíos interceptados; repetir
con `--live` verifica assets publicados, sin sesiones ni mutaciones reales.
La evidencia de esta fase se guarda en `artifacts/commissions-06e/`.

Despliegue exclusivamente CLUB: commit/push normal, modo de mantenimiento breve
durante la simplificación del esquema y cambio de código, publicar chunks
conservando los anteriores y manifest al final, regenerar cachés y reiniciar solo
`app`. Verificar `/up` local/público, SHA remoto y hashes del código montado/assets.
Respaldo exclusivo en `/srv/sites/jakawi.club/rollback-commissions-06e/`.
El código anterior 06C no es compatible con el esquema simplificado; cualquier
reversión debe conservar ese esquema y utilizar una versión compatible con él.
No ejecutar rollback de las tablas ni reintroducir obligaciones anteriores.

COM, FluentCRM, identidad/SSO, landings, registro, receptor comercial y enlaces
personales no se modifican.
