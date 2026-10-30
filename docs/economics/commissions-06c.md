# Fase 06C — motor económico CLUB

Documento histórico sustituido por [Fase 06E](commissions-06e.md). Las exigencias
de conciliación descritas aquí ya no forman parte del motor vigente.

Reglas: `https://jakawi.club/admin/reglas-comisiones`.
Libro: `https://jakawi.club/admin/comisiones`.
Afiliado: `https://jakawi.club/afiliados`.
Administración: sesión CLUB, correo verificado, activación e `is_marketing_admin`.
Todas las mutaciones usan POST, CSRF y control de concurrencia.

## Reglas y dinero

No hay reglas predefinidas, importes supuestos, porcentajes ni aprobaciones
automáticas. El administrador introduce partner COM original, evento remunerable
real, monto fijo positivo, moneda (código de tres letras) y vigencia con zona.
La interfaz utiliza hora de Bolivia. Cada regla nace en borrador y requiere una
activación explícita con motivo. Puede inactivarse o reactivarse con historial.

Importes como strings decimales; máximo 12 enteros y 4 decimales. Se rechazan
floats JSON, notación científica, signos, ceros y precisión adicional. PostgreSQL
usa `NUMERIC(20,4)` y suma en NUMERIC; PHP y React no convierten dinero a float.
Los totales son strings separados por moneda; no se suman monedas distintas.

Los términos de una regla son inmutables, incluso en borrador. Para cambiarlos
se crea una nueva versión con su fecha de vigencia. Para un evento se aplica la
regla activa de partner/tipo con la vigencia más reciente que no supere su fecha
original. No pueden coexistir versiones activas para la misma clave y fecha.
No se exige que el partner tenga una cuenta CLUB.

## Libro y atribución

El receptor existente conserva su autenticación HMAC, contrato y tabla de
eventos. Una única llamada al motor dentro de la transacción original hace que
el recibo y la actualización económica se confirmen juntos. No hay otro receptor
ni otra copia de los eventos. Los bytes originales permanecen en la tabla actual.

`commission_ledger` contiene una entrada única por
`(source, com_reference_type, com_reference_id)`, con bloqueo transaccional por
operación y unicidad PostgreSQL. Conserva el evento original, el último evento y
el evento remunerable usado, afiliado/código verificados, regla y snapshot,
importe, moneda, estado, versión, actores, fechas y referencia externa.

Se consulta el último estado por `occurred_at`, `received_at`, `id`, como el panel
de conversiones. Solo estado confirmado y código + UUID CLUB verificados pueden
generar candidatura. Email, identidad global o una referencia COM sin código no
atribuyen comisiones. Sin referente la operación queda **registrada**, sin importe.

Una candidatura sin regla activa se conserva con importe/moneda/regla nulos,
sin obligación económica. Al evaluar se busca el primer evento confirmado propio
remunerable del partner vigente en esa operación y su regla aplicable. Una vez
asignada una valoración, su evento, regla, importe, moneda y afiliado son
permanentes. Los eventos de estado posteriores nunca producen una segunda
comisión ni transfieren la entrada a otro afiliado.

Cancelaciones, cambios de partner o atribución marcan revisión obligatoria.
Una aprobación anterior no se borra: queda señalada y su importe se separa del
total de aprobaciones conciliadas. Solo la revisión explícita puede retirar la
alerta si la operación vuelve a ser elegible para el afiliado original. El admin
puede descartar una aprobación con motivo, conservando importe e historial.
Los descartes son definitivos; no se puede reabrir la operación para remunerarla
otra vez mediante otro evento o una regla distinta.

## Conciliación y aprobación

Toda entrada comienza **pendiente de conciliación con COM**, incluso con regla.
El contrato COM actual no acredita exclusividad económica: la firma HMAC y el
código verificado no demuestran que COM no haya pagado su comisión histórica.
Por eso no se infiere exclusividad ni se automatiza la aprobación.

Un administrador debe revisar la evidencia de COM y registrar su referencia
externa, motivo, actor y fecha. Puede acreditar exclusividad de CLUB o indicar
**ya remunerada por COM**; este último caso descarta definitivamente la comisión.
La referencia es texto de conciliación, no una URL ejecutable ni una consulta a
COM. Se conserva el historial de correcciones de la evidencia.

Aprobar es otra decisión manual y exige candidatura confirmada, sin discrepancias,
regla aplicada todavía activa y vigente, importe exacto y exclusividad documentada.
Una versión obsoleta del registro se rechaza; dos aprobaciones concurrentes
producen una sola decisión. Una valoración que cambie durante la solicitud debe
ser revisada de nuevo antes de aprobar. No hay pagos, saldos retirables,
desembolsos, exportaciones bancarias ni promesas automáticas de ingresos.

`commission_audit` conserva antes/después, motivo, actor y fecha de cada cambio y
decisión explícita. PostgreSQL impide borrar el libro, las reglas y el historial,
reescribir la auditoría o alterar snapshots e identidad económica permanentes.

## Operación y validación

`php artisan commissions:sync` recupera candidatos desde recibos existentes en
lotes de 100. Es idempotente: no crea eventos COM ni aprobaciones. No corre por
GET ni programa pagos. Nuevos eventos ya se procesan transaccionalmente. Tras
activar una regla, los candidatos anteriores se evalúan explícitamente en admin
o mediante ese comando; nunca se reprician entradas que ya tienen regla.

El afiliado ve conversiones verificadas, candidaturas, aprobaciones y alertas,
totales por moneda y detalle de sus comisiones con paginación independiente.
No ve referencias externas, notas internas, snapshots ni registros de terceros.
El admin conserva `/admin/conversiones-com` como historial original de eventos.

Pruebas aisladas: `bash scripts/test-commissions.sh`, `test-affiliates.sh` y
`test-commercial.sh`; kernel HTTP, HMAC y PostgreSQL real en esquemas temporales.
`bash scripts/build-frontend.sh`: TypeScript/Vite limitados y secuenciales.
`bash scripts/check-commissions-visual.sh`: navegador 390/1440 px con fixtures
exclusivamente en navegador; todos los envíos interceptados. Después publicar
repetir con `--live` para comprobar los assets HTTPS reales.

Despliegue solo CLUB, commit/push normal. Antes de sustituir el código servido,
aplicar únicamente `2026_10_30_000002_create_commission_engine.php` desde el
worktree probado, usando un contenedor efímero limitado. No ejecutar migraciones
ajenas. Fast-forward de main, assets con chunks previos y manifest al final,
cachés y reinicio solo `app`. Sin cambios de compose ni reinicios de web/DB.
Ejecutar `commissions:sync` para cubrir cualquier recibo entre migración y cambio
de código. No se crean reglas ni conversiones LIVE durante el despliegue.

`python3 scripts/verify-commissions-live.py` sella SHA/origin, código montado,
assets HTTPS, `/up`, paneles protegidos, NUMERIC y receptor HMAC con JSON incompleto
rechazado. `artifacts/commissions-06c/LIVE.json` está ignorado.
Respaldo privado: `/srv/sites/jakawi.club/rollback-commissions-06c/`.
Rollback de código/manifest y cachés, reiniciando solo `app`; conservar tablas,
eventos, snapshots y auditoría. No ejecutar rollback de la migración económica.

COM, FluentCRM, SSO, registro, SES, landings, WhatsApp y motor de referidos no se
modifican. La conciliación de remuneraciones históricas continúa siendo manual
hasta contar con evidencia estructurada de exclusividad desde COM.
