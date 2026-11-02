# Fuente canónica CLUB y operación conservada

Consolidación inicial del 01/11/2026 (America/La_Paz), entonces sin despliegue, merge ni borrados. El cierre autorizado posterior se documenta al final.

- Fuente: `/srv/repos/jakawi.club`, clon `--no-local`, sin alternates ni dependencia del almacén Git LIVE.
- LIVE, `main`, `origin/main` y sello privado `artifacts/automatic-commissions/LIVE.json`: `b085d1f9f0be8bcaf97ab6a61be23fef0055f319` al inicio.
- Origin: `git@github-new-jakawi-club:soyjavierquiroz/new-jakawi-club.git` (alias SSH existente para el repositorio GitHub `soyjavierquiroz/new-jakawi-club`). No se copia configuración SSH ni credenciales.
- Las 26 ramas locales y tags de la copia original se preservan en la fuente. La rama operativa `ops/canonical-club-repository` añade únicamente estas instrucciones, herramientas de fuente y evidencia saneada.
- Git LIVE sigue independiente y sin cambios; sus antiguos worktrees dependen de `/opt/sites/jakawi.club/.git`. No retirar ese directorio ni mover worktrees históricos.
- No se copian working trees, archivos ignorados, `.env`, `vendor`, `node_modules`, assets LIVE ni archivos privados. La fuente contiene los archivos versionados y el historial Git existente; las dependencias de frontend se instalan desde el lockfile.

## Herramientas

`prepare-source-build.sh` instala frontend únicamente en la fuente o worktrees del área autorizada, con imagen fijada y límites existentes. `build-frontend.sh` usa esas dependencias, valida TypeScript y construye Vite secuencialmente en `build-next`. `package-source-release.sh` exige Git limpio, empaqueta el commit y los assets en un destino nuevo bajo `/srv/workspaces/jakawi/club`, e incluye hashes. No consume secretos ni aplica una entrega.

Los scripts de despliegue versionados conservan sus rutas LIVE, baselines, migraciones y acciones específicas; no se ejecutaron. Para una entrega futura se debe revisar el paquete, confirmar la autorización de despliegue, seleccionar solo los pasos aplicables y preservar la publicación atómica del manifest y chunks previos. No repetir `deploy-automatic-commissions.sh` como despliegue genérico: incluye una operación económica dirigida. `deploy-media.sh` aún referencia el worktree histórico minio-media; mantenerlo hasta revisar ese procedimiento en una entrega autorizada. No existe despliegue automático nuevo.

## Dependencias que permanecen

- Bind mounts app: `/opt/sites/jakawi.club/app` y `/opt/sites/jakawi.club/docs/landings`, ambos solo lectura; storage y bootstrap/cache en `/srv/sites/jakawi.club`.
- Bind mounts web: `app/public`, los dos archivos `docker/nginx/*.conf`, storage público y logs. PostgreSQL conserva `postgres/`, secretos y `docker/postgres/01-app-role.sh`; sin puertos publicados.
- `/srv/sites/jakawi.club/{secrets,postgres,storage,bootstrap-cache,nginx-logs}` y todos los `rollback-*`/`rollbacks` privados. Mantener chunks anteriores de `app/public/build`, vendor y node_modules LIVE para herramientas históricas que todavía los montan.
- `jakawi-club-crm.timer` → `/opt/sites/jakawi.club/scripts/deliver-crm.sh`; `jakawi-club-catalog.timer` → `/opt/sites/jakawi.club/scripts/sync-catalog.sh`, que incluye el scheduler. Unidades y horarios intactos.
- Imágenes locales PHP y Node fijadas, redes propias CLUB y conexión existente de web. Compose, configuración publicada, MinIO/imgproxy y CRM intactos; no se consultan ni modifican credenciales ni infraestructura de COM/CRM.
- `/root/jakawi-build-v1/repository`: referencia visual histórica documentada, ajena a esta consolidación; no tocar.

## Copias históricas

