# JAKAWI — Contrato coordinado de identidad, atribución y CRM v1

Fecha: **29 de octubre de 2026**, America/La_Paz. Autor de esta revisión: Lead Architect de JAKAWI.CLUB.

**Estado: propuesta técnica coordinada desde CLUB, pendiente de conformidad del Lead Architect de COM.** Las decisiones del propietario se aceptan; este documento no acredita aprobación técnica de COM ni implementa integraciones.

Convenciones utilizadas en todo el contrato:

| Estado | Significado |
| --- | --- |
| **ACEPTADO COMO DECISIÓN DE NEGOCIO** | Distribución de responsabilidades y restricciones decididas por el propietario. |
| **PROPUESTO TÉCNICAMENTE** | Diseño sometido a revisión conjunta; aún puede cambiar. |
| **IMPLEMENTADO HOY** | Comprobado en el código o entorno indicado; una evidencia histórica se identifica como tal. |
| **PENDIENTE DE IMPLEMENTAR** | Capacidad ausente o trabajo posterior; no constituye autorización para ejecutarlo. |

## 1. Decisiones del negocio y alcance de esta revisión

**ACEPTADO COMO DECISIÓN DE NEGOCIO:** un ecosistema, una identidad global vinculada, dos aplicaciones de negocio independientes y un CRM de comunicaciones. Cada aplicación conserva sus datos y sesiones locales. No se comparten tablas, contraseñas ni bases de datos.

CLUB será el motor de crecimiento comercial y la única autoridad económica de afiliación. COM conservará la operación del negocio, sus cuentas definitivas y sus landings de Partners y productos. CRM gestionará contactos y comunicaciones; no será autoridad de identidad, sesiones, membresías, pagos ni comisiones.

Esta intervención revisa compatibilidad y correo en solo lectura y añade documentación. No implementa SSO, vinculación, sincronización, provisionamiento, migración de referidos, comisiones, pagos, liquidaciones ni cambios en CRM/COM. No abre el registro ni envía correos.

### Base real examinada

El baseline solicitado fue `c315fe625b402fc2408f4d5ccb983ecc4e2a0908`. Al iniciar, `main`, HEAD, `origin/main` y la referencia remota coincidían en **`6dc383d6f4913fb9065b17f0b668fef6d52d534d`**, con árbol limpio. `git pull --ff-only origin main` confirmó que no había cambios por incorporar.

El baseline solicitado es ancestro del actual. Los tres commits posteriores son `9f47397` (confirmación por landing y consentimiento), `4e12a85` (verificación de URL canónica) y `6dc383d` (evidencia de medición). Se revisa el código actual, sin retroceder esos cambios. El fragmento histórico del informe de registro no describe íntegramente el comportamiento actual.

LIVE: 368 archivos versionados montados coincidieron con Git y 19 assets HTTPS con el manifest local. `/up` de CLUB, COM y loopback devolvieron 200; el POST público `/registro` con CSRF válido devolvió **403**. Estos controles no son un nuevo despliegue. El commit que incorpore este documento será documental; el código funcional y los assets permanecerán iguales a los examinados.

## 2. Responsabilidades de las tres aplicaciones

**ACEPTADO COMO DECISIÓN DE NEGOCIO:**

| Área | JAKAWI.CLUB | JAKAWI.COM | CRM.JAKAWI.COM |
| --- | --- | --- | --- |
| Crecimiento | Leads, landings externas, campañas, webinars e invitaciones | Captación directa desde sus productos y Partners | Proyección de contactos, listas, tags y segmentación |
| Identidad | Cuenta y verificación locales; futuro cliente de identidad | Cuenta operativa y autoridad inicial propuesta para identidad global | Identificador de contacto, sin autenticación de miembros |
| Producto | Contexto comercial y referencias externas | Membresías, derechos, Partners, Locations, Benefits, Experiences, Challenges y reservas | Sin autoridad de producto |
| Transacciones | Recibe hechos comerciales verificables en el futuro | Precios, disponibilidad, compras, pagos, canjes y transacciones con Partners | Sin autoridad transaccional |
| Afiliación económica | Códigos, referente, atribución, elegibilidad, tasas, comisiones, saldos, solicitudes y liquidaciones | Comunica hechos; puede conservar invitaciones sociales sin duplicar comisión | Sin cálculos ni saldos de afiliados |
| Comunicaciones | Consentimientos propios y correo de acceso local | Consentimientos propios y correo de acceso operativo | Preferencias comerciales, supresiones, campañas y automatizaciones |

La autoridad de identidad operativa de COM no convierte automáticamente cada lead de CLUB en miembro de COM. La cuenta local y el dashboard gratuito actual de CLUB no conceden derechos sobre membresías, beneficios ni transacciones de COM.

## 3. Identidad global propuesta

**PROPUESTO TÉCNICAMENTE:** COM emitirá o gobernará un `global_subject_id` UUID estable. Un servicio dedicado bajo esa autoridad es una evolución posible. Emisión, almacenamiento, unicidad, revocación del vínculo y resolución de conflictos requieren acuerdo técnico antes de implementarse.

```text
global_subject_id (UUID, futuro)
    +-- jakawi_user_id (identificador local de COM)
    +-- club_user_id   (UUID estable de CLUB: users.uuid)
    +-- crm_contact_id (referencia de contacto; no identidad autenticada)
```

