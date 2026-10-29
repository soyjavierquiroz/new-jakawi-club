# Formulario y posconversión — jakawi.club

## A. Formulario final

Baseline Git confirmado antes de intervenir: `c315fe625b402fc2408f4d5ccb983ecc4e2a0908`; `main` y `origin/main` coincidían, árbol limpio y `git pull --ff-only` sin cambios.

LEAD_VSL, WEBINAR y WAITLIST conservan el mismo `RegistrationForm` y POST `/registro`. Se retira la ayuda «Bolivia · Incluye el código de área si corresponde. Para otro país, cambia el selector.» y su referencia accesible. Permanecen Bolivia +591 inicial, selector internacional, banderas locales, búsqueda, teclado, E.164, placeholders, labels accesibles, CTA coral, loading, errores y foco.

## B. Consentimiento

Texto exacto: **Acepto el acuerdo de privacidad**. «acuerdo de privacidad» enlaza a `/privacidad`.

Comienza marcado por petición explícita del propietario, se puede desmarcar y conserva `required|accepted` en el backend y `required` en el navegador. Desmarcar impide continuar. El estado inicial no acredita un clic afirmativo individual. Se conservan `privacy_accepted_at`, `privacy_version` y la versión existente `2026-10-28`.

La política denomina el mismo tratamiento «Acuerdo de privacidad» y describe fecha y versión de su aceptación. No se añaden finalidades de datos ni preferencias comerciales: esta aceptación no suscribe a marketing. Las preferencias opcionales de cookies de referidos conservan sus controles separados. La descripción de enlaces externos incluye el nuevo contador cancelable, sin afirmar una integración o envío de datos a WhatsApp.

## C. Gracias reutilizables y rutas

`pages/Registered.tsx` sigue siendo la única bienvenida y página de gracias. Usa logo, fuentes y tema oficiales, titular, mensaje, instrucciones y texto del CTA por landing. El estado de activación se calcula en PHP y se presenta separado de los textos editoriales. El contrato rechaza afirmaciones explícitas de activación/verificación/entrega en esos textos.

Ruta general: `/{slug}/confirmado`. Ejemplos: `/principal/confirmado`, `/webinar/confirmado`, `/fundadores/confirmado`. **En LIVE solo existe `principal` publicada**; no se crean landings ficticias. Las otras dos URLs funcionarán cuando existan esas landings publicadas y un recibo válido. Mientras no existan devuelven 404.

Solo un POST aceptado por el backend emite el recibo temporal existente. Se vincula al ID/slug, campaña y snapshot publicado de la landing. Una visita directa, un clic, un error, el registro cerrado o una query `success=1` no acreditan conversión. Sin recibo de esa landing se vuelve a la landing pública; slugs inexistentes, archivados o reservados no muestran gracias. El recibo vence a los 15 minutos. `confirmado` queda reservado; se conservan los namespaces y endpoints existentes.

Las acciones siguientes son: sin botón, solicitar acceso, ingresar a la cuenta, volver al inicio y acceder al grupo de WhatsApp. Las rutas internas se resuelven en PHP. El destino del grupo procede exclusivamente de `JAKAWI_WHATSAPP_URL` aprobado en la configuración privada, limitado a una invitación HTTPS de `chat.whatsapp.com`, sin credenciales, query ni puertos. **Actualmente no hay grupo configurado.** No se muestra un enlace ni se inicia un contador sin ese destino.

El contador del grupo es opcional: 0 (solo botón), 5, 10, 15, 30 o 60 segundos. Permite cancelar y conserva el botón manual. Refrescar o volver atrás no reinicia la navegación automática en esa pestaña. La preview nunca redirige. No se agregan enlaces Zoom, ofertas ni fechas.

## D. Editor y JSON

Grupo plegable **Después del registro**:

- Titular, mensaje de confirmación, instrucciones y texto del botón.
- Acción siguiente de una lista cerrada.
- Contador cancelable para la acción de WhatsApp y disponibilidad real del grupo.

Los textos `after_registration.title`, `message`, `instructions` y `cta_label` se incluyen en `jakawi-club-landing-v2`. La importación admite también los documentos v1 anteriores y conserva campos omitidos. Los snapshots antiguos reciben defaults en memoria, sin migración ni escrituras.

`next_step`, destinos, URLs y contador no pertenecen al JSON editorial. El importador rechaza su inyección y campos desconocidos/HTML; los controles administrativos también validan acciones y segundos. Guardar modifica solo el borrador; importar modifica solo el formulario local; publicar sigue siendo una acción independiente que copia el borrador guardado.

El selector de preview alterna Landing / Después del registro. La ruta autenticada `/admin/landings/{id}/preview/confirmado` usa `Registered` con el borrador guardado a 390, 430 y 1440 px, sin recibos, cuentas, analítica ni redirección automática. Mantiene las comprobaciones de cuenta activada, verificada y permiso editorial.

## E. Registro, bienvenida y activación reales

Se inspeccionaron `RegistrationController`, `RegistrationRequest`, `AccountMail`, `AuthController`, `LaunchSettings`, rutas, editor y política antes de cambiar el flujo.

El registro conserva normalización, creación transaccional y el único evento `registration_completed`. El correo repetido recibe una confirmación genérica indistinguible, sin otra cuenta ni conversión. `AccountMail` sigue utilizando el broker y la notificación existentes. No se afirma entrega de correo.

