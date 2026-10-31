# Checkout directo para cuentas autenticadas

`GET /activar` crea o recupera la intención desde el perfil autenticado y devuelve
el QR en la misma respuesta, sin formulario, botón de continuación ni redirección.
Reutiliza la creación serializada y el payload estable del checkout anterior.
No exige correo verificado ni identidad COM para pagar. HEAD no crea compras.
Si un perfil antiguo carece de datos indispensables, ofrece actualizarlo en el
perfil existente; no inventa datos ni muestra otro formulario de compra.

La cabecera muestra «Hola, {primer_nombre}» y «¿No eres tú, {primer_nombre}?
Cambiar de cuenta». Este botón usa POST `/activar/cambiar-cuenta`, con CSRF y
bloqueo de sesión. El servidor cierra el guard CLUB, destruye la sesión anterior,
renueva sesión/token y elimina capacidades de compra y datos de sesión. Solo
conserva un destino local fijo para volver al checkout después de otro login.
También limpia el historial Inertia y el estado local de formulario/comprobante.
No cierra la sesión COM ni borra compras: el comprador original puede recuperarlas
al autenticarse nuevamente. La página visitante ofrece datos mínimos e ingreso.

Los estados `under_review` y `approved` conservan sus mensajes y no ofrecen otro
cobro. `rejected` permite corregir el comprobante en la misma intención. QR,
descarga, firma HMAC, comprobante privado, atribución, activación y conciliación
COM conservan el mecanismo previo. Las recargas no duplican referencia ni
`InitiateCheckout`. No hay migraciones, dependencias o cambios de configuración.

## Comprobaciones focalizadas

Usar `JAKAWI_EVIDENCE_DIR=/opt/sites/jakawi.club/artifacts/direct-checkout` con:

- `bash scripts/test-public-checkout.sh`: kernel real, esquema PostgreSQL temporal,
  API COM simulada verificando firmas y bytes. Incluye GET autenticado directo,
  recargas, identidad real, invitado, cambios de cuenta, CSRF habilitado, sesión
  anterior destruida, cookie anterior inutilizable, segundo usuario, revisión,
  activación y corrección de rechazo. Sin cuentas/compras bancarias LIVE.
- `bash scripts/build-frontend.sh`: TypeScript y Vite, secuenciales y limitados.
- `bash scripts/check-public-checkout.sh`: móvil 390 y desktop 1440, QR inmediato,
  saludo real, ausencia de datos/continuación, estados y cambio de cuenta mediante
  POST interceptado; ningún envío del navegador modifica LIVE.
- `bash scripts/check-public-checkout.sh --live`: assets publicados y rutas
  públicas reales; identidades y compras privadas son fixtures del navegador.

Backup privado: `/srv/sites/jakawi.club/rollback-direct-checkout` contiene fuente,
manifest y baseline anterior de los tres contenedores CLUB.
`scripts/deploy-direct-checkout.sh` conserva chunks, cambia manifest al final,
regenera rutas/vistas y reinicia únicamente app. No recrea app/web/db ni toca COM.

Validación local con el baseline de esta entrega:

```bash
JAKAWI_BASELINE_PATH=/srv/sites/jakawi.club/rollback-direct-checkout/baseline.json \
JAKAWI_EVIDENCE_DIR=/opt/sites/jakawi.club/artifacts/direct-checkout \
python3 scripts/validate-local.py
```

Sello Git/origin/assets/HTTPS y QR real mediante GET HMAC:

```bash
JAKAWI_EVIDENCE_DIR=/opt/sites/jakawi.club/artifacts/direct-checkout \
JAKAWI_ROLLBACK_DIR=/srv/sites/jakawi.club/rollback-direct-checkout \
python3 scripts/verify-public-checkout-live.py
```

El verificador usa el baseline de esta entrega: permite el reinicio previsto de
app y exige conservar los IDs CLUB, sin reiniciar web ni PostgreSQL. No compara
workers COM contra referencias históricas.

El sello `LIVE.json` es local y no se versiona. Rollback: restaurar la fuente del
SHA previo y su manifest, regenerar rutas/vistas y reiniciar exclusivamente app.
Conservar compras, estados, eventos, comprobantes y toda la persistencia.