**IMPLEMENTADO HOY — precisión obligatoria:** CLUB tiene **`users.id` bigint como PK** y **`users.uuid` UUID único, obligatorio y protegido frente a cambios**. Las referencias internas, incluido `referred_by_id`, utilizan bigint. Esto está confirmado tanto en migraciones como en `information_schema.columns` de producción. La descripción «users.id UUID de CLUB» del contrato recibido no corresponde al esquema real.

**PROPUESTO TÉCNICAMENTE:** en los futuros mensajes, `club_user_id` designará `users.uuid`; el bigint permanecerá interno y no se sustituirá. COM debe ratificar este nombre y su tipo. No se enviará ese UUID en `jakawi_user_id`, cuyo significado pertenece a COM. `global_subject_id` no reemplazará ninguno de los identificadores locales.

La vinculación deberá registrar aplicaciones/identificadores locales, estado, método y evidencia verificable, autoridad emisora, fecha, conflictos y revocaciones. Estados conceptuales: pendiente, vinculado, en conflicto y revocado. Unicidad por aplicación e identificador local evitará vincular una cuenta a dos sujetos activos. La revocación de un vínculo no borrará la cuenta ni reescribirá atribuciones económicas históricas.

Email y WhatsApp normalizados solo permiten encontrar candidatos. No son identidad global inmutable ni prueba suficiente de titularidad. No se copiarán contraseñas, ni se fusionarán/provisionarán cuentas existentes por coincidencia de email. La persona deberá autenticarse en la cuenta que se vincula y autorizar el vínculo. Un cambio posterior de email no cambiará el sujeto global.

**PENDIENTE DE IMPLEMENTAR:** sujeto global, registro de vínculos y sus evidencias, políticas de conflictos y revocación. No están presentes en CLUB.

## 4. Flujo futuro CLUB → COM

**ACEPTADO COMO DECISIÓN DE NEGOCIO; mecanismo PROPUESTO TÉCNICAMENTE, PENDIENTE DE IMPLEMENTAR:**

1. La persona llega desde una landing, campaña, webinar o invitación de CLUB y presenta el formulario compartido.
2. CLUB conserva el lead y su procedencia válida: landing, campaña, UTM cuando existan, referente, momento de captación y evidencias de consentimiento correspondientes.
3. CLUB verifica el correo mediante entrega transaccional real. WhatsApp no se declarará verificado por haberse normalizado a E.164.
4. La persona autenticada solicita «Activar mi JAKAWI». CLUB inicia un handoff autenticado de servidor a servidor, con identificador estable para reintentos.
5. COM resuelve el caso: cuenta nueva con provisionamiento autorizado, cuenta existente que exige autenticación del titular, falta de verificación o conflicto. Nunca vincula una cuenta existente solo por email.
6. COM devuelve un resultado correlacionado y, cuando proceda, la identidad global y el vínculo. CLUB persiste ese resultado sin sustituir su cuenta local ni su referente.
7. La navegación continúa mediante un mecanismo de sesión acordado. Los datos válidos y autorizados de nombre, email y WhatsApp se reutilizan para evitar repetir el formulario; los datos insuficientes se solicitan explícitamente.

Ni un recibo de registro ni un handoff pendiente equivalen a una cuenta operativa activada. El handoff no concede por sí mismo membresías, compras ni derechos de COM.

**IMPLEMENTADO HOY:** CLUB crea un `User` local pendiente, sin contraseña inicial, cuando el registro está habilitado; no tiene una entidad Lead separada. Actualmente el registro está bloqueado. No hay botón operativo hacia COM, endpoint de handoff ni provisionamiento. La retención individual completa de UTM y campaña de este flujo también es trabajo futuro (sección 13).

## 5. Flujo futuro COM → CLUB

**ACEPTADO COMO DECISIÓN DE NEGOCIO:** este flujo es obligatorio y tiene la misma relevancia que CLUB → COM.

**PROPUESTO TÉCNICAMENTE, PENDIENTE DE IMPLEMENTAR:**

1. La persona entra en una landing de Partner, Benefit, Experience o Challenge de COM.
2. COM capta al visitante, crea o autentica su cuenta operativa y mantiene el contexto del producto y la transacción.
3. COM conserva la procedencia comercial disponible, incluida una referencia comercial de CLUB cuando sea válida. Un acceso directo sin atribución válida permanece sin referente económico.
4. COM publica hacia CLUB un evento autenticado de captación o registro, con identidad vinculada cuando exista y evidencia de procedencia. No se transmiten contraseñas ni sesiones.
5. CLUB reconoce al sujeto ya vinculado o deja el evento pendiente de resolución. No crea ni fusiona automáticamente cuentas por coincidencia de email. El contrato deberá permitir conservar el hecho comercial pendiente sin exigir crear inmediatamente una cuenta autenticada de CLUB.
6. CLUB valida el código/evidencia, conserva la atribución que corresponda y evita sobrescribir su primer referente permanente con un toque posterior.
7. COM comunica una transacción elegible confirmada y sus cambios posteriores. CLUB decide elegibilidad y comisión conforme a sus reglas futuras. COM conserva la transacción y no calcula una segunda comisión económica.

El sobre de eventos propuesto incluirá `event_id`, versión, `source_app`, `occurred_at`, identificadores comerciales locales de COM, sujeto/vínculo cuando exista, contexto de captación y referencia comercial verificable. Los hechos de transacción añadirán identificador estable, estado, importe, moneda y referencia a la captación necesaria. Nombres de eventos, endpoints, esquema final y tratamiento de devoluciones/cancelaciones aún requieren contrato propio; aquí no se declaran operativos.

