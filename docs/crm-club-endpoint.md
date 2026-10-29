# CLUB → FluentCRM operativo

Endpoint: `POST https://crm.jakawi.com/wp-json/jakawi-fluentcrm/v1/club/events`. Contrato v1 `CONTACT_UPSERT`, `source_system=CLUB`, `external_id=users.uuid`, contacto nombre/email/teléfono E.164, atribución landing/campaña/referente UUID/UTMs y estado `pending|activated` con fechas. `marketing.action` admite únicamente `UNCHANGED`. El plugin resuelve esta ruta también con enlaces simples de WordPress, sin cambiar las rutas de COM.

Firma HMAC SHA-256 del cuerpo JSON exacto: `timestamp + "\n" + event_id + "\n" + raw_body`. Headers `X-Jakawi-Timestamp`, `X-Jakawi-Event-Id`, `X-Jakawi-Signature`; ventana de cinco minutos y límite 64 KiB. Secreto exclusivo CLUB en archivos privados montados, nunca en Git ni en el payload. No se usa el secreto COM.

CRM reutiliza el writer y transacción del plugin existente, con tabla de recibos exclusiva CLUB para separar `(productor,event_id)`. Resuelve UUID y después email; conflictos responden 409. Añade `source-club` y campos `club_*`, conserva identidad COM, suscripción, bajas, listas, tags y datos comerciales existentes. `club_name` y `club_phone` conservan los datos CLUB incluso si el perfil comercial previo tiene otros valores. Un contacto nuevo queda `transactional`; un pending atrasado no revierte activated. No crea usuarios WordPress/COM.

CLUB persiste `users.registration_origin` (landing ID/slug, campaña publicada y UTMs permitidas), junto a UUID, referente y fecha existentes. La procedencia es inmutable. Los históricos mantienen `null` si no hay fuente verificable. No se reconstruye desde el recibo temporal ni se inventan campañas.

Registro y activación encolan snapshots cifrados en `jobs`, cola `crm`, dentro de su transacción PostgreSQL. El worker solo ve filas confirmadas; no hay HTTP al CRM dentro de la petición pública. Usa el mismo `event_id` y cuerpo en reintentos. Doce intentos con backoff; 409/422/configuración inválida quedan visibles en `failed_jobs`, sin bloquear registro. Un HTTP 200 sin respuesta válida se reintenta.

Entrega operativa: `jakawi-club-crm.timer` ejecuta cada minuto un contenedor efímero limitado mediante `scripts/deliver-crm.sh`; procesa hasta 20 trabajos o 40 segundos, sin worker permanente ni otro servicio web. Usa la cola PostgreSQL existente.

Operación:

- Estado: `systemctl status jakawi-club-crm.timer` y `php artisan queue:failed` dentro de CLUB.
- Reintentar un fallo corregido: `php artisan queue:retry UUID_DEL_TRABAJO`. Conserva el evento original.
- Sincronizar una cuenta existente, sin modificarla ni enviar correo: `php artisan crm:sync-contact email`.
- Recuperar leads históricos: `php artisan crm:sync-contact --registered`; solo cuentas registradas con aceptación de privacidad y teléfono, sin inventar procedencia.

Pruebas: `bash scripts/test-crm-sync.sh` en CLUB; después `bash tests/run.sh` en `/opt/sites/crm.jakawi.com/bridge`. Usan PostgreSQL y tablas WordPress/FluentCRM aisladas, notificaciones falsas y ninguna cuenta LIVE nueva. Evidencia acotada en `artifacts/crm-sync/`.