Inventario inicial limitado a worktrees registrados y directorios CLUB identificados. El cierre posterior autoriza retirar exclusivamente los candidatos comprobados sin cambios únicos ni dependencias, conservando previamente su evidencia ignorada. Las rutas retiradas se indican en la tabla. Las ramas no integradas pueden contener cambios equivalentes integrados por otra vía, pero se conserva su historia sin asumir equivalencia.

| Ruta | HEAD | Clasificación |
| --- | --- | --- |
| `/opt/sites/jakawi.club` | `b085d1f9f0be` | OPERATIVO: mantener íntegro |
| `/opt/sites/jakawi.club-10e` | `fd22c76a8a67` | RETIRADO en el cierre autorizado; rama y evidencias preservadas |
| `/opt/sites/jakawi.club/.worktrees/benefit-icons` | `792d398fca33` | CONSERVAR: 1 cambios locales pendientes de clasificar |
| `/opt/sites/jakawi.club/.worktrees/capture-cards` | `8f2f22c94688` | RETIRADO en el cierre autorizado; rama y evidencias preservadas |
| `/opt/sites/jakawi.club/.worktrees/landing-ai-kit` | `23105aab03a0` | CONSERVAR: 1 cambios locales pendientes de clasificar |
| `/opt/sites/jakawi.club/.worktrees/landing-json-v3` | `b36e8dcbaaf0` | CONSERVAR: historia no integrada |
| `/opt/sites/jakawi.club/.worktrees/landing-json-v3-release` | `e8d8184f8034` | CONSERVAR: 2 cambios locales pendientes de clasificar |
| `/opt/sites/jakawi.club/.worktrees/minio-media` | `86a3e8c7b2fe` | CONSERVAR: deploy-media.sh histórico lo referencia |
| `/opt/sites/jakawi.club/.worktrees/registration-copy` | `9964decaeb2a` | CONSERVAR: 1 cambios locales pendientes de clasificar |
| `/opt/sites/jakawi.club/.worktrees/webinar` | `1cefba008037` | CONSERVAR: historia no integrada |
| `/opt/sites/jakawi.club/.worktrees/webinar-hero` | `7bba7f40f618` | CONSERVAR: 3 cambios locales pendientes de clasificar |
| `/opt/sites/jakawi.club/.worktrees/webinar-mobile` | `ceafb8162988` | CONSERVAR: 3 cambios locales pendientes de clasificar |
| `/opt/sites/jakawi.club/.worktrees/webinar-numbers` | `b84a7ea8e85d` | RETIRADO en el cierre autorizado; rama y evidencias preservadas |
| `/opt/sites/jakawi.club/.worktrees/webinar-refinement` | `5e19216fbd06` | CONSERVAR: 3 cambios locales pendientes de clasificar |
| `/opt/sites/jakawi.club/.worktrees/webinar-release` | `c83f0196d59e` | RETIRADO en el cierre autorizado; rama y evidencias preservadas |
| `/opt/sites/jakawi.club/.worktrees/webinar-visual` | `2b4ef11570e0` | CONSERVAR: 3 cambios locales pendientes de clasificar |
| `/srv/sites/jakawi.club/phase11d-source` | `29c5f4517592` | RETIRADO en el cierre autorizado; rama y evidencias preservadas |
| `/srv/sites/jakawi.club/releases/affiliate-mvp` | `42f162812d8d` | CONSERVAR: 3 cambios locales pendientes de clasificar |
| `/srv/sites/jakawi.club/releases/commercial-phase05` | `e4e1c8dc4c84` | RETIRADO en el cierre autorizado; rama y evidencias preservadas |
| `/srv/sites/jakawi.club/releases/commissions-06c` | `164c5191ed3d` | CONSERVAR: 7 cambios locales pendientes de clasificar |
| `/srv/sites/jakawi.club/releases/commissions-06e` | `5926081a8174` | RETIRADO en el cierre autorizado; rama y evidencias preservadas |
| `/srv/sites/jakawi.club/releases/identity-phase04` | `668ba5a171dd` | RETIRADO en el cierre autorizado; rama y evidencias preservadas |
| `/srv/sites/jakawi.club/releases/partner-sso-10d` | `8741c076f9d1` | RETIRADO en el cierre autorizado; rama y evidencias preservadas |
| `/srv/sites/jakawi.club/releases/partners-10c` | `e1df6b10ce01` | RETIRADO en el cierre autorizado; rama y evidencias preservadas |
| `/srv/sites/jakawi.club/releases/short-links-07a` | `67fb6969abe8` | RETIRADO en el cierre autorizado; rama y evidencias preservadas |
| `/tmp/jakawi-club-auto` | `b085d1f9f0be` | RETIRADO en el cierre autorizado; rama y evidencias preservadas |

