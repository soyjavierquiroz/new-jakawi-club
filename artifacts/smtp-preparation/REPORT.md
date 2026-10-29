# Preparación SMTP transaccional de JAKAWI.CLUB

Fecha: 29 de octubre de 2026, Bolivia (UTC−04). Fase A completada; fases B y C pendientes de sus condiciones. Evidencia sin secretos: [validation.json](validation.json).

## A. Compatibilidad Laravel SMTP

Verificado sobre PHP 8.4.27, Laravel 13.39.0 y Symfony Mailer 8.1.7 instalados. `app/config/mail.php` ya expone todas las variables necesarias; no requiere cambios ni dependencias nuevas:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=email-smtp.us-east-1.amazonaws.com
MAIL_PORT=2465
MAIL_FROM_NAME="JAKAWI"
MAIL_EHLO_DOMAIN=jakawi.club
JAKAWI_TRANSACTIONAL_MAIL_READY=false
JAKAWI_REGISTRATION_OPEN=false
```

Es una propuesta para la fase B, todavía sin aplicar. Faltan `MAIL_FROM_ADDRESS` aprobado y `MAIL_USERNAME` / `MAIL_PASSWORD` exclusivos; sus secretos no se incluyen en esta plantilla.

Se construyó el transporte nativo de Laravel **solo en memoria**, sin credenciales y sin conectar ni enviar: host y puerto correctos, TLS implícito activo, EHLO `jakawi.club` y verificación del certificado sin deshabilitar. Es necesario indicar `smtps`: Laravel solo lo infiere automáticamente para el puerto 465. SES admite TLS implícito en 2465. [Documentación AWS](https://docs.aws.amazon.com/ses/latest/dg/smtp-connect.html).

`MAIL_URL` está ausente en el archivo privado y no tiene valor en el entorno del contenedor ni en la configuración cacheada. No sobrescribe la propuesta actualmente. Laravel procesa esa URL con prioridad sobre campos individuales cuando existe: mantenerla **ausente**, también al aplicar la fase B.

`AccountMail` sale sin enviar ni generar tokens cuando `LaunchSettings::mailReady()` es falso. Su manejo de errores ya evita registrar excepciones SMTP. `LaunchSettings` exige aprobación operativa y transporte SMTP real; el registro exige además su propia bandera. No se cambian estos controles ni el flujo de activación.

## B. Credenciales exclusivas y trabajo del operador AWS

No hay usuario ni contraseña SMTP configurados en CLUB. No se acredita exclusividad de ninguna credencial externa. Falta autorización explícita del propietario para crear credenciales; no se invocó AWS, no se verificaron permisos IAM ni se buscaron claves de CRM/WordPress u otros proyectos.

El operador AWS autorizado debe:

1. Confirmar autorización del propietario, cuenta AWS e identidad `jakawi.com` en `us-east-1`. Revisar en lectura si esa región está en sandbox; si lo está, el destinatario real autorizado debe estar verificado. No cambiar identidades, DNS ni acceso a producción como parte de esta preparación. [Sandbox regional](https://docs.aws.amazon.com/ses/latest/dg/request-production-access.html).
2. Crear un usuario IAM exclusivo, propuesto `jakawi-club-smtp-us-east-1`, sin acceso a consola, con política/grupo propios y únicamente `ses:SendRawEmail` sobre la identidad y remitente aprobados. No modificar grupos/políticas existentes del CRM ni dejar permisos amplios adicionales en el nuevo usuario. [Control de acceso SES](https://docs.aws.amazon.com/ses/latest/dg/control-user-access.html).
3. Generar credenciales SMTP nuevas para `us-east-1` y entregarlas por un canal privado al operador de CLUB. La contraseña SMTP difiere de la secret access key. No derivarla de claves de CRM/WordPress ni de credenciales temporales. Para la creación por consola, AWS documenta permisos del operador `iam:CreateUser`, `iam:CreateGroup`, `iam:PutGroupPolicy`, `iam:AddUserToGroup` e `iam:CreateAccessKey`; CLUB no recibe permisos administrativos. [Credenciales SMTP SES](https://docs.aws.amazon.com/ses/latest/dg/smtp-credentials.html).

Plantilla IAM **sin aplicar**, restringida inicialmente al único destinatario de prueba. El operador debe sustituir los marcadores por la cuenta y correo autorizados y confirmar el remitente; `info@jakawi.com` sigue siendo candidato. Si el asistente de AWS asigna un grupo amplio, retirar **solo al usuario nuevo de CLUB** de ese grupo, conservando únicamente su política restringida; no editar el grupo compartido.

```json
{
  "Version": "2012-10-17",
  "Statement": [{
    "Sid": "ClubTransactionalSmtpValidationOnly",
    "Effect": "Allow",
    "Action": "ses:SendRawEmail",
    "Resource": "arn:aws:ses:us-east-1:ACCOUNT_ID:identity/jakawi.com",
    "Condition": {
      "StringEquals": {"ses:FromAddress": "info@jakawi.com"},
      "ForAllValues:StringEquals": {"ses:Recipients": ["AUTHORIZED_TEST_RECIPIENT"]}
    }
  }]
}
```

El ARN limita la identidad a `us-east-1`. La política se asocia al usuario/grupo nuevo de CLUB, no a la política de la identidad compartida. Ampliar destinatarios exige autorización posterior; esta plantilla no habilita envíos generales. Las credenciales separan permisos y revocación, pero no independizan las cuotas de la cuenta SES ni la reputación del dominio compartido.

## C. Remitente propuesto

`JAKAWI <info@jakawi.com>`, **pendiente de aprobación del propietario**. La verificación del dominio se toma de los datos confirmados; no equivale a aprobación del remitente ni a comprobar SPF/DKIM/DMARC en un mensaje recibido. No se presupone ningún destinatario.

## D. Preparación privada y aplicación posterior

Realizado: inspección limitada y sin valores secretos de `/srv/sites/jakawi.club/secrets/app.env` y de la configuración efectiva. Archivo `root:root`, modo `0600`; mailer efectivo `log`, `APP_DEBUG=false`. No se modificaron secretos, cachés ni contenedores.

Procedimiento mínimo **solo cuando existan credenciales exclusivas y aprobación de remitente y destinatario**:

1. En terminal privada del operador, cargar usuario y contraseña mediante entrada oculta, por ejemplo `getpass`, sin argumentos, historial, impresión ni transcripciones. Conservar la copia previa exclusivamente en `/srv/sites/jakawi.club/secrets/`, `root:root`, `0600`; mantenerla privada para rollback.
2. Actualizar únicamente las claves SMTP de la propuesta en `secrets/app.env`, añadir `MAIL_FROM_ADDRESS` aprobado y las dos credenciales reales. Usar reemplazo atómico con temporal en ese mismo directorio privado, conservar propietario y modo `0600` y el resto del entorno. Eliminar `MAIL_URL` y evitar claves duplicadas. Fijar explícitamente ambas banderas a `false` y conservar `APP_DEBUG=false`. No copiar un archivo completo desde otro proyecto ni guardar claves AWS API en CLUB.
3. Aplicar el procedimiento existente del [README](../../README.md), desde `/opt/sites/jakawi.club`, en secuencia:

```bash
docker compose config --quiet
docker compose up -d --no-deps --force-recreate app
docker compose exec -T --user www-data app php artisan config:cache
docker compose restart app
```

4. Comprobar en lectura y con salida limitada a campos públicos/booleanos el transporte efectivo, TLS, puerto, EHLO, ausencia de `MAIL_URL`, presencia de credenciales y ambas banderas falsas. No ejecutar `config:show mail`, `printenv`, `docker inspect` sin filtrar ni `docker compose config` sin `--quiet`. Verificar salud de CLUB y registro cerrado. El validador local actual exige `MAIL_MAILER=log`: después de una futura instalación SMTP necesitará una adaptación focalizada y autorizada de esa comprobación, manteniendo las demás restricciones; su resultado actual no acredita SMTP instalado.

Rollback de esa futura fase: restaurar únicamente la copia privada previa y repetir esos comandos sobre `app`; confirmar `log` y ambas banderas falsas. No ejecutar `start-local.sh` (incluye migraciones), instalaciones ni builds para este cambio.

## E. Prueba real controlada

**No autorizada ni ejecutada. Cero mensajes enviados.** El puerto 2465 se toma del dato funcional ya confirmado por el propietario; no se repitió la sonda de red.

Al autorizar el destinatario exacto, hacer una sola llamada síncrona a `Mail::mailer('smtp')->raw(...)`, con texto transaccional de prueba sin enlaces de acceso, un único destinatario y sin CC/BCC, colas, failover o reintentos automáticos. Usar directamente el mailer permite conservar `JAKAWI_TRANSACTIONAL_MAIL_READY=false`; no invocar `AccountMail`, notificaciones ni el broker de contraseñas. No crear usuarios ni tokens ni alterar banderas para probar.

Registrar por separado, sin transcripción SMTP/AUTH ni excepciones completas:

| Estado | Evidencia exigida en la prueba | Estado de esta entrega |
| --- | --- | --- |
| SMTP conectado | Conexión TLS con certificado válido y saludo SMTP | No comprobado en esta entrega; puerto informado funcional |
| SMTP autenticado | Respuesta `235` | No intentado |
| SES aceptó mensaje | Respuesta final `250` después de DATA e identificador SES | No intentado |
| Buzón recibió mensaje | Confirmación expresa del titular, fecha y ubicación (entrada/spam) | No confirmado |
| Autenticación del mensaje recibido | `Authentication-Results`: SPF, DKIM y DMARC y sus dominios/alineación | Sin encabezados disponibles |

Los códigos se deben extraer en memoria y registrar solo los campos permitidos; el debug completo puede contener credenciales. Un rechazo se registra con su código saneado, sin reenvío automático. Un timeout tras DATA deja la aceptación indeterminada: no repetir sin nueva decisión del propietario. Después del único envío, solicitar confirmación de recepción y encabezados al destinatario autorizado. `250` no demuestra llegada al buzón. [Respuestas SMTP SES](https://docs.aws.amazon.com/ses/latest/dg/troubleshoot-smtp.html).

## F. Bloqueadores concretos

- Autorización del propietario y operador con permisos IAM apropiados para crear el usuario exclusivo; cuenta AWS/ARN por completar privadamente.
- Credenciales SMTP exclusivas de CLUB para `us-east-1` aún no disponibles en CLUB.
- Aprobación de `info@jakawi.com` (o remitente alternativo) y destinatario real exacto; comprobar verificación del destinatario si hay sandbox.
- Instalación privada, envío único y confirmación real de recepción/encabezados pendientes de esas condiciones.

Estos bloqueadores impiden avanzar a B/C, pero no impiden confirmar la preparación A.

## G. Registro público

`JAKAWI_TRANSACTIONAL_MAIL_READY=false` y `JAKAWI_REGISTRATION_OPEN=false` efectivos en configuración cacheada y en `LaunchSettings`. Las variables todavía están ausentes del archivo privado: se aplican los valores predeterminados `false` de `config/jakawi.php`. No se escribió el entorno para adelantarse a la fase B. La prueba en memoria con SMTP propuesto conservó ambos controles cerrados y `AccountMail` deshabilitado.

## H. Cambios, validación y Git

Inicio limpio en `main`, commit `ad117b76181132c9d20b0678c9f1e91f04a2f587`; `git pull --ff-only origin main` confirmó sincronización sin cambios. No se repitió la investigación del contrato ni se modificó su documento.

Entrega limitada a este informe, evidencia saneada y enlace desde README. Sin cambios de aplicación, dependencias, entorno, usuarios, tokens, infraestructura, COM o CRM; sin recreaciones, reinicios ni despliegue. No se hace push automáticamente.

Validación: construcción del transporte SMTP en memoria y comprobaciones de cierre; `python3 scripts/validate-local.py` con evidencia resumida en `validation.json`. Se conserva intacta la evidencia histórica del validador. No se ejecutan suites extensas ni se presenta esta validación como envío o recepción real.
