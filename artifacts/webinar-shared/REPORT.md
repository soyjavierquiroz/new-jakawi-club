# Un webinar: entradas orgánica y Meta

Las dos entradas ya usaban Welcome y el mismo RegistrationForm, y ambos POST
llamaban RegistrationController::store. Las dos confirmaciones también usaban
Registered a través de RegistrationController::confirmed. No había un JSX Meta
que clonar o reemplazar. La divergencia temporal provenía de congelar contenido,
video y grupo en cada recibo de sesión: recibos emitidos antes de publicar un
cambio continuaban mostrando los valores anteriores. La selección del webinar
además estaba repetida en ads.webinar_landing_slug y jakawi.home_landing_slug.

Se elimina la selección paralela de Ads. Ambas entradas resuelven el webinar desde
jakawi.home_landing_slug, actualmente principal, y su MarketingLanding publicada.
Los recibos nuevos del webinar guardan solo id, slug y campaña. Antes de renderizar
una confirmación autorizada se resuelven contenido, video, grupo y siguiente paso
desde published_configuration vigente, por un único ensamblador compartido con
registro y preview. También se actualizan los recibos antiguos que contenían
snapshots. No se amplía su vencimiento ni se alteran origen, cuenta, referente,
estado de activación o eventos. Otras landings mantienen sus snapshots existentes.

El editor conserva su separación borrador/publicación: publicar una vez actualiza
las dos entradas y sus confirmaciones ya emitidas. Deshabilitar un video o grupo
lo retira de ambas. No se inventan destinos ni se exponen grupos sin recibo válido.
Las URLs públicas, componentes React y diseño permanecen iguales.

Al comenzar, la publicación de principal ocultaba video y grupo; el propietario
los habilitó durante esta tarea. Se verificó después la revisión 52 publicada con
ambos habilitados. El despliegue no modifica configuración ni datos del webinar.

Validación focalizada:
- 32 comprobaciones nuevas: comparación de fuentes, configuración actual publicada,
  editor PUT + publicación reales en esquema PostgreSQL aislado, cambios reflejados
  en recibos nuevos/antiguos, campos editoriales, permisos, vencimiento y activación.
- 33 regresiones del funnel Meta: referente, origen, nuevo registro efectivo,
  CompleteRegistration, UUID persistido compartido y CAPI/cola con HTTP simulado.
- 134 regresiones de registro/confirmación: snapshots de otras landings, acceso,
  privacidad, activación y reglas de disponibilidad existentes.
- 20 escenarios de navegador a 390/1440: comparación del contenido renderizado,
  video, WhatsApp, textos, botones, reglas del formulario y estado de activación;
  un init/PageView Meta, ningún Pixel orgánico, ninguna conversión al refrescar.
- TypeScript y Vite correctos, sin cambios ni dependencias frontend nuevas.

Capturas locales: organic-*.png frente a landing-*.png y organic_thanks-*.png
frente a thanks-*.png. Son respuestas reales del kernel aislado con una copia de
la configuración publicada vigente de principal y assets de CLUB; el SDK Meta y
proveedores externos se simulan, sin escrituras ni conversiones LIVE. El bloque de
video está montado con su URL/configuración real; la captura no prueba reproducción
externa del proveedor. browser-preview.json documenta la igualdad del DOM.
Las capturas principal y Meta de confirmación coinciden byte por byte a ambos anchos.
Los enlaces de invitación se omiten del informe versionado.

La comprobación LIVE posterior y el SHA efectivo se sellan en LIVE.json local.
Pixel 1429220108808185, CompleteRegistration y DeliverAdEvent conservados.
No se crean cuentas o conversiones ficticias LIVE. Ningún cambio a COM, CRM,
TikTok, membresías, pagos, comisiones o infraestructura.
