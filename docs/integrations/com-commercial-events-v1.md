# COM → CLUB: eventos comerciales v1

Extensión Fase 08: `membership.activated` tiene el formato específico sin
`conversion_status` y resolución por identidad descrito en
[el contrato de membresías](club-membership-funnel-08.md). Las reglas históricas
de atribución de operaciones de beneficios siguen vigentes.

Receptor production: **`POST https://jakawi.club/internal/commercial/events`**.
Sin barra final, query, cookies ni Bearer. HTTPS obligatorio. No usa CRM, identidad ni OIDC.
Máximo 65 536 bytes UTF-8. `Content-Type: application/json`; `Accept: application/json`.

## Autenticación exacta

Clave nueva exclusiva COM → CLUB comercial. CLUB la lee de
`/run/secrets/com_commercial_hmac`, montada desde el archivo privado
`/srv/sites/jakawi.club/secrets/com-commercial-hmac` (root:82, 0640).
Es una cadena aleatoria de 64 caracteres hexadecimales (256 bits); **usar los
caracteres ASCII como clave HMAC, no decodificar hex**. No está en Git ni en este documento.
Codex COM debe recibirla por el canal privado de secretos y guardarla como
`CLUB_COMMERCIAL_HMAC_SECRET` en COM. No se configura ni despliega COM en esta fase.

Headers obligatorios:

```http
X-Jakawi-Com-Key-Id: com-commercial-v1
X-Jakawi-Com-Timestamp: <Unix UTC en segundos, 10 dígitos>
X-Jakawi-Com-Signature: sha256=<HMAC SHA-256 hexadecimal minúscula, 64 caracteres>
```

Cadena firmada, sin salto de línea al final:

```text
<timestamp>\nPOST\n/internal/commercial/events\n<sha256 hexadecimal minúscula de los bytes HTTP del JSON>
```

`\n` representa un byte LF (0x0A). La firma es `hash_hmac('sha256', cadena, secreto)`.
Tolerancia de reloj ±300 segundos. Firmar **los bytes que realmente se envían**;
no volver a serializarlos después de calcular la firma. En cada reintento renovar
timestamp/firma y conservar event_id/contenido. HMAC autentica a COM y su declaración
de origen; un cliente público o el navegador nunca debe conocer la clave.

## JSON

Ejemplo mínimo del formato (los valores entre `<...>` deben proceder de COM;
este ejemplo documental nunca se envía ni siembra en producción):

```json
{
  "schema_version": 1,
  "event_id": "<ID estable del evento en COM>",
  "event_type": "order.confirmed",
  "occurred_at": "2026-10-29T19:20:30-04:00",
  "com_reference": { "type": "order", "id": "<ID original de la conversión en COM>" },
  "conversion_status": "confirmed"
}
```

Campos opcionales del mismo objeto; omitirlos o enviar `null` cuando no existan:

```json
{
  "partner": { "id": "<ID real del partner en COM>", "name": "<nombre real opcional>" },
  "origin": { "landing": "<URL o identificador original de landing>", "campaign": "<campaña original>" },
  "referral": { "com_referral_id": "<registro original del referido en COM>", "club_referral_code": "<código CLUB existente si COM lo capturó>" },
  "global_subject_id": "<UUID original emitido por COM, si está disponible>"
}
```

| Campo | Regla v1 |
| --- | --- |
| `schema_version` | Obligatorio, número entero JSON `1` (no string). |
| `event_id` | Obligatorio, 1–128 caracteres; `[A-Za-z0-9][A-Za-z0-9._:-]*`. Estable en todos los reintentos, único en COM. |
| `event_type` | Obligatorio, 1–100 caracteres; `[a-z][a-z0-9._-]*`. COM comunica su tipo real; los ejemplos no imponen un catálogo ficticio. |
| `occurred_at` | Obligatorio, RFC3339: segundos y zona `Z`/`±HH:MM`, hasta 6 decimales opcionales. Fecha válida y no más de 5 minutos futura. Se permiten eventos históricos. |
| `com_reference.type` | Obligatorio, 1–64 caracteres; mismo patrón que `event_type`. |
| `com_reference.id` | Obligatorio, string original 1–190 caracteres. No se convierte un ID numérico implícitamente: serializarlo como string. |
| `partner` | Opcional/null; si existe exige `id` original, string 1–190. `name` opcional/null, hasta 190. No exige cuenta CLUB del partner. |
| `origin` | Opcional/null. `landing` opcional/null, hasta 2048 caracteres; URL o ID original, solo texto (nunca se visita). `campaign` opcional/null, string 1–190. |
| `referral` | Opcional/null. Si existe, al menos un `com_referral_id` original (string 1–190) o `club_referral_code` existente (`[A-Z0-9]{12}`). |
| `global_subject_id` | Opcional/null, UUID. Se conserva como procedencia; no crea ni vincula cuentas CLUB. |
| `conversion_status` | Obligatorio, 1–64 caracteres; `[a-z][a-z0-9._-]*`. Estado real de COM, sin cálculo de comisiones. |

Los identificadores no pueden tener controles ni espacios al inicio/final. Los
objetos solo admiten las claves enumeradas. No enviar emails, contraseñas, importes,
datos CRM, roles, saldos ni propiedades adicionales. No inventar partner, landing,
campaña, referido ni global_subject_id para rellenar campos ausentes.

