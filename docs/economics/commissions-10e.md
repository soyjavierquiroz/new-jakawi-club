# Fase 10E — tarifas de adquisición de membresías

Estado histórico de 10E. El adaptador fue conectado en [Fase 10G](commissions-10g.md); el bloqueo descrito abajo corresponde a la entrega original de 10E.

Configuración oficial `PRELAUNCH-10E-v1` en el motor, reglas y ledger existentes:

| Beneficiario | Membresía al adquirir | Tarifa fija |
| --- | --- | --- |
| INDIVIDUAL | FREE | Bs 15 |
| INDIVIDUAL | PAID vigente COM | Bs 25 |
| PARTNER empresarial habilitado | No aplica | Bs 30 |

Evento exclusivo `membership.activated`, naturaleza `ONE_TIME_ACQUISITION`, BOB.
Publicación transaccional e idempotente, con vigencia compartida desde el instante
real de publicación y auditoría de la decisión del propietario. No se ejecuta
replay ni se modifican reglas o comisiones históricas. Versiones posteriores se
crean desde `/admin/reglas-comisiones`, nunca reescribiendo las anteriores.
La pantalla identifica categoría, naturaleza, versión y bloqueo de integración.

## Bloqueo real de integración

**Las tarifas están configuradas, pero no pueden emitir comisiones 10E LIVE todavía.**
`MemberActivation::confirmed` solo prueba una activación pasada; su propio contrato
advierte que no informa vigencia/vencimiento. Los contratos comerciales HMAC
actuales (`ClubPurchaseEvents`, activaciones del receptor y `AcquisitionEvents`)
tampoco certifican primera compra histórica frente a renovación o reactivación.
El acceso empresarial SSO autoriza al representante; no acredita membresía personal.

La ausencia de una activación en CLUB no demuestra FREE ni primera compra: puede
haber pagos anteriores en COM o eventos pendientes. Email verificado tampoco.
`MembershipAcquisitionEvidence::resolve` devuelve desconocido expresamente, sin
consultar endpoints inventados. El bloqueo afecta también a Partner por la falta
de certificación de primera compra. No cambia el SSO ni sus autorizaciones.

Contrato mínimo faltante, autenticado por COM y con semántica acordada:

- Identidad durable del comprador, incluso invitado, referencia de compra y de
  membresía; `first_paid_acquisition` certificado sobre todo el historial COM.
  Renovaciones, reactivaciones y pagos posteriores deben distinguirse expresamente.
- Para INDIVIDUAL: sujeto COM del afiliado original y clasificación FREE/PAID
  **a la fecha original de activación del comprador**. PAID debe acreditar inicio,
  fin y ausencia de revocación/baja; FREE debe ser una respuesta autoritativa,
  no ausencia de registros. Incluir fecha consultada y referencia de evidencia.
- Prueba autenticada y versionada ligada a esos sujetos, compra y fecha. Si llega
  tarde debe poder responder sobre esa fecha histórica; una consulta del estado
  actual no basta. Invitados necesitan deduplicación durable anterior a su vínculo.

No se ha modificado COM ni habilitado un receptor especulativo. Conectar ese
contrato exige una entrega posterior del adaptador; las pruebas sustituyen este
adaptador **solo dentro del proceso aislado de pruebas**. Sus Bs15/25/30 no son
comisiones reales. Esta fase no debe declararse operativa de extremo a extremo.

## Garantías económicas implementadas

Se conserva beneficiario original, exclusión de auto referido mediante alias de
usuario/sujeto, serialización de adquisición y unicidad existente por persona y
referencia. La primera activación conocida se contrasta además con la evidencia
COM de primera compra; no basta el historial parcial local. Identity-linked solo
puede completar el hecho original, nunca aportar otra adquisición remunerable.
Partner usa `source=jakawi.com`, `beneficiary_id=partners.id`, no propietario.

El JSON inmutable del ledger congela regla completa, versión, tarifa y moneda,
estado utilizado y referencia de evidencia, beneficiario, fecha original firmada
y referencia de compra COM. No recalcula por ascensos posteriores. Revisión y
aprobación manuales existentes; sin pagos, desembolsos ni transferencias nuevos.

## Pruebas y despliegue

`bash scripts/test-acquisition-rates.sh` ejecuta el receptor HMAC y las migraciones
reales en un esquema PostgreSQL temporal eliminado al finalizar. Incluye regresión
Partner, competencia de referentes, invitado/vínculo, tarifas con evidencia
simulada, ascenso FREE a PAID, renovación, reactivación, duplicado, auto referido,
lead, compra aún no activada, sin referente, anteriores a vigencia, preservación
del ledger, aprobación manual y bloqueo con el adaptador real sin contrato.
`bash scripts/build-frontend.sh`: TypeScript y Vite secuenciales y limitados.

Después de commit/push: `bash scripts/deploy-acquisition-rates.sh`, solo CLUB.
Migración aditiva, publicación de tarifas, assets preservando chunks antiguos,
cachés y reinicio exclusivo de app. `/up`, validación local, SHA y hash del ledger.
Ninguna compra, comisión ni usuario ficticio LIVE. Secretos sin cambios.
Rollback: fuente anterior y manifest en
`/srv/sites/jakawi.club/rollback-acquisition-10e`; conservar migración, reglas y
ledger. Para detener nuevas valoraciones, inactivar las tres reglas en la UI;
no eliminar historia ni ejecutar `migrate:rollback`.