`/bienvenida` se conserva. Si hay recibo de landing redirige a sus gracias; sin él mantiene la bienvenida genérica existente. `/activar` continúa consumiendo el token de un solo uso, estableciendo contraseña, verificando correo e iniciando sesión. En la misma sesión, si el correo corresponde al recibo, vuelve a la landing original; una activación en otro navegador mantiene la bienvenida genérica. No se añade otro sistema de registro o activación.

«Cuenta activada» exige autenticación, `activated_at` y correo verificado; un login de otro correo no confirma la solicitud del recibo. Antes se muestra **Activación pendiente**, sin enlace personal. La confirmación verificada conserva el enlace de referido y acceso al dashboard.

Landing de origen, campaign key publicada y primer referente se conservan en el flujo existente. El snapshot del recibo no cambia si luego se publica otra campaña. Las visitas/refrescos de gracias y previews no emiten conversiones. No se modifican servicios de atribución, agregados ni reglas de afiliados.

## F. Pruebas y capturas

Contenedores limitados existentes, ejecuciones secuenciales, sin instalaciones:

| Comprobación | Resultado / evidencia |
| --- | --- |
| Backend específico: recibos, activación, JSON, destinos, consentimiento y cierre con log | 68 · [backend-tests.txt](backend-tests.txt) |
| Landings/editor, permisos, snapshots, JSON y guardado independiente | 146 · [landings-tests.txt](landings-tests.txt) |
| MVP: validación, login, activación, recuperación y referente inmutable | 73 · [mvp-tests.txt](mvp-tests.txt) |
| Unidades de teléfono/VSL | 60 · [unit-tests.txt](unit-tests.txt) |
| TypeScript estricto y Vite | [frontend-build.txt](frontend-build.txt) |
| Navegador build-next: formulario y gracias | 24 grupos · [browser.json](browser.json) |
| Navegador con assets LIVE/HTTPS | 27 grupos · [live-browser.json](live-browser.json) |
| Editor y previews de ambos renderers | 16 grupos · [editor-visual.json](editor-visual.json) |
| Validación local | [local-validation.json](local-validation.json) |
| Validación pública | [public-validation.json](public-validation.json) |

Chromium comprueba el formulario compartido en las tres plantillas a 390, 430 y 1440 px: checkbox marcado y posibilidad de retirarlo, privacidad, ausencia de ayuda de Bolivia, accesibilidad, selector, E.164, errores, loading, sticky y registro deshabilitado. Comprueba gracias pendientes/verificadas, ausencia de links inexistentes, CTA aprobado, contador, cancelación y refresco sin redirección repetida. El editor se comprueba también a 768 px.

Las pruebas PHP usan esquemas PostgreSQL temporales eliminados al terminar y notificaciones simuladas. Las páginas administrativas y estados de gracias en Chromium proceden de respuestas reales del kernel aislado; mutaciones y navegación al grupo se interceptan solo en navegador. **No son altas LIVE ni prueba de SMTP.** Las pruebas no vuelven a acreditar reproducción real de YouTube ni dispositivos físicos.

Capturas:

- Formulario HTTPS LIVE: [390](live-390.png), [430](live-430.png), [1440](live-1440.png).
- Gracias pendientes: [390](thanks-pending-390.png), [430](thanks-pending-430.png), [1440](thanks-pending-1440.png).
- Gracias verificadas (fixture): [390](thanks-confirmed-390.png), [430](thanks-confirmed-430.png), [1440](thanks-confirmed-1440.png).
- Preview de gracias: [390](thanks-preview-390.png), [430](thanks-preview-430.png), [1440](thanks-preview-1440.png).
- Preview integrada en el editor: [390](confirmation-preview-390.png), [430](confirmation-preview-430.png), [1440](confirmation-preview-1440.png).
- Contador con destino interceptado: [390](thanks-whatsapp-countdown-390.png).

## G. SMTP y estado operativo

Configuración efectiva: mailer `log`, sin host SMTP remoto ni credenciales; `JAKAWI_REGISTRATION_OPEN=false`, `JAKAWI_TRANSACTIONAL_MAIL_READY=false`, `mailReady()=false` y `registrationOpen()=false`. POST público `/registro` con CSRF válido conserva HTTP 403.

Las páginas quedan preparadas para el flujo autorizado cuando funcione el correo transaccional. No se cambian secretos ni proveedores, no se generan tokens reales ni se envían correos de prueba. La apertura sigue pendiente de SMTP real autorizado, entrega y activación comprobadas y aprobación operativa de las banderas existentes. El grupo requiere además una invitación real aprobada.

## H. Despliegue, Git y SHA LIVE

Solo jakawi.club. Se conservaron los chunks anteriores y se reemplazó el manifest al final de forma atómica; se regeneró `route:cache` y se reinició exclusivamente `app` para renovar OPcache. Sin migraciones, cambios de datos, snapshots de landings, secretos, SMTP, Nginx, PostgreSQL, Traefik, DNS ni otros proyectos. [Detalle del despliegue](deployment.json), [rollback](ROLLBACK.md).

Commit en `main` y push normal a `origin/main`. `python3 scripts/verify-postconversion-live.py` verifica igualdad de HEAD/origin/main/remoto, árbol limpio, archivos montados iguales a Git, assets HTTPS, chunks anteriores, rutas y permisos, registro cerrado, ambos `/up` HTTP 200 y datos/analítica/migraciones sin cambios contra el baseline privado. También coteja identidad, arranque y redes de contenedores ajenos y hashes de Traefik.

El SHA efectivo se sella después del push en `artifacts/postconversion/LIVE.json`, ignorado para evitar autorreferencia, y se comunica en la entrega. El baseline y manifest anteriores permanecen en `/srv/sites/jakawi.club/rollback-postconversion-c315fe625b40/`.
