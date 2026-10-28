# MVP de captación y referidos

Implementado y desplegado exclusivamente en `https://jakawi.club` el 28 de octubre de 2026 (Bolivia). Stack y dependencias conservados.

- Landing responsive con marca y lema aprobados, formulario cerrado, video/evento/WhatsApp configurables y estados vacíos, preguntas frecuentes y privacidad.
- Usuarios con UUID y código público permanente; nombre, email y teléfono normalizados; ciudad opcional; consentimiento versionado; verificación nativa de email y activación.
- Primer referente válido en sesión, cookie cifrada opcional de 30 días con consentimiento y retirada, atribución transaccional e inmutable. Índices y restricciones PostgreSQL impiden duplicados y autorreferidos.
- Registro sin contraseña; recibo genérico de 15 minutos idéntico para cuentas nuevas/duplicadas. El enlace personal se muestra después de verificar correo y establecer contraseña para evitar enumeración de cuentas.
- Tokens nativos de Laravel: hash en PostgreSQL, 60 minutos, un solo uso y consumo serializado. Login/logout, recuperación con revocación de sesiones, dashboard verificado y perfil básico.
- No se envían contraseñas ni se registran enlaces de acceso mediante mail log. Notificaciones síncronas sin servicio adicional. Nginx propio omite tokens de acceso de su log. Caché de páginas con datos personales desactivada.
- Sin compras, comisiones, liquidaciones, CMS, paquetes adicionales ni seeders de usuarios de prueba.

## Rutas

| Método | Ruta | Uso |
| --- | --- | --- |
| GET | `/` | Landing |
| GET | `/r/{code}` | Captura de primera invitación |
| POST | `/preferencias` | Permiso/retirada de cookie opcional |
| GET | `/privacidad` | Aviso y preferencias |
| POST | `/registro` | Registro, bloqueado actualmente con HTTP 403 y CSRF válido |
| GET | `/bienvenida` | Recibo temporal o bienvenida verificada |
| GET/POST | `/login` | Acceso |
| POST | `/logout` | Cierre e invalidación de sesión |
| GET/POST | `/recuperar-password` | Solicitud genérica de enlace de acceso |
| GET | `/activar/{token}` | Formulario de activación |
| POST | `/activar` | Verificación y contraseña inicial |
| GET | `/restablecer-password/{token}` | Formulario de recuperación |
| POST | `/restablecer-password` | Nueva contraseña |
| GET | `/verificar-email` | Instrucciones de verificación |
| GET | `/dashboard` | Área privada verificada |
| PATCH | `/perfil` | Nombre/teléfono/ciudad |

## Migración y despliegue

`2026_10_28_000001_extend_users_for_launch`, batch 2. Adición compatible con las tres migraciones originales; backfill de identidades sin eliminar datos. Contraseña nullable para cuentas pendientes. Índice único sobre email normalizado, códigos y UUID únicos, FK del referente y restricciones de identidad/atribución. Base y rol exclusivos `jakawi_club`. Usuarios LIVE antes/después: **0 / 0**. Esquemas temporales de pruebas restantes: **0**.

TypeScript y Vite se ejecutaron secuencialmente en contenedores limitados; no hubo instalaciones. Assets nuevos copiados antes de sustituir el manifest y assets anteriores conservados. Cachés de configuración, rutas y vistas renovadas. Reiniciado únicamente `jakawi-club-app`; Nginx propio recargado sin recreación. PostgreSQL y web conservan sus contenedores; no se modificó Traefik, DNS, redes, credenciales ni proyectos ajenos.

## Evidencia

- [46 pruebas focalizadas](tests.txt), HTTP kernel real, PostgreSQL aislado, sesiones en BD y notificaciones fake: registro, validación/consentimiento, duplicados, referente correcto/persistente, autorreferidos, códigos únicos, cookie opt-in/restauración/retirada, activación, consumo/expiración de tokens, login/logout, recuperación, revocación de sesiones, perfil, área privada, CSRF, throttling y registro cerrado.
- TypeScript sin errores; build Vite satisfactorio con páginas React y componentes compartidos.
- [Validación local](local-validation.txt) y [validación pública](public-validation.txt): HTTP, Inertia, todos los chunks/CSS, TLS de dominio principal y www, redirecciones, configuración segura, registro cerrado mediante petición con CSRF válido, dashboard inaccesible para visitantes, tres contenedores saludables, PostgreSQL sin puertos publicados y `jakawi.com/up` HTTP 200.
- El baseline histórico tenía un reinicio del worker ajeno anterior a la preparación (05:32 UTC frente a preparación 05:35 UTC). El baseline de esta tarea conserva ese estado preexistente y verifica identidades, redes, salud y archivos protegidos; el worker no fue operado. Esta diferencia no es un reinicio causado por el despliegue.
- SHA efectivo y hashes del código/assets se sellan después del commit en `artifacts/mvp/LIVE.json` (evidencia local no versionada para evitar autorreferencia).

## Apertura pendiente

Registro **CERRADO**. `MAIL_MAILER=log`, aprobación de correo y bandera de apertura desactivadas. Configurar y probar SMTP/remitente reales, aprobar responsable/contacto/plazos de privacidad y contenido de lanzamiento. Definir backup externo. Video, evento/Zoom y WhatsApp vacíos hasta aprobación. Detalles en [operación](OPERACION.md) y [rollback](ROLLBACK.md).
