# CLUB: confirmación desde arriba; CRM: diagnóstico de lectura

## Corrección

La opción `preserveScroll: true` del POST de registro heredaba la posición del formulario al mostrar `Registered`. La reproducción con el formulario real, una redirección Inertia 303 y recibos generados por el kernel Laravel en PostgreSQL aislado dio entre 360 y 446 px de scroll en la confirmación, tanto a 390 como a 1440 px.

El formulario ahora conserva scroll solo ante errores (`preserveScroll: 'errors'`). Cuando la respuesta exitosa es `Registered`, establece posición `(0,0)` con comportamiento instantáneo: el CSS de la landing usa desplazamiento suave y no debe animar la llegada. La navegación normal no cambia. Inertia ya configura `history.scrollRestoration = 'manual'`; no se modifica ese ajuste global. No hay autofocus ni `scrollIntoView` en la confirmación; el foco de errores y la navegación interna hacia el formulario se conservan.

No cambian el servidor de registro, protección por sesión/origen y expiración del recibo, atribución, video, WhatsApp ni correo. Los fixtures son respuestas de recibos válidos emitidos por el flujo existente, no altas LIVE. Las mutaciones del navegador se interceptan; no se usa SMTP ni se abre el registro.

## Verificación y operación

- `scripts/test-postconversion.sh`: 134 comprobaciones, esquema PostgreSQL temporal eliminado al terminar, notificaciones simuladas y cero usuarios LIVE.
- TypeScript y Vite secuenciales, en los contenedores limitados de `scripts/build-frontend.sh`.
- `scripts/check-confirmation-scroll.sh --preview` y después sin opciones: móvil 390×844 y desktop 1440×1000; formulario después de 5.000 px de contenido; `/principal/gracias`, `/principal/confirmado` y `/bienvenida`. Se comprueba scroll cero al aparecer y después de estabilizarse, con movimiento normal del navegador. También foco/scroll ante errores, atribución en ambos envíos, navegación normal a privacidad y presencia de video, WhatsApp y correo. La API de video se intercepta: estas pruebas verifican scroll y contenido, no reproducción del proveedor.
- `scripts/validate-local.py` con `JAKAWI_BASELINE_PATH=/srv/sites/jakawi.club/rollback-confirmation-scroll/baseline.json` y evidencia en este directorio. El baseline predeterminado del validador corresponde a un despliegue antiguo; se usa el estado real previo a esta corrección.
- Publicación exclusiva de assets CLUB: copiar chunks conservando los anteriores y sustituir el manifest de forma atómica. Sin migraciones, cambios de configuración, recreación o reinicios de servicios.
- `scripts/verify-confirmation-scroll-live.py`: sella el SHA de `main`/origin y LIVE, archivos montados, hashes de assets HTTPS, salud local/pública, recibos inaccesibles sin sesión, tablas protegidas, configuración y contenedores sin cambios. El sello `LIVE.json` es local e ignorado para evitar autorreferencia.

Evidencia: `reproduction.json`, `browser-preview.json`, `browser-live.json`, `receipt-tests.txt`, `validation.json` y `LIVE.json`. Capturas locales en este mismo directorio.

Rollback exclusivo de frontend: restaurar atómicamente el manifest desde `/srv/sites/jakawi.club/rollback-confirmation-scroll/build/manifest.json` y restaurar únicamente el componente de formulario desde Git o la copia privada `RegistrationForm.tsx`. Conservar chunks nuevos y antiguos para sesiones abiertas. No tocar bases de datos, secretos ni infraestructura.

## CRM: solo lectura

- TLS 1.3 verificado con el almacén de confianza del sistema: cadena y nombre `crm.jakawi.com` correctos. Emisor Let's Encrypt YE1. Vigencia: 26/10/2026 14:56:57 UTC a 24/01/2027 14:56:56 UTC.
- `http://crm.jakawi.com/wp-admin/` responde 301 hacia la misma ruta HTTPS. En HTTPS, `/wp-admin/` y `/wp-login.php` responden 401 con `WWW-Authenticate: Basic realm="JAKAWI CRM privado"`.
- El archivo Nginx realmente montado configura explícitamente `auth_basic` en ambas rutas. Es una protección adicional intencional. No se leyó el archivo de contraseñas ni se intentó autenticar.
- `nginx -t` satisfactorio, archivo Basic existente y legible por el usuario worker de Nginx (sin leer contenido), contenedores web/PHP activos, URLs WordPress HTTPS y proxy HTTPS reconocido con la IP actual de Traefik. No se detectó un error en estas comprobaciones que impida el acceso; no se prueba la sesión administrativa actual.
- La verificación existente del 29/10/2026 22:26:22 UTC acredita HTTP 200 para el formulario WordPress después de Basic, el login WordPress y la administración FluentCRM. No se repitió esa autenticación: el acceso actual posterior a Basic queda pendiente de comprobación por el propietario.
- El aviso «No es seguro» no se reprodujo; no está explicado por un certificado inválido en el endpoint HTTPS actual. El 401 es la solicitud de credenciales de Nginx, no un fallo TLS. Revisar la URL exacta y el detalle del aviso en Chrome si persiste; no ignorar un error de certificado.
- El propietario debe abrir `https://crm.jakawi.com/wp-admin/`, usar su acceso Basic asignado y después su cuenta WordPress con permisos administrativos para CRM. Son dos controles independientes. Su administrador puede confirmar ambos permisos por un canal privado; no enviar credenciales al chat.

No se modificó CRM, WordPress, Nginx, Traefik ni certificados. Detalles de comprobación en `crm-readonly.json`.
