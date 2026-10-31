# Fase 08 — embudo de membresías y afiliados

A. Entrada privada «Activar mi membresía — Bs 100» en el dashboard. Checkout único
COM; aviso explícito de QR pendiente y cobros todavía bloqueados. Captación pública
y confirmación WhatsApp conservadas.

B. Vinculación existente mediante handoff y consentimiento COM; después OIDC/PKCE
con sujeto esperado, sesión CLUB y vínculo vigente. Sin sesión COM exige login COM;
una sesión de otro titular no permite seguir al checkout. Sin identidad por email,
parámetros externos o cookies compartidas, ni segunda inscripción.

C. Leads únicos según `users.referred_by_id` original. Ningún clic, visita,
comprobante o intento fallido entra como lead. Sin datos personales en resultados.

D. Mismo receptor HMAC, contrato real SHA COM
`98de3ee70b2e49d8e515e47f0f3afaecc1426d45`. Guarda referencias COM, ocurrido original
y cuerpo firmado; duplicados idempotentes y conflictos 409. Eventos sin vínculo
se conservan pendientes y se resuelven al consultar tras vincular por UUID global.
Una persona cuenta una vez, incluidas renovaciones. Sin lead atribuible permanece
sin afiliado. No genera comisiones, reglas, pagos ni asientos económicos nuevos.

E. Mis resultados prioriza LEADS CAPTADOS, MEMBRESÍAS ACTIVADAS y tasa.
Explorar, Mis enlaces, Historial y métricas/registros económicos previos conservados,
separados de los nuevos contadores.

Validación: 81 comprobaciones de resultados y 113 de identidad/SSO. Imagen exacta
COM en contenedores desechables y PostgreSQL aislado: consentimiento/vinculación
reales, código OIDC real, checkout, una intención y un InitiateCheckout tras recargar,
ninguna membresía creada. Firma, sujeto equivocado, expiraciones, indisponibilidad,
activación duplicada, pendiente, orgánica, renovaciones, contadores, privacidad y
ledger comprobados. Navegador 390/1440 con fixtures interceptados, sin escrituras ni
clics LIVE, sin overflow ni errores. TypeScript y Vite secuenciales satisfactorios.

F. El sello `LIVE.json` registra SHA exacto, origin/main, hashes del código y manifest
servidos, `/up`, aislamiento y comparación de estado antes/después. El despliegue
publica assets con manifest al final, regenera rutas/vistas y reinicia únicamente
CLUB app. Sin migraciones, entorno, redes o Traefik. COM y CRM no se modifican.
El baseline fresco de esta entrega permite verificar continuidad sin reutilizar
los IDs obsoletos de fases anteriores. Evidencia posterior al commit excluida de Git
para evitar autorreferencia.

La recepción de activaciones y el checkout se prueban en aislamiento. No se fabrica
una activación LIVE ni se afirma un cobro completado: el QR real sigue pendiente.
Rollback privado: `/srv/sites/jakawi.club/rollback-membership-funnel`; restaurar
código/manifest anteriores, cachés de rutas/vistas y reiniciar solo app.
