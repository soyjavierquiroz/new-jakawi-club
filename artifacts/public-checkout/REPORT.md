# Carta de membresía y checkout público CLUB

`/membresia` usa una landing independiente del CMS existente. Los borradores,
publicación, revisión, medios, SEO y Open Graph conservan su mecanismo habitual.
Precio fijo Bs 100 y tres CTA directos a `/activar`, con primer referente de sesión.
El contenido inicial describe exclusivamente el proceso de compra. Beneficios y
textos comerciales se editan en el CMS. No se añadieron promesas ni testimonios;
los testimonios opcionales requieren marcar autorización para mostrarse.

`/activar` solicita nombre, email y teléfono a visitantes. Crea una cuenta CLUB
pendiente y un lead CRM una sola vez para emails nuevos; no autentica, verifica
ni establece contraseña por recibir datos declarados. Para emails existentes
crea una compra invitada sin modificar ni revelar la cuenta. Las sesiones
CLUB usan el perfil real y recuperan su intención pendiente. Una membresía
confirmada bloquea un segundo cobro. El POST histórico de activación de correo
conserva su ruta y controlador; SSO permanece disponible para acceso posterior.

La referencia de compra y el payload original se conservan cifrados antes de
contactar COM. Se serializan altas concurrentes por email y solicitudes de sesión.
El payload nunca se reconstruye con datos nuevos al reintentar. Se emplea el
contrato documentado `jakawi-club-purchases-v1`, HTTPS fijo, firma sobre bytes
exactos, nonce nuevo por intento y reintento acotado. La credencial exclusiva
se instaló privadamente en CLUB; no se alteró COM ni secretos existentes.

El QR original se recupera desde COM por backend, con descarga y sin caché pública.
El destinatario se copia de la configuración COM al preparar el despliegue, a una
variable privada CLUB. El contrato actual no expone ese nombre; si cambia en COM,
actualizar `CLUB_PURCHASE_RECIPIENT` en el archivo privado CLUB y regenerar config.
Sin QR disponible, destinatario o conexión vigente, no se ofrece pago.

Un comprobante JPG/PNG/PDF de hasta 10 MiB se valida por contenido y se transfiere
firmado al almacenamiento privado COM; CLUB no publica ni conserva una copia en
su almacenamiento público. COM mantiene IA, revisión, conciliación y activación.
La revisión muestra: «¡Recibimos tu comprobante! Estamos verificando tu pago.»
Una respuesta atrasada no revierte revisión ni activación.

El receptor comercial acepta activaciones CLUB con sujeto nulo y eventos
`membership.identity_linked`, conserva cuerpo original, detecta duplicados y
conflictos, y tolera vínculo anterior a activación. Un vínculo específico nunca
se degrada a null. Solo activación confirmada por COM cuenta como membresía;
ningún evento de membresía ni envío de comprobante genera comisión.
`InitiateCheckout` se cuenta una vez por intención como agregado local, sin
emitir otro evento de pixel/CAPI ni duplicar IDs de COM.

La compra invitada conserva el claim privado para vinculación posterior.
La posesión de sesión permite ver esa compra, sin acceso a la cuenta. La
recuperación entre dispositivos exige cuenta autenticada y correo verificado;
se busca con digest HMAC del email y se presenta el claim a COM. La autorización
de identidad COM existente sigue siendo necesaria para enlazar la membresía
al sujeto global real, nunca para completar el pago.

## Validación y despliegue

- `bash scripts/test-public-checkout.sh`: kernel Laravel y PostgreSQL en esquema
  temporal; API COM simulada con verificación real de firma, nonce y bytes del
  comprobante. Casos de invitado, lead, correo existente, sesión autenticada,
  atribución, idempotencia, revisión, privacidad, activación y eventos fuera de orden.
- `bash scripts/build-frontend.sh`: TypeScript estricto y Vite secuenciales.
- `bash scripts/check-public-checkout.sh`: 18 vistas en 390/1440, controles,
  ausencia de overflow y errores; todas las escrituras LIVE interceptadas.
- Regresión del CMS ejecutada en esquema aislado con el runner existente;
  resultados en `regression-landings/`, sin datos LIVE.
- `scripts/deploy-public-checkout.sh`: migraciones aditivas CLUB, assets con
  manifest al final y recreación exclusiva de app para cargar su env privado.
- `python3 scripts/validate-local.py`: comprobación local requerida.
- `bash scripts/check-public-checkout.sh --live`: rutas públicas reales y estados
  privados simulados, sin compras LIVE.
- `python3 scripts/verify-public-checkout-live.py`: SHA de main/origin, manifest y
  assets HTTPS, rutas públicas, QR COM real mediante GET HMAC, portada intacta y
  contenedores protegidos sin cambios. Sello local `LIVE.json`, excluido de Git.

No se crean intenciones ni pagos bancarios ficticios LIVE. Las pruebas de envío,
revisión y activación usan fixtures aislados y respuestas simuladas; la lectura
real de integración LIVE se limita al QR. Capturas locales excluidas de Git.

Rollback: fuente anterior y manifest en
`/srv/sites/jakawi.club/rollback-public-checkout`; restaurar el SHA/manifest,
regenerar config/rutas/vistas y reiniciar exclusivamente app. Conservar tablas,
compras, eventos y comprobantes; no revertir datos económicos ni COM.
