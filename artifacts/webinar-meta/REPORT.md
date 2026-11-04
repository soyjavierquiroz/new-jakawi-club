# Funnel Meta del webinar

Landing LIVE constatada: `/` y `/principal`, plantilla WEBINAR publicada, fecha
2026-11-04 08:30 America/La_Paz. Membresía `/membresia` es una oferta independiente.
«En vivo» apunta a `/#en-vivo`; se añadió el id al bloque real de fecha del webinar.
El dashboard usa otra configuración de encuentro, actualmente sin fecha ni acceso
Zoom. La confirmación publicada no tiene grupo WhatsApp ni CTA comercial: el
siguiente paso configurado es recuperación de acceso. No hay evidencia de asistencia.

El registro operativo es creación de cuenta CLUB mediante `/registro`, con origen
persistido y recibo en `/principal/gracias` (alias `/principal/confirmado`). Un correo
existente recibe la misma confirmación genérica, pero no crea una nueva inscripción.
Esta entrega mide exclusivamente cuentas nuevas desde la landing WEBINAR; no afirma
que exista un registro separado para cuentas existentes o asistencia al evento.

Nueva entrada Meta: `/ads/meta/webinar`; POST `/ads/meta/webinar/registro`; gracias
`/ads/meta/webinar/gracias`. Reutilizan landing, formulario, controlador de registro,
AdVisit, AdEvent, DeliverAdEvent y cola existentes. No hay migraciones ni sistema
analítico nuevo. Configuración independiente `META_WEBINAR_ENABLED`; las campañas de
membresía conservan su habilitación anterior. No se modifican compras/comisiones,
contratos COM, integración CRM o TikTok.

Una cuenta nueva guardada genera CompleteRegistration, nunca Lead adicional.
El UUID persistido se comparte entre Pixel y CAPI. Recargas y reenvíos no generan
otra conversión. Los errores de tracking no revierten el registro. Sin permiso no
hay visita publicitaria, SDK ni conversión externa. Revocación y vencimiento bloquean
browser y entrega pendiente. Origen Meta y UTMs se conservan separados del orgánico;
se conserva el primer referente válido y no se altera una atribución comprometida.

Se reutilizan las tablas de totales y el panel de edición de la landing para mostrar
visitas por sesión/día/canal, cuentas nuevas, tasa desde la implementación, origen y
estados de entrega CAPI. No se mezclan los totales históricos en el denominador nuevo.
Los estados publicitarios tienen la retención existente; accepted confirma la respuesta
API, no la atribución de un anuncio ni la recepción de Pixel.

Privacidad: responsable y correo publicados según indicación del propietario.
Se eliminó la afirmación obsoleta de que el registro estaba cerrado.

Comprobación privada sin eventos: variables Meta presentes en app.env; token válido
en consulta de identidad Graph. Lectura del objeto Pixel: #100 Missing Permission.
Este permiso de lectura no demuestra ni descarta permiso CAPI. No se enviaron eventos
a Meta para verificarlo. La recepción y deduplicación externas deben observarse con
la primera inscripción auténtica en Events Manager; no se afirman comprobadas.

Validación: 29 comprobaciones focalizadas de webinar, 134 de registro/confirmación,
106 de regresión checkout/publicidad y 8 escenarios de navegador (390/1440).
TypeScript y build satisfactorios. PostgreSQL usa esquemas temporales eliminados;
correo y APIs simulados. Cero registros ficticios o eventos externos LIVE.
Las fixtures antiguas de regresión se ajustaron a la ruta actual `/mi-cuenta`,
a la lectura independiente de derechos COM y al contrato de checkout ya existente;
no se cambiaron esos flujos de producción.
