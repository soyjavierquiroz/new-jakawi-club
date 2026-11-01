# Fase 11D — aviso consciente de pago CLUB → COM

Adaptación del checkout existente `/activar` al contrato COM Fase 11C, sin otro checkout.

## Comportamiento

- Beneficiario obtenido de la compra COM autorizada (`beneficiary_name`), distinto del destinatario bancario. QR original y total Bs 100 conservados.
- Casilla desmarcada y botón «Ya pagué · Registrar mi aviso» bloqueado hasta confirmación. Backend exige booleano true; no usa nombres ni referencias recibidos del navegador.
- POST HMAC al `/report` existente: bytes exactos `{"payment_confirmed":true}`. Misma referencia/claim, nonce nuevo por intento, sin crear una compra al registrar el aviso.
- Tras timeout, GET de la misma referencia antes de permitir reintentar. La creación incierta también consulta GET antes de reenviar su intención congelada.
- Persistencia aditiva CLUB: beneficiario cifrado, fechas COM del aviso/confirmación explícita, `receipt_pending` y `receipt_received`. `payment_confirmed` es declaración del comprador, nunca aprobación bancaria.
- «¡Recibimos tu aviso de pago!» y «Adjunta tu comprobante» tras el aviso. Sin texto opcional ni botón destacado para omitirlo; navegación disponible.
- Pendiente de archivo y comprobante recibido visibles en `/activar` y en el área de membresía. Enlace `/activar#comprobante` para adjuntar después.
- GET al entrar/refrescar y al recuperar historial/bfcache. Casilla restablecida. Respuestas tardías no revierten aviso, archivo recibido ni activación.
- JPG, PNG y PDF, máximo 10 MiB, por el endpoint privado de comprobantes existente. No se pide archivo tras aprobación, incluso si COM aprobó sin archivo.
- Cuenta propia recuperable tras nuevo login. Invitada conserva su sesión autorizada, sin segundo registro/contraseña. Si COM ya la vinculó, puede recuperarse por el sujeto COM autenticado en otra sesión; email por sí solo nunca concede acceso. Una invitada sin vínculo ni sesión conservada no adquiere acceso por declarar un email.

## Pruebas aisladas

Copia de trabajo separada de LIVE en `/srv/sites/jakawi.club/phase11d-source`. Kernel HTTP real, migraciones reales y esquema PostgreSQL temporal eliminado al terminar. COM simulado, firma HMAC y unicidad de todos los nonces verificadas; ninguna compra ni pago de prueba LIVE.

- `JAKAWI_EVIDENCE_DIR=.../artifacts/payment-report-11d bash scripts/test-public-checkout.sh`: 81 comprobaciones. Incluye validación estricta, CSRF, body vacío/422, 422 COM, doble aviso, timeout antes/después del commit, GET de recuperación, comprobante tardío, invitada, retorno tras logout, compra aprobada sin archivo y respuestas fuera de orden.
- `bash scripts/build-frontend.sh`: TypeScript estricto y build secuenciales en contenedores limitados, sin reinstalar dependencias.
- `JAKAWI_EVIDENCE_DIR=.../artifacts/payment-report-11d bash scripts/check-public-checkout.sh`: navegador 390/1440, estados cuenta/checkout, doble clic, historial, bfcache y refresh. Solicitudes de escritura interceptadas; fixtures y capturas excluidos de Git.

## Despliegue y evidencia LIVE

Con tests y preview aprobados, integrar por fast-forward en main, copiar el build verificado a `app/public/build-next`, publicar Git y ejecutar `bash scripts/deploy-payment-report-11d.sh`. Aplica exclusivamente la migración aditiva de esta fase, publica assets conservando chunks anteriores y reemplazando manifest al final; regenera rutas/vistas y reinicia solo `jakawi-club-app`.

Validar con `python3 scripts/validate-local.py`, navegador `--live` y `python3 scripts/verify-payment-report-live.py`. `artifacts/payment-report-11d/LIVE.json` (local, excluido de Git) registra SHA, manifest, rutas, migración, HMAC real de lectura del QR y, si hay compra existente, GET COM autenticado con campos 11C. No se registra un aviso artificial LIVE ni se sube un archivo de prueba a COM.

No hay cambios en QR/monto, atribución, tarifas de comisión, ledger, SSO, conciliación, APIs comerciales o CRM. Ningún despliegue COM, cambio de secretos ni infraestructura compartida.

## Rollback

Baseline privado en `/srv/sites/jakawi.club/rollback-payment-report-11d/`: SHA anterior, archive de código, manifest anterior y estado de contenedores CLUB. Revertir el commit de esta fase en main, restaurar el manifest anterior, regenerar rutas/vistas y reiniciar solo app. Conservar la migración y evidencia de avisos/archivos; no borrar compras, fechas ni comprobantes.
