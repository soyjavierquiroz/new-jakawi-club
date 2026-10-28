# Operación del MVP JAKAWI

Configuración privada en `/srv/sites/jakawi.club/secrets/app.env`; no guardar valores privados en Git. `config/jakawi.php` lee:

| Variable | Uso |
| --- | --- |
| `JAKAWI_REGISTRATION_OPEN` | Apertura explícita; por defecto false |
| `JAKAWI_TRANSACTIONAL_MAIL_READY` | Aprobación operativa tras probar correo real; por defecto false |
| `JAKAWI_VIDEO_URL` | Enlace HTTPS al video; no carga terceros automáticamente |
| `JAKAWI_EVENT_TITLE` | Título aprobado |
| `JAKAWI_EVENT_STARTS_AT` | ISO 8601 con offset y segundos; sin fecha aprobada dejar vacío |
| `JAKAWI_EVENT_ZOOM_URL` | HTTPS, visible solo tras verificar correo |
| `JAKAWI_WHATSAPP_URL` | Enlace HTTPS aprobado |

Para aplicar cambios de variables inyectadas por Compose, recrear **solo app** con `docker compose up -d --no-deps --force-recreate app`, regenerar `config:cache` como www-data y reiniciar solo app para renovar OPcache. No es necesario cambiar ningún router ni reiniciar otros servicios. No abrir registros sin una petición explícita posterior.

La apertura requiere ambas banderas y un transporte SMTP real con remitente válido. `log`, `array`, `failover`, loopback o ausencia de aprobación mantienen el registro cerrado. No se envían notificaciones de acceso cuando el correo no está habilitado, para evitar tokens en logs.

Pruebas focalizadas: `bash scripts/test-mvp.sh`. Usa la imagen PHP existente con límites, un esquema aleatorio PostgreSQL dentro de jakawi_club que se elimina al terminar, sesiones de base de datos aisladas y notificaciones fake. No instala dependencias de desarrollo ni crea usuarios LIVE. Si el proceso se interrumpe por fuerza, revisar privadamente y eliminar únicamente el esquema con prefijo `mvp_test_` de esa ejecución.

Frontend: `bash scripts/build-frontend.sh` verifica TypeScript y luego construye `public/build-next` con límites. En despliegue copiar assets hacia `public/build/assets` y reemplazar manifest al final; conservar assets anteriores para pestañas abiertas. Tras migrar y renovar cachés, reiniciar exclusivamente app y recargar Nginx propio si cambió su configuración.

Registro y atribución se guardan en una transacción. La entrega de correo es síncrona tras confirmar la transacción; sin worker adicional. Si falla SMTP, la cuenta queda pendiente y puede solicitar otro enlace. El fallo deja un mensaje operativo fijo sin email, token ni excepción SMTP. La bienvenida dura 15 minutos. Antes de verificar el correo solo muestra un recibo genérico; tras activar muestra el enlace personal, evitando revelar cuentas existentes o sus códigos. Tokens nativos del broker: hash en BD, 60 minutos, un solo uso; la recuperación revoca sesiones anteriores.

Antes de abrir: configurar y probar entrega real de activación y recuperación (incluido remitente/SPF/DKIM/DMARC), aprobar responsable/contacto/plazos del aviso de privacidad y contenido de lanzamiento. Configurar únicamente enlaces y fechas aprobados. Definir backup externo de PostgreSQL/storage. No habilitar comisiones, compras ni liquidaciones.

Referencias de integración: [broker de contraseñas de Laravel 13](https://laravel.com/docs/13.x/passwords) y [verificación nativa de email](https://laravel.com/docs/13.x/verification).
