# Apariencia global V1 de CLUB

Referencia consultada directamente y conservada sin cambios en
`docs/design/appearance-contract-v1.md`: COM LIVE PASS
`e7269cad1f1c19f135efd4dcbb2d8c5c07a80cb4`.

La implementación anterior estaba limitada por `html[data-account-context]` a
Dashboard/Affiliates. El botón alternaba dos colores, guardaba únicamente
`jakawi.club.appearance`, no permitía volver a Sistema y no tenía autoridad por
cuenta. Marketing fijaba una paleta clara y Admin redefinía su propia acción.

Ahora la paleta depende exclusivamente del modo global, con aliases semánticos
para Marketing/Admin, superficies, inputs, diálogos, tablas, foco y estados.
La marca conserva negro, crema, coral, verde y amarillo. Textos de acción y
estado usan variantes de contraste. Las composiciones, copy, precios, enlaces,
orden de secciones y componentes del VSL permanecen en su implementación previa.
El selector nativo incluye Claro, Oscuro y Sistema y admite teclado.

## Cuenta e invitado

`users.theme_preference` es la autoridad al cargar documentos, incluso públicos.
`PATCH /settings/appearance` requiere sesión y CSRF, valida los tres valores y
actualiza sólo el usuario autenticado. La cabecera de identidad UUID rechaza
peticiones de otra cuenta con 409. Las peticiones del cliente se serializan.
Ante fallo se avisa y conserva la elección en esa UI; recargar recupera el servidor.
Las props de identidad y preferencia usan `Inertia::always` para las visitas parciales.

Invitados: localStorage `appearance`, cookie host-only `appearance`, `system`.
La cookie dura un año, usa Path=/, Secure y SameSite=Lax; no tiene Domain.
Las cuentas sólo publican eventos con `appearance:<uuid CLUB>`: el UUID estable
es el identificador local de usuario, aislado del almacenamiento de invitados.
Login/logout y cambio de identidad en Inertia cambian la autoridad del almacén.
No se migran preferencias históricas del navegador a cuentas.

El mismo resolver/almacén se incluye inline antes de CSS y pintura y actualiza
`.dark`, `color-scheme` y `data-appearance`. React se suscribe mediante
`useSyncExternalStore`, con snapshot SSR estable. Sistema escucha el media query.
Cambiar apariencia no navega ni remonta las páginas o el reproductor.

## Migración y rollback

Única migración: `2026_11_21_000000_add_theme_preference_to_users.php`, columna
varchar(6), no nula, default `system`. No altera `users.id bigint`, UUID, referentes,
consentimientos ni datos editoriales/económicos. No añade un sistema de preferencias.
El respaldo privado está bajo `/srv/sites/jakawi.club/rollback-global-appearance-v1`.
Se validó restaurándolo en PostgreSQL temporal y ejecutando el ALTER aditivo sobre
esa copia. No se vuelcan respaldos ni secretos a Git.

El release usa exclusivamente el flujo de fuente/worktree y paquete canónico de
CLUB: build local limitado, commit, `package-source-release.sh`,
`deploy-appearance.sh <paquete>`, chunks inmutables conservados, manifest al final,
cachés de rutas/vistas y reinicio sólo de app. El deploy exige PASS de pruebas,
matriz de navegador y video real, hash exacto del manifest y baseline LIVE revisado.
No ejecutar scripts históricos de otras entregas.

Rollback de código: recuperar `previous_sha` de `release.json` privado, cambiar
la copia operativa a ese SHA, restaurar `manifest.json`, regenerar route:cache y
view:cache y reiniciar sólo app. Conservar la columna aditiva y las preferencias;
no ejecutar down, no restaurar la base sobre datos posteriores al despliegue.

## Estado de lanzamiento observado

La inspección inicial de LIVE encontró registro abierto, MAIL_MAILER=smtp y correo
transaccional habilitado, contrario a cerrado/log en la solicitud. No se cambian
esas banderas durante implementación ni QA. La decisión sobre ese conflicto debe
estar resuelta antes del despliegue. El script de despliegue preparado conserva
el estado real observado y rechaza cualquier desviación del baseline; no cambia
secretos, correo, SSO, afiliados, publicación editorial ni infraestructura.

## Validación focalizada

`bash scripts/test-appearance.sh`: kernel HTTP y migraciones reales en esquema
PostgreSQL temporal; no crea usuarios LIVE ni envía correo/HTTP externo.
`bash scripts/build-frontend.sh`: TypeScript y Vite secuenciales.
`bash scripts/check-appearance.sh --matrix-only --390 --light` y variantes
390/1440, light/dark; `--behavior-only`; `--video-only`. Chromium aislado, assets
del build candidato e interceptación de escrituras. `aggregate-appearance.py`
comprueba que toda la evidencia corresponde al mismo build.
Capturas y resultados: `artifacts/global-appearance-v1`.

La sincronización futura requiere un contrato autenticado por identidad común:
autoridad, versión/fecha, conflictos y transporte entre backends. Esta entrega
no añade SSO, cookies compartidas, acceso a COM ni sincronización entre dominios.

## Límite de la prueba del proveedor

La prueba real aislada con el video de demostración de YouTube
`M7lc1UVf-VE` recibió código 150 y una verificación de bot en el navegador del
servidor. El reloj no avanzó en 390 ni 1440 px. No se declara PASS de reproducción,
no se sustituye el proveedor y no se intenta eludir esa verificación. El cambio de
apariencia conserva la instancia, fuente, silencio y geometría también en ese
estado de error. `video.json` y `video-390/1440.png` conservan la evidencia.
El script de release rechaza el despliegue mientras falte PASS real de video.
