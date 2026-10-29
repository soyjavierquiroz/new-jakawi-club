# Identidad global CLUB — preparativos locales

**Informe histórico.** Continuación de estos preparativos y estado operativo actual: [Fase 03](../identity-phase03/REPORT.md).

## Implementado

Migración aditiva preparada, **pendiente en producción**, `2026_10_29_000003_add_global_subject_id_to_users`: añade `users.global_subject_id` UUID nullable y único, sin backfill ni cambios en identificadores, credenciales, roles, referente o atribución. El campo queda fuera de los atributos asignables desde formularios. No hay cambios de dependencias, frontend, SMTP, CRM, registro ni configuración de sesiones.

Prueba focalizada: `bash scripts/test-global-identity-schema.sh`. Usa PostgreSQL en un esquema temporal, sin cuentas LIVE ni llamadas COM. Comprueba tipos, conservación de cuentas preexistentes, varios NULL, unicidad, persistencia y exclusión de asignación masiva. El UUID de prueba es sintético y nunca se escribe en producción.

## Bloqueo comprobado

El contrato arquitectónico v1 aprobado conserva en su sección 10 el handoff como propuesta técnica: no especifica todavía la autenticación concreta, esquema HTTP definitivo, respuesta verificable ni continuación interactiva. No se convierte esa propuesta en un protocolo inventado desde CLUB.

Al revisar el runtime actual de COM el 29/10/2026, `route:list --path=identity --json` devuelve `[]`; el modelo desplegado no tiene columna `global_subject_id`; `OPTIONS https://jakawi.com/internal/identity/handoffs` responde 404. Esa URL es exclusivamente la propuesta del contrato local. El propietario confirmó después que el endpoint aún no está implementado ni desplegado y limitó esta intervención a preparativos locales independientes de COM. Los `/up` de CLUB y COM responden 200.

Prerrequisitos para continuar: URL real, contrato técnico implementado, autenticación acordada y SHA LIVE verificado de COM. Permanecen pendientes el cliente autenticado, operación idempotente, provisión explícita, autorización interactiva de cuentas COM existentes, persistencia del sujeto recibido, CTA exclusivamente privado y pruebas de ambos flujos. No se muestra una acción que todavía no puede completar su función, ni se vincula por coincidencia de email. Esta entrega no acredita el flujo de identidad funcionando.

## Piloto

La cuenta autorizada `javierquiroztv@gmail.com` existe en ambos runtimes. En CLUB está activada, verificada y conserva su permiso administrativo; en COM tiene email verificado. Se solicitó recuperación mediante el broker nativo de CLUB, que devolvió `passwords.sent`, sin cambiar la cuenta, contraseña o permisos. No se leyó ni expuso el token; el titular debe usar el correo e iniciar sesión. No se creó ningún administrador alternativo ni se reutilizaron sesiones de navegador.

La autorización de vinculación sigue pendiente del endpoint operativo y de la autenticación del titular en ambos sistemas. Ninguna identidad global se asigna manualmente.

## Verificación de los preparativos

La evidencia focalizada está en `schema-tests.json`; los resultados de regresión y límites se registran en `validation.json`. La comprobación posterior al commit/push mediante `python3 scripts/verify-global-identity-preparation.py` queda en `LIVE.json`, excluido de Git para evitar autorreferencia; distingue el SHA preparatorio del SHA operativo anterior. La base privada de comparación está en `/srv/sites/jakawi.club/rollback-global-identity/`; no contiene contraseñas en claro. El digest de cuentas conserva todas las columnas originales.

Pasaron 153 comprobaciones: esquema 12, MVP/acceso/registro 73, embudo actual 34 y cola CRM 34. Las notificaciones de esas suites son simuladas. Dos comprobaciones antiguas no pasan: `validate-local.py` todavía exige registro cerrado, pese a la apertura ya autorizada, y `test-landings.sh` exige WhatsApp en captación, contrario al embudo vigente. Este último fallo se reprodujo con el código intacto del commit anterior `d4078a94382c59365544b43b621b75988cd824a7` en un esquema aislado. No se alteraron esos validadores ni el embudo para cambiar sus resultados. No se probaron provisión, autorización interactiva ni reintentos de identidad COM.

No se aplica la migración a producción ni se declara un nuevo despliegue. No se reconstruyen assets, regeneran cachés ni reinician contenedores. El manifest y la configuración de correo se verifican por hash. No se modifica ni despliega COM o FluentCRM. El cambio de esquema se probó exclusivamente en esquemas PostgreSQL temporales.

Rollback conservador: mantener la columna nullable sin utilizar. No ejecutar un rollback general de migraciones. Eliminar esta columna solo sería admisible mientras no existan vínculos; después implicaría pérdida del mapeo global.