Una referencia en query o una declaración del navegador no acreditan por sí solas una comisión. COM deberá preservar un contexto de referencia validable por CLUB, con integridad, vigencia y correlación acordadas. La vinculación posterior de identidad no inventará ni reemplazará atribuciones ausentes.

## 6. Landings de Partners y productos en COM

**ACEPTADO COMO DECISIÓN DE NEGOCIO:** COM conserva sus páginas de Partners y productos y su captación directa. No se traslada todo el catálogo ni todas sus landings a CLUB.

CLUB podrá enlazar campañas con identificadores opacos de esos productos y recibir eventos comerciales. No replicará precios, disponibilidad, reservas, pagos, canjes ni operaciones de Partners como fuente de verdad. Los snapshots del editor de CLUB son contenido editorial de CLUB, no un catálogo transaccional compartido.

**PENDIENTE DE IMPLEMENTAR:** transporte de procedencia entre dominios, validación comercial de la referencia, eventos COM → CLUB y reconciliación de resultados. Las cookies locales actuales de CLUB no hacen esa transferencia.

## 7. Centralización económica de referidos y afiliados

**ACEPTADO COMO DECISIÓN DE NEGOCIO:** CLUB será la única autoridad de códigos de invitación comerciales, relaciones de referidos, atribución económica, elegibilidad, tasas, comisiones, saldos, solicitudes de pago y liquidaciones a afiliados.

COM mantiene la autoridad de compras y pagos del negocio. La liquidación a un afiliado y el pago de una compra son responsabilidades distintas. Las invitaciones sociales/comunitarias de COM pueden persistir, sin generar una comisión económica paralela.

**IMPLEMENTADO HOY en CLUB:** código personal, enlace `/r/{code}` y primer referente permanente, protegido por modelo y trigger PostgreSQL, con restricción contra autorreferencia. No hay motor de comisiones, saldos ni liquidaciones. Un registro repetido no crea otra cuenta ni cambia referente ni suma conversión.

**PENDIENTE DE IMPLEMENTAR:** reglas económicas y proyecto separado de transición del motor existente de COM. Antes de migrar deberán acordarse corte, reglas y saldos históricos, reconciliación, tratamiento de conflictos y una única fuente de cálculo por transacción. No se retiran tablas, desactivan funciones, cambian cálculos ni migran afiliados en esta tarea.

## 8. CRM y sincronización de contactos

### Estado observado y contrato recibido

**IMPLEMENTADO HOY, verificado en CRM:** WordPress, FluentCRM, FluentSMTP **2.4.1**, MariaDB independiente, Nginx/PHP-FPM Docker y Jakawi FluentCRM Bridge **1.0.1**, activo y habilitado. No se leyeron fichas personales ni contenido de listas/contactos.

**REPORTADO POR COM, no revalidado en su repositorio en esta intervención:** `CrmContactProjectionService` → `crm_deliveries` → `crm:dispatch` → HTTPS/HMAC → bridge. El contrato recibido describe outbox cifrado y estados PENDING, PROCESSING, SENT, RETRY y DEAD. Esto no demuestra una entrega nueva después de la migración.

**IMPLEMENTADO HOY, cotejado en el código del bridge:** `CONTACT_UPSERT` versión 1, event ID UUID, HMAC-SHA256 sobre timestamp, event ID y cuerpo exacto separados por saltos de línea; ventana de 300 segundos; validación estricta de campos; deduplicación por event ID y hash del cuerpo; conflicto 409 para el mismo ID con contenido diferente. Conserva FIRST/LATEST y preferencias/supresiones comerciales. Las listas Leads, Usuarios y Partners son proyecciones de comunicación, no membresías ni prueba de identidad.

### URL efectiva después de migración

**IMPLEMENTADO HOY, comprobación de metadatos sin POST:**

| URL | Resultado el 29/10/2026 |
| --- | --- |
| `https://crm.jakawi.com/?rest_route=/jakawi-fluentcrm/v1/events` | OPTIONS 200 JSON; método POST registrado en el índice REST. Ruta utilizable para el futuro emisor. |
| `https://crm.jakawi.com/wp-json/jakawi-fluentcrm/v1/events` | OPTIONS 200 **HTML**, sin metadatos REST. No tratar ese 200 como confirmación del endpoint. |

No se invocó el callback de escritura ni se probó una proyección nueva. El agregado del bridge contiene 12 eventos SENT, último procesamiento **14/10/2026 18:26:03 UTC**, sin procesamientos posteriores al corte de referencia de migración. SENT aquí significa proyección de contacto procesada, no correo entregado.

### Compatibilidad pendiente para CLUB

El bridge v1 solo admite `identity.jakawi_user_id` y `provider_contact_id` numéricos. Su lista de campos permitidos no incluye `global_subject_id` ni `club_user_id`. **No es compatible con enviar directamente el UUID de CLUB en un campo de COM ni con añadir campos arbitrarios.** La coincidencia de email que usa una proyección CRM no autentica ni vincula cuentas de negocio.

**PROPUESTO TÉCNICAMENTE / PENDIENTE DE IMPLEMENTAR:** acordar una extensión versionada y compatible del bridge para productor CLUB, identificadores tipados/globales, credenciales independientes por emisor, relojes de actualización y supresiones; después construir una outbox propia de CLUB. La clave HMAC actual de COM no se copiará. No se obligará a crear una cuenta COM solo para proyectar un lead CLUB al CRM.

