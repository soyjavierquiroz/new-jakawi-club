# Fase 06C — motor económico CLUB

Reglas: `https://jakawi.club/admin/reglas-comisiones`.
Libro: `https://jakawi.club/admin/comisiones`.
Afiliado: `https://jakawi.club/afiliados`.

Reglas de monto fijo introducidas por administración, en borrador inicialmente,
sin importes ni reglas activas predefinidas. Vigencia, partner/tipo COM y moneda;
términos inmutables y cambios mediante nuevas versiones. PostgreSQL NUMERIC(20,4),
strings monetarios en PHP/React y sumas por moneda sin float.

Libro único por operación, transaccional con el receptor HMAC existente; estados
registrada/candidata/aprobada/descartada. El importe permanece nulo sin regla.
Toda candidatura empieza pendiente de conciliación COM. Exclusividad con evidencia
externa y aprobación son decisiones manuales separadas; COM ya remunerada implica
descarte definitivo. Versionado y locks evitan aprobaciones concurrentes duplicadas.
Snapshots e historial inmutables; cancelaciones y cambios de atribución requieren
revisión y separan importes cuestionados del total aprobado conciliado.

El afiliado conserva conversiones y enlace/WhatsApp originales, y ahora ve sus
comisiones, detalle y totales por moneda. Nunca se presenta saldo retirable.
Admin con el permiso existente, sesión activada/verificada, CSRF y auditoría de
actores/motivos. No hay pagos ni llamadas a COM para inferir exclusividad.

Validación focalizada:

- 59 comprobaciones económicas HTTP/PostgreSQL aisladas: ausencia de regla,
  configuración, snapshots, importes exactos, concurrencia de eventos y aprobaciones,
  conciliación, bloqueo de doble remuneración, aislamiento y CSRF real.
- 64 comprobaciones de regresión de afiliados; la aserción del listado de conversiones
  se acota a sus filas porque el libro ahora conserva atribuciones históricas en revisión.
- 62 comprobaciones de regresión del receptor y administración comercial.
- TypeScript y Vite correctos, sin dependencias nuevas.
- Chromium 390/1440 px con fixtures del kernel: formularios, decimal como string,
  hora de Bolivia, conciliación, aprobación bloqueada, detalle privado y totales.
  Todos los envíos administrativos interceptados; sin sesiones/cuentas/eventos LIVE.

Una única migración aditiva; no modifica las tablas de eventos ni identidades.
Sin cambios de COM, FluentCRM, SSO, registro, SES, landings, WhatsApp o referidos.
Reglas LIVE y aprobaciones LIVE permanecen vacías al desplegar. La conciliación
histórica sigue requiriendo evidencia humana de COM; no se simula esa evidencia.

Verificación de despliegue: `check-commissions-visual.sh --live` y
`verify-commissions-live.py`; el sello ignorado `LIVE.json` registra SHA/origin,
código montado, assets HTTPS, migración y `/up`. Respaldo privado en
`/srv/sites/jakawi.club/rollback-commissions-06c/`; rollback solo de código/manifest,
conservando tablas y auditoría. [Operación exacta](../../docs/economics/commissions-06c.md).
