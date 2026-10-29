# Ajuste mínimo del bridge para CLUB

Estado comprobado el 29/10/2026: CLUB no llama a CRM y no tiene configuración ni credencial CRM independiente. Su base es PostgreSQL `jakawi_club`. No se ha enviado ningún contacto ni modificado COM/CRM.

El bridge instalado en CRM expone `POST /wp-json/jakawi-fluentcrm/v1/events`. `Contract.php` solo admite `contact.email/name` e `identity.jakawi_user_id/provider_contact_id` numéricos. No admite teléfono, identidad externa UUID ni estado de activación CLUB. `Adapter.php` busca y protege el campo numérico `jakawi_user_id` de COM. Su autenticación usa un único secreto HMAC. Enviar `users.uuid` de CLUB allí se rechazaría y reutilizar un ID entero local colisionaría con COM. No corresponde reutilizar el secreto de COM.

La extensión mínima, pendiente de autorización en CRM, puede permanecer en el plugin existente `jakawi-fluentcrm-bridge`:

1. Añadir `POST /wp-json/jakawi-fluentcrm/v1/club/events`, con secreto exclusivo CLUB y la misma firma HMAC del bridge: `timestamp + "\n" + event_id + "\n" + cuerpo JSON exacto`. Mantener ventana de cinco minutos y límite de 64 KiB. No cambiar el contrato ni el secreto de COM.
2. Validar un contrato acotado con `source_system=CLUB`, `external_id=users.uuid`, teléfono E.164, landing/campaña, referido externo y estado `pending|activated`. Rechazar passwords, tokens y claves desconocidas. Provisionar campos de texto `club_user_uuid`, `club_landing_slug`, `club_campaign`, `club_referrer_uuid` y `club_activation_status`; fechas `club_registered_at` y `club_activated_at`. Teléfono usa el campo nativo del contacto. No escribir `jakawi_user_id` ni campos de membresía COM.
3. Reutilizar el upsert de FluentCRM: resolver primero `club_user_uuid`, después email normalizado. Si ambos identifican contactos distintos, responder 409. Vincular al contacto existente por email sin crear usuarios WordPress/COM ni sobreescribir su identidad COM. Identificar origen mediante campos CLUB y etiqueta `source-club`. Actualizar los campos CLUB sin borrar etiquetas de otros orígenes.
4. Reutilizar el recibo de eventos del plugin, con identidad de productor incluida en la deduplicación: `(CLUB,event_id)`, mismo evento/cuerpo devuelve el mismo resultado. Mismo evento con distinto cuerpo responde 409. Serializar el upsert por UUID/email como ya hace el bridge para evitar duplicados concurrentes. Ignorar estados anteriores a la última actualización CLUB; una repetición de `pending` no debe desactivar una cuenta ya activada.
5. La aceptación de privacidad no concede marketing. Para un contacto nuevo usar `transactional`; conservar bajas, rebotes y consentimientos existentes. CLUB envía `marketing.action=UNCHANGED`; no inicia campañas ni automatizaciones comerciales por esta integración.

Ejemplo sintético del cuerpo propuesto, no enviado:

```json
{
  "version": 1,
  "event_id": "11111111-1111-4111-8111-111111111111",
  "operation": "CONTACT_UPSERT",
  "occurred_at": "2026-10-29T21:00:00Z",
  "source_system": "CLUB",
  "external_id": "22222222-2222-4222-8222-222222222222",
  "contact": {"name": "Persona de ejemplo", "email": "persona@example.invalid", "phone": "+59170000000"},
  "attribution": {"landing_slug": "principal", "campaign": "campana_aprobada", "referrer_external_id": null},
  "activation": {"status": "pending", "registered_at": "2026-10-29T21:00:00Z", "activated_at": null},
  "marketing": {"action": "UNCHANGED"}
}
```

Responder con `{"result_code":"OK","provider_contact_id":123}`; 401 para firma inválida, 422 para datos incompatibles, 409 para conflicto, 429/503 para reintento. La activación envía otro `event_id`, el mismo `external_id`, `status=activated` y su fecha real.

Después de habilitar este contrato y proporcionar autenticación independiente, el cambio CLUB debe emitir tras commit en registro y activación, con envío diferido y fallo aislado. Debe capturar durablemente landing/campaña al registrar: hoy solo existen en el recibo temporal y agregados, no en `users`. Una entrega diferida necesita conservar esa atribución junto al evento y el mismo `event_id` en sus reintentos. No añadir llamadas HTTP dentro de la transacción ni hacer que CRM condicione el registro. Este emisor no se ha construido ni activado para un endpoint que aún no admite el contrato.

Fuentes locales consultadas solo en lectura: `crm.jakawi.com/clean-wordpress/wp-content/plugins/jakawi-fluentcrm-bridge/{jakawi-fluentcrm-bridge.php,includes/Contract.php,includes/Adapter.php,includes/SchemaManager.php,includes/EventStore.php}` y `jakawi.com/app/app/Services/Crm/CrmBridgeClient.php`.
