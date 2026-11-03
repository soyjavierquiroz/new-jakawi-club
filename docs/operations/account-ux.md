# UX de cuenta CLUB alineada con Mi JAKAWI

Rama `feat/account-ux-theme`, basada en LIVE `70ceb03dc45762d6c8853d2190481e67ae87fc5d`; sin fusionar main. Entrega limitada a `/afiliados`, `/mi-cuenta` y encabezado de cuenta. Montserrat, crema/tinta, tarjetas blancas/oscuras y CTA sólido `#D34035`; WhatsApp secundario con verde propio. Marketing y admin conservan su contexto visual.

## Referencia COM y persistencia

Consulta exclusivamente de lectura de `/srv/repos/jakawi.com`, SHA `c863327e3653ea15f8687dfa473325fde8a901de`: `app/resources/css/mi-jakawi.css`, `resources/js/hooks/use-appearance.tsx`, `resources/views/app.blade.php`, `ThemePreferenceController.php`, rutas de settings y contratos en `docs/integrations/`.

COM persiste `theme_preference` en el perfil autenticado mediante `PATCH /settings/appearance` (sesión y CSRF de COM), más localStorage `appearance` y cookie de su dominio. Los contratos internos disponibles de identidad/OIDC/membresía no exponen lectura/escritura de esa preferencia; su endpoint de sesión no constituye un contrato para CLUB. No se consume, modifica ni comparte la sesión COM.

CLUB conserva `light`, `dark` o `system` en localStorage propio `jakawi.club.appearance`. Persiste entre páginas, refrescos, cierre/reapertura y nuevos inicios de sesión **en el mismo navegador y dominio**, hasta borrar sus datos. No sincroniza con COM, otros navegadores ni dispositivos. Sin cookie nueva, endpoint, migración o infraestructura. Si el almacenamiento está bloqueado, el cambio sigue funcionando durante la página actual; no se promete persistencia.

Un script de head resuelve la selección antes del contenido y CSS; React mantiene la misma selección. Automático escucha cambios del dispositivo; una selección explícita tiene prioridad. Eventos storage mantienen coherencia entre pestañas. El ámbito claro/oscuro se activa solo en los componentes de cuenta y al entrar por HTTP a sus rutas.

## Datos y permisos

Resumen: total real de enlaces propios (consulta COUNT de lectura, independiente de paginación/filtros), referidos de `results.leads_captured`, adquisiciones verificadas de `results.memberships_activated` (activación COM confirmada; no se infiere una nueva primera adquisición económica), cantidad de comisiones aprobadas de `economics.approved`. Los importes y estados originales permanecen en Mis resultados. Sin ceros inventados si falta una cifra.

Se conservan las cuatro pestañas, filtros y rutas, códigos, destinos y atribución. Solo cambia la presentación de los controles. Tarjetas 16:9, dos columnas amplias en escritorio, una en móvil; copiar como CTA principal, WhatsApp secundario, y acción para salir de estados vacíos. El menú proviene del servidor con los mismos guards administrativos y de afiliación; se elimina la entrada redundante de membresía y el logout duplicado del contenido. Membresías, compras, identidad, COM/CRM y reglas económicas no cambian.

## Pruebas y operación

Evidencia privada: `/srv/sites/jakawi.club/account-ux-evidence/`.

- `bash scripts/build-frontend.sh`: TypeScript y Vite en secuencia, dependencias canónicas existentes.
- `JAKAWI_EVIDENCE_DIR=… bash scripts/test-affiliate-results.sh`: 65 checks del kernel HTTP/PostgreSQL aislado; esquema temporal eliminado. Runner actualizado a `/mi-cuenta` porque `/dashboard` ya es redirección, más la comprobación del total propio de enlaces.
- `JAKAWI_EVIDENCE_DIR=… bash scripts/check-account-ux.sh` y `--live`: claro/oscuro/automático, prioridad explícita, cambios del dispositivo, head antes de body, refresco, navegación Inertia, cookies de sesión eliminadas y cierre/reapertura real de Chromium con el mismo perfil; 390/1440, copiar URL exacta, WhatsApp, filtros y estado vacío. Sin cuentas LIVE ni peticiones comerciales. Capturas privadas con fixtures del kernel aislado, plan Pionero simulado y marca ilustrativa para imágenes; no representan cuentas reales ni inventario LIVE. Ocho capturas por ejecución.
- Las pruebas de navegador no reemplazan una sesión interactiva de un usuario real.

Despliegue explícitamente solicitado por el propietario: empaquetar el commit con `scripts/package-source-release.sh`, revisar y aplicar `scripts/deploy-account-ux.sh <paquete>`. Precondiciones: baseline LIVE exacto, checkout limpio, ancestro, alcance, hashes, 65 checks y navegador aprobado con reinicio. Publica chunks antes del manifest atómico, mantiene todos los chunks anteriores, cachea rutas/vistas y reinicia solo `app`. Sin migraciones ni cambios de entorno.

Rollback privado en `/srv/sites/jakawi.club/rollback-account-ux/{release,manifest}.json`: restaurar checkout detached al `previous_sha`, restaurar manifest atómicamente, `route:cache`, `view:cache`, reiniciar únicamente `app` y ejecutar `python3 scripts/validate-local.py`. No borrar chunks ni datos.

Después: validador local, navegador `--live`, `/up` loopback/HTTPS, SHA y manifest efectivos, comparación de hashes de tablas protegidas y contenedores. Sello final fuera de Git para evitar autorreferencia; no se publica ni fusiona main.
