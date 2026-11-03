# Soporte superadmin de solo lectura — CLUB

Entrada: `https://jakawi.club/admin/soporte`. Enlace administrativo directo:
`/admin/soporte?sub=<global_subject_id UUID COM>`. Requiere sesión OIDC normal del
actor real; el soporte COM no puede emitir autorización OIDC y no se elude ese bloqueo.

No existe un rol superadmin preexistente en CLUB: marketing no habilita soporte.
La lista privada `storage/app/private/support/superadmins.json` asigna exclusivamente
un sub COM al UUID local existente, activo y verificado. Se relee en cada petición.
Con autorización explícita del propietario para ese UUID:

```sh
docker compose exec -T --user www-data app php artisan support:superadmin <UUID-COM>
# Revocación inmediata:
docker compose exec -T --user www-data app php artisan support:superadmin <UUID-COM> --revoke
```

El registro no crea cuentas ni cambia roles de marketing o permisos económicos.
No se infiere autorización por email, nombre o sesión del destino. Sin UUID
confirmado por el propietario no se concede acceso a nadie.

## Reautenticación y duración

POST/CSRF solicita OIDC con `prompt=login`, PKCE, state y nonce, usando el contrato
COM existente y su autenticación completa (MFA configurado o passkey). No se usan
`max_age`, `acr` ni claims no soportados. La transacción dura cinco minutos;
el callback exige la misma cuenta y sub del actor y no ejecuta proyección,
vinculación ni login del destino. La prueba local de reautenticación sirve cinco
minutos y se consume al iniciar una consulta. Cada consulta dura como máximo
900 segundos, sin extensión al leer ni cambiar de pestaña. Un motivo de 10–500
caracteres es obligatorio. Salir permite volver a la cuenta real del actor.

## Pantallas y datos

- Búsqueda por sub exacto o nombre de cuenta CLUB existente (máximo 20 resultados).
  La búsqueda y el enlace directo requieren reautenticación para revelar resultados.
- Mi cuenta: datos personales permitidos, restricciones de activación/verificación,
  conexión, referidos agregados, activación histórica y compras autorizadas por
  pertenencia local o vínculo COM confirmado. Compras paginadas a 25; último estado
  persistido, sin ejecutar refresh ni reconciliación.
- Plan efectivo: Gratuito, Estándar y Pionero; consulta HMAC existente a COM con el
  sub del destino almacenado. Errores o sujeto distinto no conceden beneficios.
  Pionero conserva la política oficial +15 meses sin inventar fecha de lanzamiento.
- Afiliados: solo si el destino cumple los controles actuales (activo y correo
  verificado). Resultados, enlaces ya existentes, conversiones y comisiones
  individuales con los mismos scopes de UUID/código del panel normal. Paginación.
  Paneles Partner requieren consulta COM fresca del permiso económico del destino;
  no se reutilizan permisos del actor ni concesiones de sesión.
- Un sub sin cuenta CLUB se muestra como ausente; no se consulta como una identidad
  inventada ni se confirma que exista en COM. No se crea cuenta.

La pantalla es una proyección administrativa segura de los datos y restricciones;
no carga los controladores normales que pueden sincronizar compras al abrirse.
Todas las consultas de datos del destino se ejecutan en una transacción PostgreSQL
`READ ONLY`. Solo se serializan campos explícitos. Sin tokens, claves, PIN,
intents, comprobantes privados, URLs de autorización ni enlaces de acciones COM.

## Bloqueo y auditoría

Barra fija «MODO SOPORTE — Viendo a …», tiempo restante y salida POST/CSRF.
Mientras exista el contexto de soporte, se permite únicamente leer sus tres rutas
administrativas y salir. `/mi-cuenta`, `/dashboard` y `/afiliados` redirigen a la
proyección segura. El resto de GET y todos los métodos de escritura se bloquean,
incluidos checkout, identidad, referidos, afiliación, comisiones, exportaciones,
medición y callbacks. No se autentica ni se emite OIDC como destino. El vencimiento
mantiene el bloqueo hasta la salida explícita. La salida funciona tras revocación.
Las rutas de navegador se serializan por sesión para evitar carreras entre pestañas.

