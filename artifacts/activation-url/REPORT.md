# URL corta de activación — JAKAWI.CLUB

Entrada comercial: **https://jakawi.club/activar**, membresía Bs 100.
GET nuevo; POST `/activar` y enlaces `/activar/{token}` conservados para activar
la cuenta por correo. Sin modificar captación ni confirmación WhatsApp.

Invitados llegan al login existente con opción al registro existente. La intención
fija `/activar` sobrevive login local, SSO, registro y correo abierto en otro navegador.
Referente original preservado; registro orgánico sin asignación inventada. Ningún
parámetro de destino externo modifica la continuación.

Autenticados usan handoff y OIDC/PKCE existentes antes del checkout único COM.
Un sujeto aún ausente se vincula por la autoridad existente antes de continuar.
Dashboard usa `/activar`; aviso QR pendiente retirado tras la confirmación del
propietario y SHA COM `fabbd76daed53c660d0e2744eaac089ec8ca18f0`.

Con una activación HMAC confirmada para su sujeto, el usuario ve **Tu membresía
está activada** y acceso a los detalles en COM, sin invitación a comprar. Se revisa
antes de SSO, después de vincular y antes de salir del callback. El contrato actual
no transmite vigencia o cancelaciones: CLUB muestra activación confirmada y remite
a COM para consultar vigencia, sin inventar duración ni una API de estado.

Validación: **137 comprobaciones aisladas** contra la imagen exacta de COM, con DB
desechable y esquema CLUB aislado. Incluye referente previo frente a último código,
orgánico, login fallido, correo en otro navegador, POST histórico, vinculación real,
login local/SSO, checkout y activación recibida durante OIDC. Una intención y un
InitiateCheckout COM; sin membresías, comisiones ni entregas CRM nuevas. Ningún
usuario, compra o membresía de prueba LIVE. TypeScript y Vite secuenciales correctos.
Navegador 390/1440 verifica login, CTA, ambos estados, continuación y cero overflow;
fixtures privados interceptados, sin escrituras ni apertura de checkout LIVE.

Despliegue exclusivo CLUB: chunks anteriores conservados, manifest al final,
cachés de rutas/vistas y reinicio solo app. Sin migraciones ni cambios de entorno,
red, Traefik, COM o CRM. Los scripts de Fase 08 admiten directorios de evidencia y
rollback propios para reutilizar su procedimiento sin sobrescribir la fase anterior.

`LIVE.json` sella SHA Git/origin, hashes del código y manifest servidos, `/up`,
validación local y comparación del estado comercial/contenedores antes y después.
Evidencia posterior al commit excluida de Git para evitar autorreferencia.
Rollback privado: `/srv/sites/jakawi.club/rollback-activation-url` contiene código y
assets anteriores; restaurar manifest/fuente, regenerar rutas/vistas y reiniciar app.