El acuerdo de privacidad de CLUB no equivale a opt-in comercial. No convertirlo en `marketing.action=GRANT`. Hasta disponer de una preferencia comercial explícita y su contrato, conservar la ausencia de consentimiento y las supresiones existentes. Cualquier futura proyección deberá acordar esta semántica; no se ejecuta ahora.

## 9. Correo transaccional y ruta SMTP definida

### A–F. Infraestructura real y alcance de la evidencia

**IMPLEMENTADO HOY, inspección de solo lectura del CRM y consultas AWS realizadas el 29/10/2026 a las 10:02:43 America/La_Paz:**

| Pregunta | Resultado y límite |
| --- | --- |
| A. Transporte saliente | FluentSMTP utiliza **Amazon SES por API HTTPS**; su handler llama a `sendRawEmail`. El bridge CONTACT_UPSERT no es un mailer. |
| B. Plugin de email | `fluent-smtp/fluent-smtp.php`, activo, versión 2.4.1; conexión SES seleccionada como predeterminada. |
| C. Proveedor/región | Amazon SES, **us-east-1**; API `email.us-east-1.amazonaws.com`. |
| D. Configuración efectiva | `key_store=wp_config`, claves presentes mediante secreto montado, sin exponer valores. GetAccount respondió 200: SendingEnabled=true, ProductionAccessEnabled=true y EnforcementStatus=HEALTHY. Cuota: 5.000 destinatarios/24 h, 2/s; uso informado: 6 en las últimas 24 h. |
| E. Remitente | CRM configura **info@jakawi.com**, nombre Jakawi. La identidad de dominio `jakawi.com` respondió 200: VerifiedForSendingStatus=true, VerificationStatus=SUCCESS, firma DKIM habilitada/SUCCESS y custom MAIL FROM SUCCESS. La consulta individual de `info@jakawi.com` respondió 403 y no permite certificar una identidad individual; el dominio sí está verificado. La identidad `jakawi.club` respondió 404 en esta cuenta/región. La aprobación del remitente para CLUB sigue pendiente. |
| F. Entregas tras migración | La tabla `wp_fsmpt_email_logs` está vacía. GetSendStatistics de SES respondió 200: 38 puntos, 40 DeliveryAttempts en la ventana histórica consultada; 6 posteriores al corte de referencia y último punto a las 13:32 UTC del 29/10. Bounces/Complaints/Rejects agregados: 0. Estos datos corresponden a la cuenta/región SES; no identifican la aplicación emisora ni acreditan recepción, lectura o activación desde CRM/CLUB. |

El corte de referencia es el informe CRM `production-verification.json`, disponible el 26/10/2026 a las 16:48 UTC; no se reconstruye aquí el instante exacto de la migración. Los agregados posteriores no prueban que el CRM haya originado esos envíos. No se consultaron destinatarios, asuntos, cuerpos, contactos ni direcciones suprimidas.

Fuentes locales inspeccionadas en `/opt/sites/crm.jakawi.com/`: `compose.yaml`, `clean-wordpress/wp-content/plugins/fluent-smtp/` (configuración, handler SES y logger), configuración seleccionada de `wp_options`, metadatos/agregados de logs y bridge, `ses-readonly-verification.json` y `production-verification.json`. Se consultaron únicamente GetAccount, GetEmailIdentity y GetSendStatistics contra AWS; no SendEmail/SendRawEmail. La evidencia histórica de API operativa del 26/10 se diferencia de estas consultas actuales.

### G–H. Cómo aprovechar SES desde CLUB

**PROPUESTO TÉCNICAMENTE:** reutilizar el proveedor y la identidad de dominio ya verificados, con **credenciales SMTP exclusivas para CLUB**. No copiar claves API, contraseñas SMTP ni secretos HMAC desde CRM o COM. Compartir proveedor/cuenta implica compartir cuota y reputación, aunque las credenciales tengan revocación y rotación independientes.

