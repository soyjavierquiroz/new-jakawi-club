# Fase 04 — cliente SSO JAKAWI.CLUB

Login: **https://jakawi.club/login**, opción principal **INGRESAR CON JAKAWI**. Entrada directa: `https://jakawi.club/auth/jakawi`. El login local de CLUB permanece disponible.

Se consume el [contrato OIDC desplegado de COM](../../docs/integrations/CLUB_COM_OIDC_V1.md), SHA `61af05e4a0b153cfa9970db435bb467bc6971d4c`: issuer `https://jakawi.com`, authorization `/oauth/authorize`, token `/oauth/token`, JWKS `/oauth/jwks`, callback exacta `https://jakawi.club/auth/jakawi/callback`. Cliente confidencial `8898bf42-9f49-4cc8-8b79-6496d93d2536`. Se usa jumbojett/openid-connect-php 1.0.2 y phpseclib 3.0.57, fijados en Composer. Los paquetes existentes conservan sus versiones.

Authorization Code con PKCE S256 y HTTP Basic del servidor. State y nonce independientes de 256 bits; verifier de 128 caracteres. La transacción, cifrada dentro de la sesión Laravel privada, vence en diez minutos. Se bloquean por sesión las rutas OIDC y se persiste el consumo antes del canje. State ausente, incorrecto, expirado o consumido no produce solicitudes al token endpoint. CLUB no conserva los tokens en sesiones, base de datos, navegador o logs.

La biblioteca verifica la firma; el adaptador restringe RS256, kid y JWKS fijos y rechaza claves embebidas o destinos indicados por el token. Exige issuer exacto, audiencia y authorized party, sub UUID, nonce, iat/exp y nbf cuando existe. Solo se permite transporte HTTPS hacia los endpoints fijos, sin seguir redirecciones ni reenviar cookies. Errores genéricos no exponen respuestas del proveedor. Callback sin caché y con `Referrer-Policy: no-referrer`; el log Nginx existente ya omite queries.

CLUB localiza exclusivamente `users.global_subject_id = sub`, comprueba activación/verificación local y consulta el vínculo autorizado Fase 03 con su cliente autenticado. Revocación, conflicto o indisponibilidad impiden autenticar. `Auth::login()` establece la sesión Laravel y regenera/destruye el ID anterior. Dashboard como destino fijo. Roles, perfil y atribución proceden de CLUB; ningún claim concede permisos ni actualiza perfiles.

Un sujeto desconocido vuelve al login con instrucciones concretas de Fase 03: ingresar con acceso CLUB, usar **ACTIVAR MI ACCESO A JAKAWI.COM** en el dashboard y autorizar en COM. No crea usuarios, handoffs o vinculaciones por email. La vinculación existente exige su consentimiento y verificaciones originales. No hay logout global: el logout local conserva la sesión COM.

## Pruebas y publicación

**104 comprobaciones SSO/vinculación PASS**, más **73 comprobaciones MVP/acceso/registro PASS**. TypeScript, build Vite y navegador 390/1440 PASS.

`python3 scripts/test-sso.py` usa la imagen inmutable desplegada de COM en contenedores limitados con PostgreSQL desechable; CLUB usa un esquema aleatorio que se elimina al terminar. Cuentas fixture, login y CSRF nativos COM, autorización explícita Fase 03, sesión COM existente → código real → canje real → firma JWKS → callback CLUB → dashboard y panel administrativo. Se verifican sesiones independientes, replay, local login, cuenta miembro sin elevación por claims y conservación de cuentas. Casos adversos usan JWT firmados de prueba contra el kernel HTTP y el verificador reales. Ninguna cuenta/token/correo de prueba se escribe en LIVE.

TypeScript y Vite se ejecutan con los límites de los builders existentes. `bash scripts/check-sso-visual.sh` comprueba el login en 390/1440 píxeles, CTA principal, alternativa local, navegación completa y ausencia de errores/overflow; las mutaciones se interceptan. `--live` comprueba los assets publicados. Resultados en `sso-tests.json`, `contract-tests.txt`, `sso-com-invariants.json` y `browser-*.json`.

Publicación exclusiva CLUB, sin migraciones. Credencial registrada instalada en `/srv/sites/jakawi.club/secrets/com-oidc.env`, root 0600; Compose la inyecta solo en CLUB. Se actualizan source/vendor/assets/cachés y se recrea exclusivamente `jakawi-club-app` para cargarla. Assets previos conservados. Commit/push normal a `main`; `python3 scripts/verify-sso-live.py` verifica remoto, código montado, assets HTTPS, `/up`, inicio/callback de error reales COM, credencial aplicada, cookies exclusivas y permisos/vínculo del piloto. SHA LIVE en `LIVE.json`, excluido de Git para evitar autorreferencia.

Nginx CLUB conserva la política de referrer anterior en las demás rutas y aplica `no-referrer` en inicio/callback SSO. Esto evita que su cabecera adicional sobreescriba la protección emitida por Laravel. Se valida `nginx -t` y se recarga únicamente Nginx CLUB, sin recrear/reiniciar su contenedor. Se verifican todas las cabeceras Referrer-Policy recibidas en HTTPS.

Se conservan por hash landings, registro, confirmaciones, WhatsApp, correo/SES, FluentCRM, referidos y atribución. Se comparan las cuentas originales y sus credenciales/roles, y los contenedores COM/CRM conservan ID/imagen/inicio/reinicios. No se despliega ni modifica COM.

## Piloto y límites de verificación

`javierquiroztv@gmail.com` ya tiene vínculo real `LINKED` en ambos sistemas, sujeto coincidente, activación/verificación y permiso administrativo CLUB. No se asignó ningún sujeto ni permiso manualmente en esta entrega. La vinculación del propietario ya estaba completada antes de implementar el cliente.

El recorrido autenticado completo pasa con cuentas fixture contra la implementación real de COM. La comprobación LIVE no suplanta al piloto ni fabrica su sesión; el acceso personal COM → CLUB → dashboard/panel queda pendiente de la prueba del titular desde su navegador. No se declara realizada esa comprobación personal. Las sesiones host-only y el logout independiente se verifican en los tests.

Rollback: restaurar únicamente código/vendor/manifest/cachés CLUB desde `/srv/sites/jakawi.club/rollback-identity-phase04/`, recrear solo `app` con Compose anterior y reiniciar OPcache. Conservar cuentas, sesiones válidas, sujetos, vínculos y evidencias. No restaurar dumps ni ejecutar rollback de migraciones. El respaldo incluye source Git, vendor, assets, cachés y digests previos; no contiene contraseñas en claro.
