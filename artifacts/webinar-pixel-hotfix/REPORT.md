# Hotfix de inicialización Meta Pixel del webinar

Diagnóstico puntual sobre https://jakawi.club/ads/meta/webinar, Chrome limpio con
ventana, SDK real y consentimiento mediante el botón de la página.

La configuración LIVE estaba habilitada, con Pixel ID 1429220108808185 disponible
al frontend solo tras consentimiento. Sin consentimiento el SDK está ausente:
es el comportamiento previsto, no un error de configuración. No hay evidencia
sobre la preferencia o extensiones del navegador particular del propietario.

Se encontró un defecto de inicialización: faltaba el alias estándar window._fbq.
El SDK real emitía “Multiple pixels with conflicting versions were detected on
this page” pese a tener un solo Pixel. Se añade únicamente ese alias al bootstrap
existente. No se instala otro Pixel ni se cambia el consentimiento o el tracking
de CompleteRegistration y su event_id compartido.

La versión anterior también enviaba PageView con HTTP 200 al aceptar el permiso
en Chrome con ventana. Por tanto no se atribuye al alias faltante un bloqueo total
del envío. La ausencia inicial de Pixel Helper no se reproduce como falta de
configuración: la prueba sin permiso sí muestra ausencia, correctamente.

La configuración pública que entrega Meta incluye un gateway OpenBridge. Se
comprobó el envío nativo a www.facebook.com/tr/ con HTTP 200. Esa configuración
externa no se modifica. No se cambia CAPI, TikTok, compras, comisiones ni credenciales.
Las pruebas headless eran filtradas por BotBlocking del SDK; se validó con Chrome
con ventana usando el Xvfb ya instalado, sin bloqueadores ni instalaciones globales.

Validación del candidato: Pixel ID correcto, alias correcto, SDK real HTTP 200,
PageView real HTTP 200, orgánico sin SDK, consentimiento persistente y revocable,
sin errores JavaScript ni CSP. TypeScript y build satisfactorios. Ocho escenarios
adicionales simulados a 390/1440 verifican consentimiento y deduplicación de registro.
Cero cuentas nuevas LIVE de prueba y cero conversiones LIVE de prueba.

HTTP 200 del endpoint Pixel demuestra transporte HTTP, no la aparición del evento
en Events Manager. CAPI sigue configurada y no se prueba con conversiones ficticias.
La detección de Pixel Helper requiere observar la extensión real; el estado fbq no
se presenta como sustituto de esa comprobación.
