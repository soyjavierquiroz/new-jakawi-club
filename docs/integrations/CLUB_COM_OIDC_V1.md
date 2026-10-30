# JAKAWI COM → CLUB: OIDC Authorization Code + PKCE v1

COM es el proveedor OpenID Connect y mantiene su login Fortify, sesión, 2FA y passkeys. CLUB es el cliente confidencial de primera parte. Esta fase despliega solamente COM; el consumidor y la callback de CLUB se implementan por separado en CLUB.

## Implementación mantenida

- [Laravel Passport](https://laravel.com/framework/docs/13.x/passport) 13.10.0 y [League OAuth2 Server](https://github.com/thephpleague/oauth2-server) 9.4.1 validan cliente, código, PKCE, redirección, expiración y revocación.
- [jeremy379/laravel-openid-connect](https://github.com/jeremy379/laravel-openid-connect/tree/3.3.0) 3.3.0 añade ID tokens RS256, nonce ligado al código y JWKS. Su manifiesto admite Laravel 13, Passport 13 y PHP 8.4. No se reemplaza su protocolo ni su firma.
- La integración COM adapta los claims al sujeto existente y restringe rutas, cliente, scopes y PKCE. Discovery describe esta superficie limitada. Un bloqueo transaccional PostgreSQL serializa el canje de un mismo código entre procesos; tanto emisión como consumo se confirman juntos.
- El único cambio de dependencia existente es el parche `symfony/mime` 8.1.7 → 8.1.10 para CVE-2026-45070. Las demás dependencias existentes se conservan.

## Endpoints

| Parámetro | Valor |
| --- | --- |
| issuer | `https://jakawi.com` |
| discovery | `https://jakawi.com/.well-known/openid-configuration` |
| authorization endpoint | `https://jakawi.com/oauth/authorize` |
| token endpoint | `https://jakawi.com/oauth/token` |
| JWKS | `https://jakawi.com/oauth/jwks` |
| callback registrada y esperada | `https://jakawi.club/auth/jakawi/callback` |
| response type / mode | `code` / `query` |
| grant | `authorization_code` |
| PKCE | `S256`, obligatorio aun con secreto de cliente |
| scopes permitidos | `openid`, `profile`, `email` |
| firma ID token | `RS256`, `kid` publicado en JWKS |
| autenticación del cliente | `client_secret_basic` preferido; `client_secret_post` admitido |
| código / access token / ID token | 120 s / 300 s / 3600 s |

No se ofrece UserInfo, refresh/offline tokens, registro dinámico, gestión pública de clientes, password grant, client credentials, implicit, device flow ni logout federado. Los access tokens no abren rutas locales de COM: el guard web existente sigue siendo de sesión. No se añaden privilegios ni se usa el access token como prueba de identidad; CLUB verifica el ID token.

`prompt` admite omisión, `none` o `login`. Una sesión COM válida permite emitir el código sin pedir contraseña; CLUB está preautorizado como cliente de primera parte. `none` sin sesión devuelve `login_required` y el state al cliente validado. `login` solicita autenticación local completa. Parámetros adicionales, incluidos `max_age`, `claims`, `request`, `request_uri` y `prompt=consent`, se rechazan en esta integración limitada. No se anuncia una certificación OIDC.

## Configuración privada y entrega operativa

El valor real de `client_id`, el secreto exclusivo de CLUB y los ajustes del proveedor están en `/opt/sites/jakawi.com/oidc-club.env`, root 0600, fuera de Git. El informe de despliegue entrega el `client_id`, que es público. El secreto nunca se entrega por chat, Git ni navegador. La instalación de esa credencial en CLUB corresponde a su siguiente implementación; esta fase no escribe en CLUB.

| Variable privada COM | Uso |
| --- | --- |
| `OIDC_ISSUER` | issuer fijo anterior |
| `OIDC_CLUB_CLIENT_ID` | UUID del cliente registrado |
| `OIDC_CLUB_REDIRECT_URI` | única callback exacta anterior |
| `OIDC_CLUB_CLIENT_SECRET` | secreto aleatorio exclusivo, distinto de identidad/CRM |
| `OIDC_KEY_DIRECTORY` | directorio montado de claves RSA de COM, solo lectura |
| `OIDC_KEY_ID` | identificador público de la clave activa |

CLUB necesita `issuer`, `client_id`, `client_secret`, `redirect_uri`, scopes `openid profile email`, Authorization Code, PKCE S256 y validación RS256/JWKS. Debe usar una biblioteca cliente OIDC mantenida y almacenar su secreto en configuración privada del servidor.

`php artisan oidc:register-club` registra el cliente únicamente desde esta configuración. Su secreto queda hasheado en `oauth_clients`; una segunda ejecución verifica la misma configuración y no rota ni reemplaza el cliente. Una discrepancia o revocación requiere intervención explícita. No ejecutar `passport:install` ni regenerar claves durante cada despliegue.

Las claves RSA están fuera de imágenes y Git, en `/opt/sites/jakawi.com/oidc-keys`, montadas en `/run/jakawi-oidc` para app/worker/scheduler. Sus permisos permiten lectura al usuario PHP. Mantenerlas estables entre despliegues y respaldarlas de forma privada. JWKS publica solamente la clave pública activa. Cualquier futura rotación debe incorporar solapamiento de claves para la vida de los ID tokens ya emitidos; no sustituir la clave activa sin ese trabajo.

## Solicitud de autorización desde CLUB

1. El usuario inicia el acceso desde CLUB. CLUB genera por cada intento `state` y `nonce` independientes con al menos 32 bytes aleatorios, codificados base64url sin padding, y un `code_verifier` aleatorio de 43–128 caracteres conforme RFC 7636. Guarda esos tres valores, la expiración y la intención de acceso en su sesión privada, ligados a ese intento.
2. Calcula `code_challenge = BASE64URL(SHA256(code_verifier))`. Redirige el navegador a `/oauth/authorize` con `client_id`, `redirect_uri`, `response_type=code`, `scope=openid profile email`, `state`, `nonce`, `code_challenge` y `code_challenge_method=S256`.
3. COM valida el cliente y la URI exacta, sin comodines ni destinos elegidos por el usuario. `state` y `nonce` deben ser cadenas opacas URL-safe de 32–512 caracteres. Una solicitud inválida recibe HTTP 400 local, sin redirección. Passport comprueba además registro activo, scopes y grant.
4. Sin sesión, COM redirige a su login existente y conserva la solicitud. Contraseña más los controles configurados, o passkey, completan la misma sesión nativa. Una contraseña antes de terminar 2FA no permite emitir código. Con sesión válida se reutiliza directamente. Si los formularios React/Inertia reanudan la autorización por XHR, COM usa `Inertia::location()` para continuar mediante navegación completa antes de la callback externa; no se emite código durante esa petición XHR.
5. COM vuelve a la callback registrada con `code` y el `state` original. El código solo dura 120 segundos y queda ligado a cliente, URI y challenge. El nonce se transporta dentro del código protegido por la biblioteca y luego dentro del ID token firmado.

## Callback y canje desde el servidor CLUB

1. La callback debe existir en la ruta exacta registrada. Antes de canjear, CLUB exige una transacción pendiente, no expirada, y compara `state` con el almacenado mediante comparación segura. Un state desconocido, cambiado, ausente o ya consumido termina el flujo sin autenticar ni crear/vincular usuarios.
2. Envía un POST `application/x-www-form-urlencoded` al token endpoint, autenticado con HTTP Basic (`client_id` y secreto). Cuerpo: `grant_type=authorization_code`, `code`, `redirect_uri` exacta y `code_verifier` original. Nunca poner el secreto o verifier en la URL ni enviar el secreto al navegador.
3. Una respuesta válida contiene `token_type=Bearer`, `expires_in=300`, `access_token`, `scope` e `id_token`. No contiene refresh token. Respuestas de código inválido, expirado, consumido o PKCE incorrecto son HTTP 400; secreto incorrecto, HTTP 401. Un PKCE/secreto/URI incorrecto no emite token ni consume el código legítimo. Intentos simultáneos con el mismo código solo producen un canje válido.
4. La biblioteca OIDC cliente debe verificar firma con JWKS y algoritmo permitido **RS256**, `kid`, issuer exactamente `https://jakawi.com`, `aud` del cliente registrado, tiempos `iat`/`exp`, `nonce` idéntico al almacenado y presencia de `sub`. Consumir la transacción una sola vez; no conservar tokens en logs o URLs posteriores. Ante cualquier error, no establecer sesión CLUB.
5. `sub` es **exactamente `users.global_subject_id` de COM**, el mismo UUID global de Fase 03. `iss` + `sub` identifican la cuenta. El PK bigint local no cambia. CLUB compara ese sujeto con el vínculo autorizado que ya conserva. Nunca enlaza por coincidencia de email, nunca reemplaza un sujeto ya asignado y no concede roles/membresías desde claims. Un sujeto inesperado exige el procedimiento de vinculación explícita, no una reasignación automática.

El email solo se incluye si se solicitó `email`; `email_verified` representa el estado real de COM. `profile` permite `name`. Los claims nunca contienen contraseña, rol administrativo, membresías, Partner, comisiones o secretos. Iniciar sesión OIDC no crea cuentas ni reconstruye sujetos/vínculos.

## Verificación y recuperación

`bin/jakawi-oidc-test` ejecuta las pruebas focalizadas sobre la red PostgreSQL desechable `jakawi-oidc-test`. `bin/jakawi-oidc-http-test.py <imagen-app-inmutable> <imagen-web-inmutable>` prueba cookies, login/CSRF real, callback, canje, validación del ID token con JWKS y concurrencia en una base distinta desechable, sin usuarios/sesiones de producción. El informe posterior al despliegue distingue estas pruebas del control HTTPS público.

Las cinco migraciones nuevas son las tablas aditivas upstream de Passport, publicadas con nombres propios de esta fase; no alteran `users`, sujetos, vínculos, roles o membresías. La publicación aplica solo esas cinco migraciones. El rollback operativo restaura imágenes/configuración anteriores de COM y conserva tablas, claves, cliente y sujetos. No usar `migrate:rollback` ni restaurar un dump sobre sujetos globales emitidos.
