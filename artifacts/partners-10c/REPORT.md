# Fase 10C — entrega CLUB

Beneficiarios INDIVIDUAL/PARTNER, contrato COM Fase 10B contrastado con su emisor
desplegado, origen firmado y atribución durable. Panel empresarial separado de la
actividad personal y permisos expresos por UUID. Escalas configurables en ledger
existente; solo activación confirmada y regla aplicable permiten valoración.

Pruebas aisladas: 41 comprobaciones específicas de Partners, 57 de checkout,
82 de ledger previo y 81 de resultados. TypeScript/build y Chromium 390/1440,
sin desbordes, errores JS ni escrituras LIVE. Los importes de los fixtures viven
únicamente en esquemas PostgreSQL temporales eliminados tras las pruebas.

Dossis 43 real: source jakawi.com, partner_id 1, contrastado con el catálogo ya
sincronizado de CLUB. Su referencia comercial se habilita durante el despliegue,
sin asignar representantes ni crear cuentas, compras, reglas económicas o pagos.
La administración puede autorizar representantes en /admin/partners-afiliados.

Las atribuciones tardías recuperan activaciones HMAC ya recibidas por la versión anterior.
Los originales se preservan, incluso al vincular invitados posteriormente. No se
recuperan ni atribuyen compras por coincidencia de email. Un conflicto posterior
a la valoración conserva el snapshot y bloquea aprobación para revisión manual.

El script de despliegue opera únicamente CLUB, con rollback privado y migración
aditiva. /up, assets y fuente montada se contrastan con origin/main. El sello final
SHA LIVE queda en LIVE.json local excluido de Git para evitar autorreferencia.

Operación y límites: docs/integrations/club-partners-10c.md.