`/root/jakawi-club-direct-v1`: herramientas temporales, build, pruebas y evidence, sin repositorio propio en la raíz. Preservar todo hasta clasificar evidencia y referencias internas; no usar para trabajo futuro. `/root/jakawi-club-referral-v1` contiene evidence: conservar como evidencia, no fuente. No se revisó contenido privado ni se trasladaron esos directorios.

Inventario de estados locales y baseline de contenedores: `/srv/sites/jakawi.club/repository-consolidation/`, fuera de Git. No es una copia de datos ni secretos.

## Validación de la consolidación

`npm ci`, TypeScript y Vite completados secuencialmente en contenedores limitados desde la fuente canónica. `git fsck --full --no-reflogs` satisfactorio; tips de las 26 ramas y todos los tags iguales a los originales; árbol de `main` idéntico. `validate-local.py` pasó con baseline exclusivo de esta operación y evidencia privada. `/up` local y HTTPS: 200; `APP_DEBUG=false`, PostgreSQL sin puertos publicados, IDs/arranques/montajes de los tres contenedores iguales al inicio. SHA y manifest LIVE coinciden con el sello previo; Git LIVE limpio. Resumen saneado en `canonical-repository-validation.json`. No se ejecutaron migraciones, sincronización, reinicios ni despliegues.

## Cierre autorizado y limpieza controlada

Se integran en `main` exclusivamente los ajustes operativos de `ops/canonical-club-repository` y este registro de cierre; ninguna rama histórica se fusiona. El nuevo SHA Git se confirma tras publicar y puede consultarse con `git rev-parse origin/main` desde la fuente canónica. **El SHA LIVE sigue en `b085d1f9f0be8bcaf97ab6a61be23fef0055f319`**: no se actualiza la copia operativa ni se ejecutan deploys, migraciones o reinicios.

Se retiraron los 12 worktrees indicados en la tabla mediante `git worktree remove`, conservando todas las ramas y el almacén `.git` LIVE compartido. Antes se verificaron sus commits integrados, Git limpio, ausencia de montajes Docker/host, procesos, descriptores abiertos y referencias en scripts operativos/unidades. Los 150 archivos ignorados de evidencia se archivaron privadamente y se verificaron por SHA-256 byte a byte; las dependencias y builds descartados son regenerables desde los lockfiles. Código y evidencia versionados siguen accesibles por sus ramas e historial Git.

Los archivos privados están en `/srv/sites/jakawi.club/repository-consolidation/closure/preserved-evidence/`, con manifiestos por archivo; no entran en Git. Se conservan todos los rollbacks, evidencia necesaria, persistencia, secretos, PostgreSQL, dependencias LIVE, `minio-media`, worktrees con cambios locales o historia no integrada y las carpetas históricas de evidencia bajo `/root`.

Espacio retirado según `du`: 628,973,568 bytes; archivos privados conservados: 6,021,120 bytes. **Liberación neta estimada: 594.09 MiB** (622,952,448 bytes). Es una estimación por bloques asignados; excluye pequeños informes y metadatos Git.

La verificación posterior confirma los IDs, arranques, reinicios, montajes y manifest LIVE sin cambios, los tres contenedores saludables, Git LIVE limpio, unidades de scheduler/cola idénticas y ambos timers activos/habilitados. `/up` local y HTTPS: 200. No se modifican COM ni CRM. Resultado saneado, rutas y medición en `canonical-repository-closure.json`.

La fuente oficial sigue siendo `/srv/repos/jakawi.club`. Crear nuevos worktrees solo bajo `/srv/workspaces/jakawi/club`; no crearlos en `/opt/sites` ni `/root`.
