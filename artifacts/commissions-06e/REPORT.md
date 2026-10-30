# Fase 06E — cierre del motor económico CLUB

Corte aplicado: **2026-10-30T07:45:11.067511-04:00**. Configuración versionada;
solo hechos económicos COM confirmados estrictamente después son elegibles.
La fecha y sus microsegundos provienen del cuerpo original firmado, sin cambiar
el receptor. Las entregas tardías y actualizaciones de operaciones previas no
renuevan el devengo.

Se retiró la conciliación histórica COM del motor, administración y esquema.
La migración simplifica restricciones y columnas; no importa ni migra datos
legacy. Se mantienen referente CLUB verificado, regla real activa, importe
exacto, unicidad por operación, snapshots y auditoría. Sin regla no hay importe
ni obligación económica con monto. Aprobación administrativa manual; sin pagos
automáticos ni reglas ficticias.

Paneles actualizados: `/admin/reglas-comisiones`, `/admin/comisiones`, `/afiliados`.
Los enlaces personales y las conversiones comerciales conservan su flujo.

Validación:

- 82 pruebas económicas: corte antes/igual/después al microsegundo y UTC/Bolivia,
  recepción tardía, estados fuera de orden, referente presente/ausente, reglas
  activas/inactivas/futuras, exactitud y monedas, decisiones simultáneas,
  aprobaciones manuales, descartes definitivos, unicidad y auditoría inmutable.
- 62 pruebas de regresión del receptor comercial y 64 del flujo de afiliados.
- TypeScript y Vite satisfactorios, sin cambiar dependencias.
- Navegador de los tres paneles a 390/1440 px, formulario de aprobación sin
  conciliación, sin desbordes ni errores JS. Fixtures locales y envíos interceptados.
- Cero cuentas, eventos, reglas, notificaciones o pagos de prueba en LIVE.

El validador general `validate-local.py` ya falla antes de esta entrega porque
exige registro cerrado en la línea 52; el propietario lo mantiene abierto.
Se conserva su resultado previo y no se cambia el registro ni ese validador.
La comprobación focalizada de esta fase verifica `/up`, código montado, assets,
corte, esquema, rutas privadas, middleware, SHA remoto y contenedores CLUB.

Desplegado exclusivamente CLUB mediante fast-forward y push normal de main;
chunks anteriores conservados y manifest publicado al final. Solo se reinicia
`app`; mantenimiento de 6,79 segundos. `/up` local y HTTPS devuelven 200.
El navegador con assets LIVE confirma ambos tamaños sin errores ni mutaciones.
No se cambian COM, FluentCRM, identidad/SSO, landings, registro, receptor
comercial ni enlaces personales. La base LIVE comprobada antes del despliegue
contenía cero reglas y cero entradas económicas.

Verificación final y SHA efectivo: `LIVE.json` local ignorado, generado por
`python3 scripts/verify-commissions-live.py` después del push. Evidencia visual
con assets HTTPS: `browser-live.json`. Respaldo privado de código, assets y cachés:
`/srv/sites/jakawi.club/rollback-commissions-06e/`. La versión 06C no debe
restaurarse sobre el esquema simplificado: cualquier corrección debe conservar
el corte y utilizar código compatible. No se reintroduce conciliación legacy.
