# Fase 10G

Implementación y pruebas focalizadas: 222 comprobaciones satisfactorias mediante
receptor HTTP/HMAC, adaptador real y PostgreSQL con esquema temporal aislado.
Cero fixtures LIVE. TypeScript y build Vite satisfactorios, en serie y con límites.

La comprobación de transporte real usa una referencia inexistente de consulta:
HMAC aceptado, contrato `jakawi-acquisition-evidence-v1`, HTTP 200, digest correcto,
UNKNOWN sin creación de compra ni comisión. El despliegue vuelve a comprobarla.

Prueba de tarifas Bs15/25/30 y versión nueva Bs20 solo en fixtures aislados.
Sin retroactividad, sin cambios de importes históricos y con aprobación manual.
La entrega no acredita una comisión real LIVE.

[Operación, contrato, pruebas y rollback](../../docs/economics/commissions-10g.md).
Evidencia operativa generada y excluida de Git: `LIVE.json`, `com-live.json`,
`validation.json`; incluye SHA desplegado y conservación del ledger.
