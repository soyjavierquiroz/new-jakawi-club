# Bootstrap del primer administrador — jakawi.club

Baseline: `351cfa4f38c01eebd7dde251da2d25fa72f08d9c`; rama `main` limpia y sincronizada antes de trabajar. Se inspeccionaron `landings:admin`, el modelo `User`, el login y la activación existentes. `landings:admin` requiere una cuenta existente, activada y verificada. Con `MAIL_MAILER=log`, el servicio de correo de acceso no entrega ni registra enlaces; el primer acceso necesita una operación CLI explícita.

## Operación para el propietario

Desde una terminal privada del servidor, con TTY:

```bash
cd /opt/sites/jakawi.club
docker compose exec --user www-data app php artisan landings:bootstrap-admin
```

No añadir `-T` ni `--no-interaction`. El comando pide:

1. Nombre real.
2. Correo electrónico real.
3. Confirmación explícita de que el correo pertenece al propietario y controla su acceso (no por defecto).
4. Contraseña mediante entrada oculta y su repetición, sin fallback visible.
5. Confirmación final de creación del primer administrador (no por defecto).

La contraseña debe tener al menos 12 caracteres, letras y números, y no superar 72 bytes. Se conservan los espacios; solo se descarta Enter de la entrada de terminal. No se recibe por argumentos, variables o tuberías, ni se imprime o registra.

Esta operación confirma manualmente la titularidad; no simula una verificación mediante un correo enviado. Solo tras las confirmaciones se crea la cuenta activada y verificada. Se reutilizan la generación de UUID/código de referido y el cast de contraseña del modelo `User`, `markEmailAsVerified()` y el comando `landings:admin` para conceder el permiso. La concesión se ejecuta dentro de la transacción del alta; un fallo la revierte completa. No se generan tokens ni se envían correos.

Si ya existe un administrador, el comando rechaza otra alta. Si el correo ya pertenece a cualquier cuenta, rechaza el alta sin cambiar contraseña, verificación ni permisos. Revalida ambas condiciones bajo bloqueo breve de la tabla de usuarios; no mantiene bloqueos durante las preguntas. Las excepciones inesperadas se sustituyen por un mensaje genérico para impedir que consultas, trazas o valores sensibles lleguen a consola o logs.

Tras ejecutar el alta, el propietario inicia sesión en `https://jakawi.club/login` con sus datos y abre `https://jakawi.club/admin/landings`. Se conservan las sesiones, middleware y pantallas de autenticación existentes. No se añade gestión web de usuarios.

## Pruebas focalizadas

`bash scripts/test-admin-bootstrap.sh` ejecuta en secuencia dos contenedores temporales limitados. Usan solo `jakawi_club`, con esquemas PostgreSQL aleatorios que se eliminan al terminar; storage y cachés de prueba viven en tmpfs. Sin dependencias nuevas ni instalaciones.

- **45 comprobaciones** del comando y HTTP real: rechazo de modo no interactivo/sin TTY, datos inválidos, titularidad no confirmada, contraseñas inválidas o distintas, cancelación final, reversión ante error de creación/concesión, alta confirmada, UUID/referral, hash y espacios, repetición segura, otras cuentas intactas y ausencia de tokens.
- Autenticación existente: el administrador accede al creador y preview; el usuario normal recibe 403 y el invitado 401 en solicitudes JSON. El registro público sigue respondiendo 403.
- Logger single-file activo con marcador de control y `MAIL_MAILER=log`: ninguna contraseña aparece en logs o salida, incluso si una excepción de prueba contiene el valor sensible. Las contraseñas de prueba se generan en memoria, sin fixtures de credenciales en archivos.
- **TTY real**: el controlador Python conduce preguntas reales de Symfony en otra ejecución aislada, comprueba la creación y confirma que la contraseña no aparece en la transcripción. La transcripción permanece en memoria y se descarta; solo se guarda el resultado de la prueba.

Evidencia: `tests.txt`. La ayuda Artisan confirma que el comando está disponible (`command-help.txt`). Validaciones local y HTTPS satisfactorias (`local-validation.txt`, `public-validation.txt`). Sin cambios de frontend, assets o esquema: no corresponden builds, instalaciones ni migraciones.

## Despliegue y estado

Desplegado únicamente en jakawi.club mediante el código montado y reinicio exclusivo de `jakawi-club-app` para renovar OPcache. Sin cambios de secretos, SMTP, Compose, Traefik, DNS, jakawi.com o servicios compartidos. Los servicios propios están saludables y ambos `/up` responden 200.

**No se ejecutó el alta en LIVE.** Se mantienen cero usuarios y cero administradores. El registro público continúa cerrado, `MAIL_MAILER=log` y `APP_DEBUG=false`. La ejecución con información real queda exclusivamente para el propietario.

El commit y SHA LIVE se sellan después del push mediante la verificación de Git limpio/sincronizado, archivos montados, assets HTTPS, salud y registro cerrado. `LIVE.json` se excluye de Git para evitar autorreferencia.

Rollback: revertir este commit y reiniciar solo `app`. No hay migraciones ni usuarios LIVE que deshacer. Una futura cuenta creada por el propietario utiliza el modelo/autenticación existentes y sobrevive a retirar este comando; no borrar cuentas ni datos como parte del rollback.