Las credenciales SMTP de SES son específicas de región y difieren de las claves AWS usadas por la API. El operador de AWS deberá emitir credenciales dedicadas con permiso de envío mínimo para el remitente aprobado en us-east-1. La identidad de dominio verificada no prueba por sí sola que esa nueva credencial esté autorizada. [Credenciales SMTP de SES](https://docs.aws.amazon.com/ses/latest/dg/send-email-smtp.html).

Se comprobaron conexiones acotadas desde el contenedor **app de CLUB**, con validación de certificado y nombre TLS, sin autenticación ni comandos MAIL/RCPT/DATA:

| Destino `email-smtp.us-east-1.amazonaws.com` | Resultado |
| --- | --- |
| 465 TLS / 587 TCP | No se logró conexión dentro de 8 segundos por puerto. No se determina la causa ni se cambia firewall/red. |
| **2465 TLS implícito** | Conexión, certificado/nombre TLS válidos y saludo SMTP 220. **Ruta preferida.** |
| 2587 STARTTLS | Conexión, saludo 220, STARTTLS anunciado y negociación TLS válida. Alternativa. |

SES documenta 2465 para TLS implícito y 2587 para STARTTLS. No hace falta modificar WordPress, añadir un relay ni abrir puertos entrantes de CLUB. [Puertos/TLS de SES](https://docs.aws.amazon.com/ses/latest/dg/smtp-connect.html), [endpoint regional](https://docs.aws.amazon.com/general/latest/gr/ses.html).

Configuración mínima **futura, no aplicada**, después de aprobar remitente y emitir credenciales; los marcadores no son valores utilizables:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=email-smtp.us-east-1.amazonaws.com
MAIL_PORT=2465
MAIL_USERNAME=<CREDENCIAL_SMTP_EXCLUSIVA_CLUB_US_EAST_1>
MAIL_PASSWORD=<SECRETO_SMTP_EXCLUSIVO_CLUB>
MAIL_FROM_ADDRESS=<REMITENTE_APROBADO_BAJO_JAKAWI.COM>
MAIL_FROM_NAME="JAKAWI"
MAIL_EHLO_DOMAIN=jakawi.club
JAKAWI_TRANSACTIONAL_MAIL_READY=false
JAKAWI_REGISTRATION_OPEN=false
```

`info@jakawi.com` es candidato por estar configurado en CRM bajo el dominio verificado; el propietario debe aprobar su uso para CLUB o escoger otro remitente de ese dominio. Un remitente `@jakawi.club` necesita verificar esa identidad antes de usarlo. No se prescribe una dirección nueva inexistente.

La configuración local lee `MAIL_SCHEME`; no lee `MAIL_ENCRYPTION`. `MAIL_MAILER=smtp` mantiene `transport=smtp`, y `MAIL_SCHEME=smtps` obliga TLS incluso en el puerto alternativo 2465. No requiere otra dependencia PHP ni alterar `LaunchSettings::mailReady()`. Verificar que `MAIL_URL` no sobrescriba los campos explícitos. La alternativa 2587 necesitaría configuración de STARTTLS obligatorio; no sustituir solo el número de puerto conservando `smtps`.

La API SES por HTTPS es otra opción futura, pero el guard actual solo acepta SMTP y la dependencia AWS SDK no está instalada como paquete en CLUB. Adoptar API exigiría una tarea funcional separada, instalar la dependencia con los límites del repositorio y revisar expresamente el guard, sin relajar sus garantías. No es necesario hacerlo mientras exista la ruta SMTP 2465 comprobada. [Correo Laravel](https://laravel.com/framework/docs/mail).

### I. Qué falta para la primera prueba real

**PENDIENTE DE IMPLEMENTAR / OPERAR, mediante una petición posterior:**

1. El propietario aprueba remitente y buzón destinatario de prueba; el operador SES entrega de forma privada credenciales dedicadas de CLUB, con permiso de envío restringido. No enviarlas por Git/chat/logs ni reutilizar las de CRM.
2. Configurar únicamente los secretos privados de CLUB, aplicar el procedimiento existente de recreación exclusiva de app y caché de configuración, y mantener registro público cerrado. No ejecutar ese procedimiento durante esta revisión documental.
3. Autorizar y enviar **un mensaje transaccional controlado** mediante el transporte Laravel configurado, con destinatario permitido y sin tokens de activación. Este envío aislado no requiere poner `JAKAWI_TRANSACTIONAL_MAIL_READY=true`, ya que esa bandera gobierna AccountMail, no el transporte Laravel en sí.
4. Correlacionar aceptación SES, recepción real en el buzón y encabezados SPF/DKIM/DMARC, además de verificar la gestión de supresiones/rebotes. El mensaje y sus datos privados no se publican en artifacts.
5. Después, autorizar expresamente una cuenta de prueba y el ensayo acotado de activación/verificación/recuperación. AccountMail exige la bandera de aprobación de correo; su habilitación controlada y cualquier alta de prueba requerirán ese alcance explícito, manteniendo `JAKAWI_REGISTRATION_OPEN=false`. No retirar el guard ni usar una apertura pública como mecanismo de prueba.
6. Documentar entrega y activación efectivas y revisar los restantes pendientes operativos ya descritos en [operación del MVP](../../artifacts/mvp/OPERACION.md), incluido aviso de privacidad. Solo una aprobación posterior de apertura permitirá habilitar `JAKAWI_REGISTRATION_OPEN=true` junto a correo aprobado.

**IMPLEMENTADO HOY / estado preservado:** `MAIL_MAILER=log`, `JAKAWI_TRANSACTIONAL_MAIL_READY=false`, `JAKAWI_REGISTRATION_OPEN=false`, `LaunchSettings::mailReady()=false`, `registrationOpen()=false`, APP_DEBUG=false, sin host SMTP remoto ni credenciales SMTP configurados en CLUB. No se enviaron mensajes, generaron tokens ni crearon usuarios de prueba durante esta tarea.

## 10. Contrato futuro de handoff

**PROPUESTO TÉCNICAMENTE, PENDIENTE DE IMPLEMENTAR:** `jakawi-identity-handoff-v1`, con receptor conceptual **`POST /internal/identity/handoffs` en COM**. No existe como endpoint operativo confirmado. No se crea en esta tarea.

| Campo conceptual | Regla por acordar en el esquema definitivo |
| --- | --- |
| `handoff_id` | UUID estable de la operación; igual en reintentos. |
| `source_app` | Emisor autenticado, por ejemplo CLUB; no confiar en una etiqueta del cuerpo sin vincularla a la credencial. |
| `club_user_id` | UUID de `users.uuid`; nunca asumir que `users.id` ya es UUID. |
| `club_lead_id` | Identificador de lead futuro. CLUB no tiene hoy esa entidad; definir nulabilidad/relación antes de exigirlo, sin inventar un segundo ID. |
| Nombre/email/WhatsApp | Normalizados; origen, vigencia y verificación por atributo. Email verificado no acredita el teléfono. |
| Evidencia de verificación | Método, aplicación emisora, fecha y referencia verificable; un booleano declarado o sesión activa no bastan. |
| Consentimiento/versión | Finalidad, texto/versionado, origen y momento; distinguir privacidad, comunicaciones y autorización del vínculo. |
| Campaña/landing/UTM/referente | Contexto comercial válido, opcional cuando no existe; no sustituye evidencia de titularidad. |
| Correlación/vigencia | Versión, momento, expiración y destino preaprobado; concretar firma y claves. |

Resultados funcionales propuestos: `PROVISIONED`, `LINKED`, `AUTH_REQUIRED`, `VERIFICATION_REQUIRED`, `PENDING` y `CONFLICT`. No representan todavía enums ni respuestas HTTP implementadas. La respuesta deberá correlacionar el handoff y declarar el vínculo únicamente tras completar sus requisitos.

Se requiere autenticación servidor-servidor independiente del bridge CRM, integridad del cuerpo, protección contra replay, expiración, idempotencia persistente, autorización de provisionamiento y vinculación explícita de cuentas existentes. Los detalles del protocolo, claves/rotación, errores, tiempos y resolución interactiva están pendientes de conformidad. La UI pública no revelará si existe un email ajeno. Los redirects se limitan a destinos registrados; no se aceptarán URLs arbitrarias.

El endpoint interno no crea automáticamente una sesión de navegador. El intercambio interactivo de continuación y el futuro SSO deben acordarse sin transportar contraseñas ni tokens reutilizables en URLs.

## 11. Sesiones y SSO futuros

**IMPLEMENTADO HOY en CLUB:** guard web de sesión local, `User implements MustVerifyEmail`, activación/verificación propias y broker local de contraseñas. No hay cliente OIDC ni SSO con COM.

**REPORTADO POR COM, no corregido ni auditado desde CLUB:** Fortify, PK local bigint, email único y arquitectura con passkeys/2FA. El contrato recibido advierte que Fortify habilita emailVerification, pero el User examinado no implementa MustVerifyEmail; tampoco acredita una política uniforme de verificación ni correo real. Una sesión activa de COM no es evidencia suficiente de verificación de email. COM deberá resolver y acreditar ese requisito antes de emitir claims de correo verificado aceptables para CLUB.

**PROPUESTO TÉCNICAMENTE:** OpenID Connect con proveedor bajo autoridad de COM o servicio dedicado de esa autoridad y CLUB como cliente. Authorization Code Flow, PKCE S256, `state` y `nonce` ligados a la operación, HTTPS, redirect URIs registradas, códigos de un uso, tokens limitados y validación de issuer/audience/firma/expiración y nonce. Vincular el `sub` emitido con el sujeto global exige una regla explícita; no se asumirá que todo `sub` equivale automáticamente a `global_subject_id`.

`jakawi.com` y `jakawi.club` son dominios raíz diferentes: no se compartirá una cookie Laravel para obtener SSO. Cada aplicación conserva su sesión; navegación unificada exige redirecciones autenticadas y una política acordada de cierre de sesión/revocación y autenticación adicional. No se compartirá APP_KEY, almacén de sesiones ni hashes de contraseña.

**PENDIENTE DE IMPLEMENTAR:** selección del proveedor, registro del cliente, claims/scopes, claves, logout, revocación y pruebas. No se construirá un servidor OIDC en esta tarea. Referencias para el diseño futuro: [OpenID Connect Core](https://openid.net/specs/openid-connect-core-1_0.html), [OAuth Security BCP](https://www.rfc-editor.org/rfc/rfc9700.html).

## 12. Idempotencia, duplicados y coherencia

| Operación | IMPLEMENTADO HOY | PROPUESTO TÉCNICAMENTE / PENDIENTE DE IMPLEMENTAR |
| --- | --- | --- |
| Registro CLUB | Email normalizado único; restricción PostgreSQL y manejo de concurrencia; respuesta genérica a cuenta existente; conversión solo para alta nueva. | No confundir deduplicación local por email con identidad entre aplicaciones. |
| Activación CLUB | Broker con token hash, expiración de 60 minutos y consumo serializado; verificación y activación locales. | Evidencia exportable y verificación por COM aún ausentes. |
| Proyección CRM | event ID + hash, devolución idempotente y conflicto para cambio de cuerpo; FIRST/LATEST y supresiones protegidos en bridge. | Contrato multiorigen y campos CLUB/globales; outbox CLUB con cifrado, reintentos acotados y resolución de errores. |
| Handoff | Ausente. | Unicidad por emisor + handoff ID, hash del contenido y resultado persistido; mismo cuerpo devuelve resultado coherente, cuerpo diferente produce conflicto sin duplicar cuentas. |
| Eventos COM → CLUB | Ausentes. | Outbox/inbox, deduplicación por emisor + event ID y separación entre ocurrido/recibido; tolerar llegada tardía, reordenación y vínculo pendiente. |
| Comisión futura | Ausente en CLUB. | Además del event ID, unicidad por transacción/hecho económico, afiliado, concepto y versión de regla acordados; reintentos no duplican devengos ni liquidaciones. |

No se promete entrega «exactamente una vez». Se propone entrega con reintentos y efectos idempotentes, con conciliación de pendientes. Cancelaciones/devoluciones se registrarán como hechos correlacionados y ajustes auditables según contrato futuro; no se borrará una comisión histórica para ocultar un duplicado. Los mensajes no tendrán permisos para sustituir un referente permanente ni sobreescribir consentimientos de otra finalidad.

## 13. Compatibilidad con el código actual de CLUB

**Resultado: compatible con la separación de responsabilidades, con precisiones y capacidades pendientes concretas. No está listo para integrar identidad ni abrir registro.** Revisión focalizada; no se modificó lógica.

| Módulo y fuente local | IMPLEMENTADO HOY | Precisión o pendiente concreto |
| --- | --- | --- |
| [User](../../app/app/Models/User.php) y [migración de identidad](../../app/database/migrations/2026_10_28_000001_extend_users_for_launch.php) | PK bigint, UUID estable separado, referente inmutable, MustVerifyEmail. | Corregir el tipo asumido por el contrato; añadir vínculo global futuro sin reemplazar PK/UUID. |
| [Registro](../../app/app/Http/Controllers/RegistrationController.php) y [request](../../app/app/Http/Requests/RegistrationRequest.php) | User pendiente, datos normalizados, privacidad requerida/aceptada, transacción con atribución y conversión. Email repetido: no crea usuario ni otra conversión. | No hay tabla Lead ni `club_lead_id` independiente, global_subject_id ni outbox de captación. Definir modelo/proyección futura; no renombrar cuentas actuales por documentación. |
| [Activación y recuperación](../../app/app/Http/Controllers/AuthController.php) | Activación consume token y establece email_verified_at/activated_at; login exige ambos; cambio de contraseña revoca sesiones previas. | Entrega real no probada en CLUB. La prueba es de correo, no de WhatsApp ni identidad civil; no existe evidencia federada. |
| [Referidos](../../app/app/Services/ReferralAttribution.php) y migración | Primer código válido en sesión; cookie opcional 30 días con permiso; referente permanente al crear usuario; sin autorreferencia. | Referidos locales no son aún un sistema económico ni referencias autenticadas para COM. Resolver conflictos/atribución tardía sin reescribir la relación actual. |
| [Formulario](../../app/resources/js/components/forms/RegistrationForm.tsx) y [privacidad](../../app/resources/js/pages/Privacy.tsx) | En el SHA actual el checkbox empieza **marcado**; texto «Acepto el acuerdo de privacidad». Backend guarda fecha y versión `2026-10-28`; no hay opt-in comercial. Cookie de referido independiente. | El reporte histórico de registro describía otro estado. El valor actual no acredita un clic afirmativo individual ni permiso de marketing/vinculación. Definir evidencias y finalidades futuras sin reclasificar estos registros ni cambiar el control ahora. |
| [Mailer](../../app/config/mail.php), [AccountMail](../../app/app/Services/AccountMail.php), [LaunchSettings](../../app/app/Support/LaunchSettings.php) | `log`; guard requiere aprobación explícita + SMTP remoto + remitente válido; AccountMail retorna antes de crear tokens si no está listo. Envío síncrono sin worker. | SES SMTP 2465 es compatible sin cambio lógico; credenciales, remitente y prueba siguen pendientes. El guard no realiza una prueba de entregabilidad. |
| [Medición](../../app/app/Services/MarketingAnalytics.php) y [eventos públicos](../../app/app/Http/Controllers/MarketingEventController.php) | Totales diarios de visitas/CTA/video; registration_completed solo en alta nueva del servidor. Totales por landing/campaña publicada, sin vínculo personal; recibo de sesión de 15 min para confirmación. | No existe historial individual de lead/campaña/UTM/captación para handoff, inbox de eventos COM ni ledger económico. Los agregados y el recibo temporal no lo sustituyen. |
| [Contenido editorial](../../app/app/Support/LandingContent.php) y [editor](../../app/app/Http/Controllers/LandingAdminController.php) | LEAD_VSL/WEBINAR/WAITLIST; borrador/snapshot publicado; `/` y `/principal`; URLs `/{slug}`; exporta **jakawi-club-landing-v2**, importa v1/v2; `after_registration` y confirmación local por landing. | El contrato recibido menciona solo v1: debe admitir v2 como estado actual. `next_step` admite none/recover/account/home/whatsapp, sin handoff COM ni redirects arbitrarios; cambios futuros necesitan contrato y validación específicos. |

### Validación y límites de la evidencia

Se ejecutó **`python3 scripts/validate-local.py` con éxito**, sin dependencias nuevas: HTTP/Inertia 200, contenedores propios healthy, APP_DEBUG=false, mailer log y aislamiento comprobado. El archivo histórico de evidencia que genera se conservó con sus bytes originales para que esta entrega añada solo documentación.

El verificador histórico `verify-postconversion-live.py` superó igualdad Git/código montado/assets, rutas y bloqueo HTTP 403, pero **la ejecución completa falló** en la comparación de arranque de un contenedor ajeno (`jakawi-worker-1`) con su baseline anterior. La validación local identificó reinicios automáticos previos (RestartCount 16 frente a 0 histórico; arranque 13:56:37 UTC), ya presentes al inspeccionar el entorno. No se intervino ese servicio. No se presenta el verificador completo como aprobado ni se modifica su baseline.

Las comprobaciones focalizadas de Git, archivos montados, HTTPS, 11 rutas y protección de 4 rutas administrativas se ejecutaron también de forma independiente utilizando el bloque existente anterior a esa comparación y pasaron. El estado efectivo de CLUB confirmó las tres opciones cerradas, ausencia de SMTP y **cero conversiones de registro**. Los digests agregados de usuarios, landings y migraciones coincidieron con el baseline privado de la entrega LIVE anterior, sin exponer filas ni datos personales. La comparación final contra la captura inicial de esta intervención confirmó **87 contenedores sin cambios de identidad, arranque, reinicios, estado, salud ni redes**, y **24 archivos Traefik con hashes iguales**.

No se ejecutaron migraciones, builds, instalaciones, suites extensas, reinicios, cambios DNS/Traefik ni despliegues. No se ejecutó `crm:dispatch`, ningún POST al bridge ni correos de prueba. No se inspeccionó el repositorio COM: los puntos recibidos sobre Fortify/outbox permanecen atribuidos a su arquitecto, sin necesidad de ampliar la lectura para resolver el correo actual.

## 14. Responsables y conformidad por fase

| Fase/decisión | Responsable | Condición de cierre futura |
| --- | --- | --- |
| Conformidad del presente contrato | Lead Architect COM + Lead Architect CLUB; propietario para decisiones de negocio | Acordar mapeo `club_user_id=users.uuid`, lead pendiente, autoridad global, verificación y flujo inverso. |
| Correo transaccional CLUB | Operador AWS/correo + propietario + Lead Architect CLUB | Credenciales separadas, remitente/destinatario aprobados, recepción real y ensayo de acceso autorizados. CRM mantiene su configuración actual. |
| Identidad y recuperación operativa | Lead Architect COM | Política verificable de email/Fortify y autoridad del sujeto global; resolver la advertencia MustVerifyEmail en su propio proyecto. |
| Captación y cliente de identidad | Lead Architect CLUB | Modelo de procedencia/consentimientos y evidencia; handoff y vínculo local sin apropiación de cuentas ni atribución alterada. |
| Handoff y provisionamiento | COM receptor/autoridad; CLUB emisor/cliente | Protocolo autenticado, contrato de conflictos e idempotencia; pruebas conjuntas en entorno autorizado. |
| Eventos de captación/transacción | COM produce y conserva operación; CLUB recibe y atribuye | Esquema, autenticidad de procedencia, correlación y reconciliación en ambos sentidos. |
| Proyección de contactos y supresiones | Responsable CRM + arquitectos COM/CLUB | Extensión compatible del bridge, productores/credenciales independientes, preferencias y nuevas entregas verificadas. |
| Economía de afiliados | Lead Architect CLUB + propietario; COM aporta hechos y transición de su motor existente | Reglas, exclusión de doble cálculo, corte y reconciliación antes de migrar cualquier operación. |
| SSO | Autoridad de identidad COM/servicio dedicado + CLUB cliente | Proveedor OIDC y contrato de seguridad/sesiones validados. |

## 15. Roadmap y siguiente paso operativo

Todas las fases siguientes están **PENDIENTES DE IMPLEMENTAR** y requieren su alcance de ejecución posterior. No son parte del commit documental.

1. **Conformidad técnica conjunta:** devolver este documento al Lead Architect de COM para acordar las precisiones reales y el flujo COM → CLUB. No se envía automáticamente a terceros desde esta tarea.
2. **Correo antes de registro público:** aprobar remitente/destinatario, emitir credenciales SMTP CLUB en SES us-east-1 y preparar la prueba por 2465 TLS descrita en la sección 9. Es el siguiente paso operativo recomendado; no depende de construir SSO, modificar CRM ni migrar afiliados.
3. **Apertura de captación como decisión separada:** comprobar activación/verificación/recuperación reales con cuenta expresamente autorizada y cerrar los pendientes existentes de operación/privacidad antes de solicitar apertura. Hasta entonces conservar las banderas cerradas.
4. **Modelo mínimo de integración:** definir sujeto global y vínculos; procedencia comercial individual con UTM/landing/campaña, evidencias de verificación y consentimientos por finalidad; outbox/inbox. Acordar cómo conservar eventos COM sin cuenta CLUB vinculada.
5. **Handoff CLUB → COM y captación COM → CLUB:** implementar contratos versionados y probar reintentos, cuenta existente, autenticación del titular, conflicto, llegada directa sin referente y ausencia de pérdida/duplicación de atribución. Ninguna fase activa membresías por ser lead.
6. **CRM:** extender el bridge de forma compatible y añadir la proyección CLUB con autenticación propia, supresiones y una prueba autorizada posterior a la migración. Mantener el mailer transaccional separado de campañas.
7. **SSO OIDC:** selección/configuración del proveedor, cliente CLUB, políticas de verificación, sesiones y revocación. Puede diseñarse en paralelo al handoff, sin convertirlo en requisito para verificar primero el correo local.
8. **Afiliación económica y transición COM:** proyecto posterior de reglas, eventos transaccionales, ledger, comisiones y liquidaciones; migración controlada y exclusión del cálculo duplicado. No trasladar catálogo ni autoridad de pagos de COM.

La investigación sustenta una ruta concreta: **SES existente → credenciales SMTP independientes de CLUB → `email-smtp.us-east-1.amazonaws.com:2465` con TLS → prueba Laravel autorizada → validación del flujo de acceso → eventual apertura explícita**. La conectividad TLS y la configuración del proveedor no sustituyen la prueba de envío/recepción ni autorizan abrir el registro.
