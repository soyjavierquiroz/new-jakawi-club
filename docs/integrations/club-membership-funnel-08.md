# Fase 08 — membresías y afiliados

La entrada privada **Activar mi membresía — Bs 100** está en `/dashboard`.
`POST /cuenta/membresia` requiere cuenta CLUB activada/verificada, CSRF y límite de
intentos. No hay cambios en captación pública ni en la confirmación con WhatsApp.
Actualización posterior: COM habilitó el QR real. El aviso estático se retiró y
la entrada comercial es `/activar`; ver [recorrido actual](club-activation-url.md).

Sin vínculo, la acción reutiliza Fase 03: evidencia de correo y consentimiento
explícito, handoff HMAC y lookup autenticado del UUID CLUB. Una cuenta existente
requiere consentimiento nativo COM; un email coincidente no crea un vínculo.
Al completar la conexión, el usuario vuelve a pulsar la misma opción del dashboard.

Con vínculo, CLUB inicia OIDC/PKCE con state, nonce y transacción cifrada de diez
minutos ligada al UUID CLUB y sujeto esperado. El callback existente comprueba
firma RS256, issuer, audience, nonce, state y sujeto, sesión CLUB original y vínculo
COM vigente. Una sesión COM de otro titular impide continuar y conserva la cuenta
CLUB. Sin sesión COM, el proveedor exige su login propio, sin segunda inscripción.
Solo después del callback se navega al destino fijo
`https://jakawi.com/membresia/checkout`. CLUB no crea intención, referencia de
compra ni InitiateCheckout.

## Evento exacto de COM

Fuente comprobada de solo lectura: SHA COM
`98de3ee70b2e49d8e515e47f0f3afaecc1426d45`,
`app/app/Services/MembershipBankCheckout.php`, método `approve`.
El receptor sigue siendo `POST /internal/commercial/events`, con el HMAC comercial
existente y sin nuevos secretos ni servicios.

```json
{
  "schema_version": 1,
  "event_id": "com:membership:<payment-reference>:activated",
  "event_type": "membership.activated",
  "global_subject_id": "<UUID global COM>",
  "membership_reference": "<ID original de membresía COM, string>",
  "com_reference": {"type": "membership", "id": "<mismo ID>"},
  "payment_reference": "<referencia comercial original COM>",
  "occurred_at": "<fecha de activación RFC3339>"
}
```

No incluye `conversion_status`, partner ni referido. CLUB valida ambos IDs de
membresía iguales y conserva cuerpo firmado íntegro, referencias y fecha original
(incluidas fracciones en el cuerpo). Para la proyección local deriva
`conversion_status=activated`. La idempotencia y conflictos de `event_id` siguen
siendo los existentes: 201 nuevo, 200 duplicado, 409 cuerpo diferente.

## Personas y atribución

Un lead es una fila única de `users` registrada con `referred_by_id` original.
Un activado es ese mismo usuario con al menos un evento de activación firmado cuyo
`global_subject_id` coincide con el UUID global vinculado. La consulta parte de
usuarios y usa EXISTS: múltiples entregas o renovaciones nunca multiplican personas.
No usa email, último clic ni referido recibido de COM para estas métricas.

El evento se persiste aunque falte el usuario/vínculo. La resolución es una
proyección al consultar resultados: al completarse el vínculo autenticado, el
mismo evento retenido entra en el contador, sin tarea de reparación ni carrera
entre entrega y vinculación. Si no hay lead atribuido queda almacenado sin afiliado.
Las columnas de procedencia del evento no se reescriben para simular una atribución
que COM no remitió. No se exponen datos personales de leads en `/afiliados`.

Mis resultados destaca leads, membresías y tasa; clics, canjes y operaciones
históricas permanecen separados. Ledger/reglas anteriores se conservan.
`membership.activated` se guarda como hecho disponible para desarrollo económico
futuro, y no invoca el motor actual ni genera obligación, importe o pago.

## Operación

Pruebas focalizadas: `bash scripts/test-membership-results.sh`,
`python3 scripts/test-membership-sso.py`, `bash scripts/build-frontend.sh`,
`bash scripts/check-membership-visual.sh` y el mismo con `--live` tras desplegar.
Los datos se crean solo en esquemas/DB desechables; el navegador intercepta todas
las pantallas privadas y escrituras. SSO utiliza una copia de la imagen exacta COM
con PostgreSQL propio desechable, nunca la base de producción.

Backup privado: `scripts/prepare-membership-deployment.py` guarda el commit anterior,
assets y baseline de contenedores en `/srv/sites/jakawi.club/rollback-membership-funnel`.
`bash scripts/deploy-membership-funnel.sh` publica chunks preservando anteriores,
manifest al final, cachés de rutas/vistas y reinicia solo app. Sin migración, entorno,
red ni Traefik. Rollback: restaurar fuente del SHA anterior y manifest del backup,
regenerar rutas/vistas y reiniciar solo app; conservar eventos originales recibidos.
