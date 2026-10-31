# Fase 10G — evidencia COM conectada

Adaptador del contrato real `jakawi-acquisition-evidence-v1`, desplegado por COM
con SHA `917f897f5b7e4725d66156a70143c3a22fe8eac1`. Se consultó el contrato
10F instalado en COM únicamente en lectura. No se modifica COM.

`ClubPurchaseClient` reutiliza HMAC, secreto privado `CLUB_PURCHASE_HMAC_SECRET`,
key ID `club-purchases-v1` y nonce nuevo por intento. POST exacto a
`/internal/club/acquisitions/evidence`, usando el `payment_reference` firmado
original; nunca `club_reference`. INDIVIDUAL usa el sujeto autorizado del afiliado
original, sin resolver email. Un vínculo expresamente revocado bloquea evaluación.
PARTNER envía null: beneficiario empresarial `source=jakawi.com`, `partners.id`.

El adaptador exige contrato, referencia, tipos estrictos, identidad compatible,
membresía, fechas originales coherentes, categoría y sujeto del afiliado, journal
y digest SHA256 del cuerpo COM. Guarda el body exacto y referencias en tabla
privada aditiva `acquisition_evidence`; la UI administrativa solo recibe motivos,
estado y referencia de compra. La evidencia utilizada queda congelada también en
el snapshot existente del ledger junto a beneficiario, BOB, importe, regla,
versión y fecha COM. No existe pago automático; aprobación manual conservada.

Primera adquisición true permite continuar; false excluye renovaciones y
reactivaciones; unknown conserva pendiente. FREE=15, PAID=25, Partner=30 según
las reglas PRELAUNCH-10E-v1 ya publicadas, sin cambiar su vigencia. UNKNOWN no se
convierte en FREE ni true. Cobertura histórica insuficiente 10F explica la espera
al administrador. HTTP 200 por sí solo no autoriza comisión.

Las reglas nuevas reciben versión única ACQ-UUID y vigencia futura mediante la
UI existente. La selección usa evaluated_at de COM contrastado con la activación
original firmada, nunca entrega, consulta ni aprobación. El ledger valorado no se
recalcula. No se aplica 10E a activaciones anteriores a su fecha real de publicación.
No se reinterpreta una activación sin tarifa aplicable como adquisición legacy.

Reintentos del cliente: máximo dos, nonce distinto; backoff largo deja pendiente.
Consultas repetidas: cooldown de cinco minutos por evento, afiliado y contexto
de identidad autorizado. Los eventos identity-linked existentes pueden cambiar
ese contexto y resolver al invitado inmediatamente, sin nueva adquisición.
El administrador puede reconsultar desde `/admin/comisiones`, con el mismo límite;
esto también permite resolver afiliados vinculados posteriormente. Sin nuevos
jobs, cron, replay histórico ni creación de compras LIVE de comprobación.

Pruebas: `bash scripts/test-acquisition-evidence.sh`, PostgreSQL con esquema
aleatorio eliminado al terminar y transporte COM simulado. Reutiliza regresión
10E/Partner y prueba adaptador real, firma, nonces, Bs15/25/30, false, UNKNOWN,
identidades tardías, invitado, duplicados, tipos/contrato/digest/referencia/identidad
inválidos, timeout/429/503, versiones Bs15→20, evento tardío, históricos intactos
y aprobación manual. `bash scripts/build-frontend.sh`: TypeScript y Vite en serie.

Despliegue CLUB: `bash scripts/deploy-acquisition-evidence.sh`. Migra solo tabla
aditiva, conserva tarifas, publica manifest al final, regenera cachés y reinicia
solo app. Verifica HMAC real con referencia inexistente (solo consulta, UNKNOWN),
digest, `/up` 200, validación local y hash íntegro del ledger. Registra SHA LIVE en
`artifacts/acquisition-10g/LIVE.json` sin autorreferencia en Git.

Rollback privado: `/srv/sites/jakawi.club/rollback-acquisition-10g`, fuente previa
y manifest. Restaurar código/manifest, regenerar cachés y reiniciar solo app;
conservar tabla de evidencia, tarifas y ledger. No ejecutar migrate:rollback.
No se acredita ninguna comisión real sin una adquisición LIVE posterior válida.
