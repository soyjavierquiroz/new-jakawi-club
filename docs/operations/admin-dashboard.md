# Centro de administración CLUB

Entrada: `GET /admin`. Requiere cuenta activa, correo verificado, autorización superadmin explícita existente (UUID CLUB + sub COM) y sesión OIDC del mismo actor. El permiso `is_marketing_admin` por sí solo recibe 403 en el resumen. No se conceden permisos nuevos ni se modifican los existentes.

Menú común, con sección activa, lateral a 1440 px y colapsable a 390 px:

| Sección | URL | Permiso existente |
| --- | --- | --- |
| Resumen | `/admin` | Superadmin explícito + OIDC |
| Partners afiliados | `/admin/partners-afiliados` | Marketing, activo, verificado |
| Libro de comisiones | `/admin/comisiones` | Marketing, activo, verificado |
| Reglas de comisiones | `/admin/reglas-comisiones` | Marketing, activo, verificado |
| Ofertas comerciales | `/admin/ofertas-comerciales` | Marketing, activo, verificado |
| Conversiones COM | `/admin/conversiones-com` | Marketing, activo, verificado |
| Landings | `/admin/landings` | Marketing, activo, verificado |
| Soporte de usuarios | `/admin/soporte` | Superadmin explícito + OIDC; controles de soporte intactos |

El menú se genera en servidor y oculta destinos no autorizados. Durante modo soporte solo muestra Soporte; se debe salir explícitamente antes de volver a la administración. Las URLs conservan sus middleware actuales. Todas las páginas administrativas, incluido el editor de landings y soporte, usan el layout común; las previews públicas de landings conservan su presentación.

El resumen consulta `affiliate_partners` habilitados, beneficiarios individuales distintos en `acquisition_people`, activaciones de `acquisition_activations` y `commission_ledger`. Pendientes y revisión reutilizan la consulta de última evidencia por evento sin comisión emitida del libro existente; no cuentan evidencias históricas repetidas. Los importes aprobados se agrupan por moneda y excluyen revisión. El apartado de pendientes de pago identifica aprobadas pendientes de liquidación e informa que el saldo efectivo de pagos no está disponible: no existen registros de desembolso que permitan afirmarlo. No se escriben tablas económicas ni se consulta COM/CRM desde el resumen.

## Validación y operación

Evidencia privada: `/srv/sites/jakawi.club/admin-dashboard-evidence`.

- `JAKAWI_EVIDENCE_DIR=... bash scripts/test-admin-dashboard.sh`: 40 comprobaciones; kernel HTTP real, PostgreSQL temporal eliminado al terminar; accesos permitidos/denegados, rutas del menú, filtros, totales, no duplicados, ausencia de mutaciones y solicitudes remotas. No crea cuentas LIVE.
- `bash scripts/build-frontend.sh`: TypeScript y Vite secuenciales con límites existentes.
- `JAKAWI_EVIDENCE_DIR=... bash scripts/check-admin-dashboard.sh` y `--live`: diez escenarios a 390/1440 px; respuestas HTTP privadas de los fixtures aislados, sin sesiones reales de administradores. Assets LIVE comprobados después del despliegue. Capturas solo del resumen y menú móvil.
- `python3 scripts/validate-local.py`, `/up` local y HTTPS después de desplegar.

Despliegue explícito autorizado solo CLUB desde `feat/admin-dashboard`, sin merge a main: empaquetar con `scripts/package-source-release.sh` y ejecutar `scripts/deploy-admin-dashboard.sh <paquete>`. El script comprueba el baseline LIVE revisado, pruebas y navegador; conserva bind mounts, publica chunks antes del manifest atómico, conserva chunks anteriores, regenera rutas/vistas y reinicia solo `app`. Sin migraciones, instalaciones, cambios de entorno ni comandos económicos.

Rollback manual: `/srv/sites/jakawi.club/rollback-admin-dashboard/release.json` contiene `previous_sha`; checkout detached de ese SHA en LIVE, restaurar atómicamente `manifest.json` del mismo directorio, regenerar `route:cache` y `view:cache`, reiniciar solo `app`, ejecutar validador local. Conservar assets y datos.