Si se informa código CLUB, el receptor lo busca en CLUB: desconocido → `422` sin
guardar el evento. Si existe conserva el UUID del referente como evidencia histórica,
sin modificarlo. El panel indica **Código CLUB verificado**. Esto acredita la existencia
del código; HMAC acredita que COM lo asoció a esa conversión. Un `com_referral_id`
sin código CLUB se conserva como **Referencia informada por COM**: no se presenta
como verificación independiente ni se inventa una cuenta local. No se infieren
referidos por email, sesión, partner o global_subject_id.

## Respuestas e idempotencia

```http
HTTP/1.1 201 Created
Content-Type: application/json
Cache-Control: no-store

{"status":"accepted","event_id":"<ID original>"}
```

Repetición → `200 {"status":"duplicate","event_id":"<ID original>"}`.
Conflicto → `409 {"error":"event_id_conflict","event_id":"<ID original>"}`.

La unicidad `(source=jakawi.com,event_id)` está protegida por PostgreSQL, también
ante concurrencia. El primer evento nunca se sobrescribe. Se conservan bytes
originales, hashes de cuerpo y contenido, key_id, timestamp firmado, fecha de
recepción y origen. Un conflicto conserva su propio cuerpo/hashes/procedencia
una vez por contenido diferente; no incrementa conversiones.

La igualdad de contenido ignora orden de claves y formato/espacios del JSON;
conserva valores y tipos. Omitido y `null` son contenidos diferentes. Los reintentos
no actualizan fechas ni revalidan referentes históricos ya aceptados. Cada cambio
real de estado requiere **nuevo event_id** y la misma referencia original COM.
El panel lista eventos: varios cambios de una misma referencia son filas históricas,
no un agregado de ventas únicas ni una proyección de estado final.

| HTTP | Significado / acción COM |
| --- | --- |
| 200 / 201 | Entrega completada; no volver a crear un evento. |
| 400 | `invalid_target`, `invalid_json` o `invalid_json_object`; corregir petición. |
| 401 | `unauthenticated`; revisar key_id, secreto, bytes, firma y reloj. |
| 409 | `event_id_conflict`; detener reintentos automáticos, investigar; nunca cambiar ID solo para eludir el conflicto. |
| 413 | `payload_too_large`; máximo 64 KiB. |
| 415 | `unsupported_media_type`; usar JSON. |
| 422 | Validación Laravel `{message,errors}` por campo; corregir contrato o referido. |
| 503 | `receiver_unavailable`; clave comercial no disponible. Reintentar con backoff. |
| Otros 5xx / timeout | Reintentar con backoff y jitter, mismo evento/contenido y nueva firma. |

Errores de método/ruta pueden devolver `404`/`405`. Los fallos de autenticación no
guardan eventos ni crean sesiones. No se requieren nonces: un replay autenticado
solo puede devolver el recibo previo; no produce efectos adicionales.

## Emisor PHP para Codex COM

```php
$raw = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$timestamp = (string) time();
$canonical = $timestamp."\nPOST\n/internal/commercial/events\n".hash('sha256', $raw);
$headers = [
    'Accept' => 'application/json',
    'Content-Type' => 'application/json',
    'X-Jakawi-Com-Key-Id' => 'com-commercial-v1',
    'X-Jakawi-Com-Timestamp' => $timestamp,
    'X-Jakawi-Com-Signature' => 'sha256='.hash_hmac('sha256', $canonical, $commercialSecret),
];
// Laravel HTTP: enviar exactamente $raw, no usar post($url, $event) tras firmarlo.
$response = Http::withHeaders($headers)->withBody($raw, 'application/json')
    ->connectTimeout(5)->timeout(15)
    ->post('https://jakawi.club/internal/commercial/events');
```

Crear outbox durable en COM con ID y contenido estables queda a cargo de Codex COM.
Este despliegue solo implementa CLUB. No envía conversiones ficticias LIVE.

## Panel y operación CLUB

`https://jakawi.club/admin/conversiones-com`, accesible desde **CONVERSIONES COM**
en la administración existente. Exige sesión CLUB, email verificado, activación y
permiso administrativo `is_marketing_admin`. Solo lectura; 25 eventos por página,
filtros exactos por ID de partner y campaña, fecha en Bolivia. Ausencias explícitas;
sin enlaces externos ejecutables ni datos sensibles del cuerpo original.

Pruebas: `bash scripts/test-commercial.sh` usa esquema PostgreSQL temporal, kernel
HTTP real, autenticación, reintentos/concurrencia/conflictos, atribución y panel.
`bash scripts/build-frontend.sh` valida TypeScript y genera `public/build-next`.
`bash scripts/check-commercial-visual.sh` valida el panel con fixtures exclusivamente
en navegador, sin sesión ni cuentas LIVE. Tras publicar usar `--live`.

Despliegue: fast-forward main al commit revisado y push normal; aplicar solo esta
migración aditiva; instalar el secreto privado y su mount; publicar assets conservando
chunks anteriores y manifest al final; recrear únicamente `app`, generar config/rutas/vistas
y renovar OPcache. Nginx y PostgreSQL no requieren reinicio. Verificar con
`python3 scripts/verify-commercial-live.py`, que sella SHA Git/origin y código montado,
assets HTTPS, receptor autenticado y panel protegido. El `LIVE.json` queda ignorado.

Rollback: respaldo privado `/srv/sites/jakawi.club/rollback-commercial-phase05/`.
Restaurar únicamente el código/manifest anteriores y compose previo, regenerar cachés
y recrear solo `app`. Conservar tablas, eventos, conflictos y clave; no ejecutar
rollback de migración con eventos. No toca landings públicas, registro, SSO, SES,
FluentCRM, comisiones, pagos ni saldos.
