# Formulario de captación simplificado — jakawi.club

## A. Interfaz compartida

Baseline esperado y confirmado antes de intervenir: `d8cadaec85de70fc431b504b409f90c7a56a7bd0`. `main`, `origin/main`, remoto y código montado LIVE coincidían; árbol limpio, assets HTTPS cotejados y ambos dominios `/up` HTTP 200.

`RegistrationForm` sigue siendo el único formulario de LEAD_VSL, WEBINAR y WAITLIST. Nombre, teléfono y correo usan `Tu nombre completo`, `WhatsApp` y `Tu correo electrónico`. Sus labels asociados permanecen accesibles y visualmente ocultos. Se conservan autocomplete, inputMode, required, límites, validaciones, mensajes y foco del primer error.

Se retiran el badge y los mensajes de preapertura, incluida la repetición del hero. El bloque de conversión no anuncia encuentros sin fecha confirmada; conserva el ancla `en-vivo` y presenta los encuentros configurados. No se modifican textos guardados ni snapshots de landings.

El selector conserva bandera local, nombre del país, prefijo, búsqueda, navegación por teclado, diálogo y normalización E.164. Bolivia +591 permanece inicial y pegar un número extranjero no cambia el país elegido. No se cambian las reglas telefónicas del frontend ni del backend.

CTA: `QUIERO SER PARTE DE JAKAWI`, tokens coral oficiales, estado `Un momento…` y `aria-busy`. Se conserva `¿Ya tienes cuenta? Ingresa aquí`, sticky móvil, VSL y las composiciones responsive.

## B. Privacidad

Texto visible exacto: **Acepto el aviso de privacidad**. `aviso de privacidad` enlaza mediante `/privacidad` a `https://jakawi.club/privacidad`.

El checkbox permanece **inicialmente desmarcado**. La revisión breve del uso real encontró `privacy: required|accepted` en `RegistrationRequest`, almacenamiento de `privacy_accepted_at` y `privacy_version` en `RegistrationController`, y un aviso publicado que describe ese dato como consentimiento. En este flujo representa una aceptación obligatoria registrada, por lo que se conserva la acción afirmativa existente; no se lo reclasifica como mero reconocimiento para premarcarlo. El usuario puede marcarlo y desmarcarlo. No se cambia la validación del backend ni la versión del consentimiento.

Este campo no suscribe a marketing. No existe una preferencia de comunicaciones comerciales en este formulario y no se introduce ninguna. La preferencia opcional de cookies de referidos conserva sus controles independientes.

## C. Estado real del registro

**Registro público cerrado.** Configuración efectiva comprobada sin revelar secretos:

| Comprobación | Estado |
| --- | --- |
| Mailer efectivo | `log` |
| Host SMTP remoto configurado | No |
| Credenciales SMTP presentes | No |
| `JAKAWI_REGISTRATION_OPEN` | `false` |
| `JAKAWI_TRANSACTIONAL_MAIL_READY` | `false` |
| `LaunchSettings::mailReady()` | `false` |
| `LaunchSettings::registrationOpen()` | `false` |
| POST público `/registro` con CSRF válido | HTTP 403 |

No se envían correos de prueba a `log`, no se generan tokens reales y no se crean cuentas LIVE. No hay evidencia de entrega SMTP ni se afirma apertura. El formulario y el botón permanecen deshabilitados; el botón expone ese estado y está asociado al único aviso breve **Registro no disponible.** El handler también impide enviar cuando `open=false`; el middleware conserva el bloqueo del servidor.

## D. Pruebas focalizadas

Ejecución secuencial en los contenedores limitados existentes, sin instalaciones:

| Comprobación | Evidencia |
| --- | --- |
| 60 pruebas unitarias de teléfono/VSL | [unit-tests.txt](unit-tests.txt) |
| 73 comprobaciones MVP: validaciones, bloqueo con log, activación simulada, login y referidos | [mvp-tests.txt](mvp-tests.txt) |
| 139 comprobaciones de landings, snapshots, plantillas y atribución | [landings-tests.txt](landings-tests.txt) |
| TypeScript estricto y Vite | [frontend-build.txt](frontend-build.txt) |
| 10 grupos de navegador previos con build-next | [browser.json](browser.json) |
| 12 grupos de navegador tras desplegar, incluido HTTPS real | [live-browser.json](live-browser.json) |
| Validación local | [local-validation.json](local-validation.json) |
| Validación pública y aislamiento | [public-validation.json](public-validation.json) |

Chromium a 390 y 1440 px comprueba las tres plantillas, un único formulario, ausencia de overflow, placeholders, nombres del árbol de accesibilidad, labels ocultos, país inicial, ausencia de mensajes de preapertura y bloqueo sin envíos. También comprueba búsqueda por país/prefijo, teclado y foco del diálogo, normalización E.164, error local de teléfono, consentimiento desmarcado obligatorio, posibilidad de retirarlo, loading, errores del servidor y foco, payload de referido/landing, sticky, política local y login. Los casos de apertura y errores de servidor se interceptan exclusivamente en navegador; **no acreditan correo real**.

Las pruebas PHP usan esquemas PostgreSQL temporales y notificaciones simuladas. Se eliminan sus fixtures temporales; cero usuarios de prueba LIVE. No se ejecutan benchmarks ni suites extensas. La reproducción real de YouTube no forma parte de este cambio ni se vuelve a acreditar con estos fixtures.

## E. Capturas

- HTTPS LIVE: [390 px](live-390.png), [1440 px](live-1440.png).
- LEAD_VSL: [390 px](closed-lead_vsl-390.png), [1440 px](closed-lead_vsl-1440.png).
- WEBINAR: [390 px](closed-webinar-390.png), [1440 px](closed-webinar-1440.png).
- WAITLIST: [390 px](closed-waitlist-390.png), [1440 px](closed-waitlist-1440.png).
- Errores de validación simulados: [390 px](validation-errors-390.png).

## F. Despliegue y Git

Solo jakawi.club: publicación del manifest al final mediante reemplazo atómico, conservando chunks anteriores; reinicio exclusivamente de `app` para renovar OPcache. Sin migraciones, cambios de secretos, usuarios o registros de landings; sin cambios de Traefik, DNS, jakawi.com, Nginx, PostgreSQL u otros proyectos. Detalle de assets en [deployment.json](deployment.json).

Commit en `main` y push normal a `origin/main`. Después del push, `python3 scripts/verify-registration-live.py` comprueba árbol limpio, igualdad HEAD/origin/main/remoto, archivos montados iguales a Git, assets HTTPS iguales al manifest, ambos `/up`, rutas, registro cerrado y datos sin cambios contra el baseline privado. También coteja identidad, arranque y redes de contenedores ajenos y hashes de configuración de Traefik. El SHA LIVE efectivo queda en `artifacts/registration/LIVE.json`, ignorado para evitar autorreferencia, y se comunica en la entrega.

Rollback preparado: [ROLLBACK.md](ROLLBACK.md).

## G. Único pendiente operativo para captar usuarios

**Disponer de SMTP transaccional real y comprobar la entrega y activación de una cuenta autorizada.** Actualmente no existe esa configuración. Una vez configurado de forma privada, hay que confirmar la entrega y completar el flujo; solo entonces aprobar `JAKAWI_TRANSACTIONAL_MAIL_READY=true` y habilitar `JAKAWI_REGISTRATION_OPEN=true` usando el procedimiento existente de recreación exclusiva de `app` y caché de configuración. No cambiar proveedores ni inventar credenciales. Las pruebas simuladas de esta entrega no sustituyen esa comprobación.
