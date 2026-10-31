# URL comercial de activación

`GET https://jakawi.club/activar` inicia el recorrido de membresía de Bs 100.
Conserva `POST /activar` y `GET /activar/{token}` para activar la cuenta por correo.
No se modifican landing pública, formulario de captación ni confirmación WhatsApp.

Sin sesión, la ruta guarda intención por dos horas y redirige al login existente.
Ese login muestra una opción al formulario existente `/#registro`. Login local y
OIDC retoman exclusivamente `/activar`: ningún `next`, `return_to` ni URL externa
controla el destino. El correo de activación de cuenta conserva `continue=membership`
para retomar también en otro navegador. Este parámetro solo selecciona un destino
fijo; no identifica, autentica ni vincula cuentas.

La ruta admite `?ref=CODIGO` y `?club_ref=CODIGO` a través del capturador existente:
primero prevalece el referente de sesión/cookie consentida anterior. El registro
mantiene el FK original permanente; visitas posteriores no lo reasignan. Sin
referente válido se registra orgánicamente. No cambia el origen comercial guardado.

Con sesión y cuenta CLUB activada/verificada, reutiliza la vinculación de Fase 03,
la evidencia de correo y el consentimiento existente. Si COM provisiona el vínculo,
continúa hacia OIDC en la misma acción. Si requiere autorización del titular en COM,
usa la pantalla existente y, al volver a CLUB, la actualización del vínculo retoma
`/activar`. Los reintentos de SSO fallidos conservan el botón sin redirecciones
repetidas automáticas. HEAD no provisiona ni inicia OIDC.

El callback existente valida PKCE, state, nonce, firma, issuer, audience, sujeto
esperado, sesión CLUB y vínculo vigente antes de abrir el único checkout COM.
No crea una cuenta por coincidencia de email, ni comparte cookies de autenticación.
Solo COM crea referencia de compra, intención e InitiateCheckout.

El dashboard usa `/activar`, retira el aviso de QR pendiente y, con una activación
HMAC ya recibida para el sujeto global, muestra **Tu membresía está activada** y
**Ver mi membresía en COM**, sin CTA de compra. La misma ruta evita iniciar SSO o
checkout en ese caso. Esta es una proyección de activación confirmada: el contrato
comercial actual no transmite vigencia/cancelación ni incluye consulta de entitlement.
Por ello la vigencia se consulta en COM y no se inventa una fecha de vencimiento.
COM conserva además su propia protección contra compra de una membresía ya activa.

Fuente COM verificada en lectura: SHA
`fabbd76daed53c660d0e2744eaac089ec8ca18f0`. El propietario confirmó QR real y cobros
habilitados. No se copian QR, comprobantes o pagos a CLUB.

## Validación y operación

`bash scripts/test-activation-url.sh` usa la imagen exacta COM con PostgreSQL propio
desechable y CLUB en esquema aislado. Comprueba registro orgánico y atribuido,
confirmación original, activación por correo en otra sesión, login local y OIDC,
vinculación real de un sujeto ausente, checkout único, miembro ya activado y cero
comisiones nuevas. No genera membresías, cuentas o compras LIVE.

`bash scripts/build-frontend.sh` ejecuta TypeScript y Vite secuencialmente.
`bash scripts/check-activation-url.sh` y el mismo con `--live` verifican login y
ambos estados de dashboard a 390/1440 con fixtures interceptados. No hacen POST LIVE
ni abren un checkout de producción.

Los scripts de preparación, despliegue y verificación de membresías admiten:

```bash
export JAKAWI_EVIDENCE_DIR=/opt/sites/jakawi.club/artifacts/activation-url
export JAKAWI_ROLLBACK_DIR=/srv/sites/jakawi.club/rollback-activation-url
python3 scripts/prepare-membership-deployment.py
# Tras pruebas, build, navegador preview y commit/push:
bash scripts/deploy-membership-funnel.sh
bash scripts/check-activation-url.sh --live
python3 scripts/verify-membership-live.py
```

Guardar `state-before.json` con `scripts/membership-live-state.php` antes del
commit, igual que Fase 08. Backup único, no sobrescribirlo. Sin migraciones,
entorno, redes, Traefik o cambios COM/CRM. Solo se reinicia CLUB app. Restaurar
código/manifest anteriores, regenerar rutas/vistas y reiniciar solo app para rollback.
El sello LIVE y la evidencia posterior al commit se excluyen de Git.