Auditoría privada append bajo flock y fsync, permisos 0600, en
`storage/app/private/support/audit.jsonl`, sobre el storage persistente exclusivo de
CLUB. Guarda acción, timestamp, ID de consulta, UUID/sub del actor y destino, y motivo.
Registra reautenticación, búsqueda, inicio, cada pantalla, bloqueos, expiración
observada por servidor y salida. Sin registro de cuerpos, excepciones, tokens ni
respuestas remotas. Si falla la auditoría no se entrega la consulta. El archivo no
se expone por HTTP; respaldarlo junto con el storage privado. Expiración sin nueva
petición queda determinada por el timestamp del inicio +900 s.

## Validación y despliegue

Pruebas focalizadas: `JAKAWI_EVIDENCE_DIR=/srv/sites/jakawi.club/support-evidence bash scripts/test-support.sh`.
Kernel HTTP real, JWT RS256 y esquema PostgreSQL temporal eliminado; no cuentas LIVE.
`bash scripts/build-frontend.sh` ejecuta TypeScript y Vite secuenciales.
`bash scripts/check-support.sh` y `--live`, con el mismo directorio de evidencia:
fixtures privados de los tests y assets preview/LIVE, 390/1440 px, sin sesión real
administrativa ni mutaciones LIVE. No equivalen a una sesión interactiva del propietario.

Empaquetar con `scripts/package-source-release.sh`; despliegue autorizado exclusivamente
CLUB mediante `scripts/deploy-support.sh <paquete>`. Verifica baseline y evidencias,
conserva chunks anteriores, manifest atómico, cachés config/rutas/vistas y reinicio
exclusivo de app. Sin migraciones, env, COM, CRM o infraestructura compartida.
Rollback: SHA previo y manifest en `/srv/sites/jakawi.club/rollback-support`; restaurar
ambos, regenerar config/rutas/vistas y reiniciar únicamente app. Nunca borrar datos,
auditoría, grants ni chunks. No fusionar main automáticamente.

Resultados previos al despliegue: 77 comprobaciones de soporte, 153 de regresión Partner y 45 OIDC; 12 escenarios visuales responsive.

## Navegación COM ↔ CLUB

Entrada canónica `/admin/soporte?subject=<UUID-COM>`; `sub` sigue siendo alias.
UUID inválido o aliases contradictorios se rechazan. La selección no concede
permiso alguno. Sin sesión CLUB, la entrada inicia OIDC normal del actor y conserva
el destino en la transacción cifrada con state/nonce/PKCE. El callback regresa a
la entrada fija y el servidor exige el superadmin CLUB autorizado antes de mostrar
datos. COM sigue bloqueando OIDC bajo su modo soporte: para autenticar al actor,
este debe usar su identidad real fuera del modo soporte COM. No se evade ese control.

Una consulta vigente para el mismo sub se reutiliza sin ampliar su expiración.
Otro destino se preselecciona y exige cerrar por POST la consulta anterior;
la nueva consulta conserva reautenticación y motivo obligatorios. Con selección
exacta no se repiten los formularios de búsqueda/elección. La ausencia de cuenta
CLUB se informa sin crearla. Una sesión vencida puede continuar la selección tras
cerrar explícitamente el contexto anterior.

«Ver en COM» hace POST/CSRF a `/admin/soporte/com`: registra `stopped_for_com`,
elimina soporte y reautenticación y persiste la salida antes de abrir exclusivamente
`https://jakawi.com/admin/soporte?subject=<sub-guardado>`. Ignora URL o sub del POST;
COM comprueba su propia sesión y permiso. No se comparten credenciales ni sesiones.
También permite salir después de vencimiento o revocación, como la salida normal.

Despliegue puntual: `scripts/deploy-support-navigation.sh <paquete>`, con evidencia
en `/srv/sites/jakawi.club/support-navigation-evidence` y respaldo independiente
en `/srv/sites/jakawi.club/rollback-support-navigation`. Sin migraciones ni cambios
de grants, COM, CRM o infraestructura. Pruebas de soporte incluyen entrada guest,
retorno OIDC, selección, reutilización, cambio explícito, UUID inválido, CSRF real,
salida auditada a COM y bloqueo de escrituras. Navegador: 16 escenarios a 390/1440,
fixtures privados sin sesión real del propietario, con assets preview y LIVE.
