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
matriz de navegador y reproducción de un clip real en QA aislado, hash exacto del manifest y baseline LIVE revisado.
No ejecutar scripts históricos de otras entregas.

Rollback de código: recuperar `previous_sha` de `release.json` privado, cambiar
la copia operativa a ese SHA, restaurar `manifest.json`, regenerar route:cache y
view:cache y reiniciar sólo app. Conservar la columna aditiva y las preferencias;
no ejecutar down, no restaurar la base sobre datos posteriores al despliegue.

## Estado de lanzamiento observado

La inspección inicial de LIVE encontró registro abierto, MAIL_MAILER=smtp y correo
transaccional habilitado, contrario a cerrado/log en la solicitud. No se cambian
esas banderas durante implementación ni QA. El propietario aclaró después que se conservan exactamente esos tres valores
reales. El contexto cerrado/log anterior queda sustituido por esa aclaración. El script de despliegue preparado conserva
el estado real observado y rechaza cualquier desviación del baseline; no cambia
secretos, correo, SSO, afiliados, publicación editorial ni infraestructura.

## Validación focalizada

`bash scripts/test-appearance.sh`: kernel HTTP y migraciones reales en esquema
PostgreSQL temporal; no crea usuarios LIVE ni envía correo/HTTP externo.
`bash scripts/build-frontend.sh`: TypeScript y Vite secuenciales.
`bash scripts/check-appearance.sh --matrix-only --390 --light` y variantes
390/1440, light/dark; `--behavior-only`; `--video-local` para el clip real aislado
y `--video-only` para diagnóstico del proveedor externo. `--live` comprueba el
runtime publicado sin autenticación ni escrituras de cuenta/comerciales. Chromium aislado, assets
del build candidato e interceptación de escrituras. `aggregate-appearance.py`
comprueba que toda la evidencia corresponde al mismo build.
Capturas y resultados: `artifacts/global-appearance-v1`.

La sincronización futura requiere un contrato autenticado por identidad común:
autoridad, versión/fecha, conflictos y transporte entre backends. Esta entrega
no añade SSO, cookies compartidas, acceso a COM ni sincronización entre dominios.

## Video reproducible y límite del proveedor externo

El requisito de apariencia se valida con un clip WebM real de unos 12 segundos,
VP8 + Opus, guardado en `scripts/fixtures/appearance-video.webm`. Se grabó con
Canvas/AudioContext y MediaRecorder de Chromium, y se remultiplexó con el FFmpeg
ya instalado para conservar duración y pistas. Su hash queda en `video-local.json`.
El fixture de transporte emula sólo la API de YouTube en el navegador QA; el
reproductor, sus hooks y sus controles de aplicación son los originales.
No se simula el reloj: currentTime, fotogramas decodificados, pista de audio,
pausa y fin proceden de HTMLVideoElement. En 390 y 1440 px se verifican autoplay
silencioso, activar sonido, pausa, fin/replay y cambio de apariencia durante esos
estados sin remonte ni cambios de fuente o geometría. No se publica el clip ni
se añade soporte de otro proveedor a la aplicación.

La prueba externa de YouTube con `M7lc1UVf-VE` recibió código 150 y verificación
de bot, también observados en evidencia histórica previa de CLUB. No se declara
PASS del servicio YouTube ni se intenta eludir su verificación. `video.json` y
`video-390/1440.png` conservan ese diagnóstico separado. No es una modificación
ni una validación de disponibilidad del proveedor de producción.

El gate de release exige PASS de reproducción del clip real aislado y la matriz
de apariencia del mismo build. Conserva explícitamente el resultado externo
YouTube como no verificado. Además compara antes/después la configuración
efectiva completa de correo y lanzamiento mediante hashes privados, el archivo
de configuración/credenciales sin imprimirlo y las tablas de negocio protegidas.
No realiza envíos SMTP ni operaciones de prueba comerciales.
