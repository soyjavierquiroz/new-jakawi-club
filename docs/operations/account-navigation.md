# Navegación personal CLUB

`/dashboard` conserva su nombre de ruta y redirige 302 a `/mi-cuenta` tras `auth`; no ejecuta una segunda lectura de compras o membresía. Retornos OIDC existentes siguen funcionando a través de esa redirección.

El header autenticado sustituye el público sin duplicarlo. Menú colapsable a 390 px y visible a 1440 px, con sección activa y marca existente:

- Mi cuenta: `/mi-cuenta`.
- Mi membresía: `/mi-cuenta#mi-membresia`, estado efectivo existente y enlace informativo `/membresia`.
- Mis referidos y comisiones: `/afiliados`, solo cuenta activada y verificada como exige ActiveAffiliate. El panel mantiene la autorización Partner existente.
- Explorar beneficios: `https://jakawi.com/beneficios`, catálogo público independiente de beneficios exclusivos.
- Editar mi perfil: `https://jakawi.com/perfil`.
- Administración CLUB: `/admin` solo con concesión explícita y actor OIDC correcto. Marketing sin ese permiso entra a su primera sección administrativa autorizada; nunca al resumen superadmin.
- Cerrar sesión: POST `/logout` existente.

Gratuito, Estándar y Pionero no conceden permisos administrativos ni Partner. Sin nuevos roles, sesiones COM o lógica económica. Se conservan compras, activaciones, referencia personal y resultados. El modo soporte mantiene su layout y restricciones existentes.

## Comprobación individual

Consulta LIVE de solo lectura a COM el 3 de noviembre de 2026: se seleccionó el registro CLUB por la pareja UUID CLUB + sub COM de la concesión administrativa explícita, con coincidencia exacta del `global_subject_id` almacenado. El usuario resultante fue Javier Quiroz. `ComMembershipStatus::forUser` hizo la llamada HMAC existente, validó contrato y sujeto exacto; devolvió `available=true`, `type=free`, `status=free`, `is_active=false`, sin beneficios exclusivos. Se conserva Plan Gratuito. No se infiere membresía por nombre, email ni administración. No se registran identificadores ni claves en este documento.

## Validación y operación

Evidencia privada: `/srv/sites/jakawi.club/account-navigation-evidence`.

`test-free-oidc.sh`: kernel HTTP, JWT RS256 y PostgreSQL temporal; login gratuito, Partner, membresías efectivas, sujeto incorrecto, caída COM, redirección y menú. `test-admin-dashboard.sh`: permisos reales, menú administrativo y ausencia de escrituras económicas. `build-frontend.sh`: TypeScript/Vite secuenciales. `check-free-oidc.sh` y `--live`: siete escenarios a 390/1440 con respuestas aisladas y assets reales, sin sesiones ni usuarios LIVE creados. El escenario visual admin combina la cuenta Pionero de prueba con el menú real producido por el fixture superadmin; no representa membresía de Javier.

Despliegue manual autorizado solo CLUB en `fix/account-navigation`, sin merge a main: `package-source-release.sh` y `deploy-account-navigation.sh <paquete>`. Baseline ef711db; chunks antes del manifest atómico, cachés rutas/vistas y reinicio solo app. Sin migraciones, cambios de entorno, infraestructura o comandos económicos. Rollback privado en `/srv/sites/jakawi.club/rollback-account-navigation`: restaurar checkout `previous_sha` y manifest atómico, regenerar rutas/vistas, reiniciar solo app y validar localmente. Conservar chunks y datos.
