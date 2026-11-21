# JAKAWI.CLUB — apariencia global V1

Implementación terminada en la rama `feat/global-appearance-v1`, basada en el
runtime real de CLUB y conservando sus entregas posteriores a GitHub main.
El estado final de publicación y los SHA se registran en `LIVE.json` y
`CANDIDATE.json`, fuera de Git para evitar autorreferencia.

1. **Causa anterior:** el CSS oscuro dependía de `data-account-context` y sólo
   alcanzaba Dashboard/Affiliates. Marketing fijaba Claro; Admin redefinía su
   acción. El botón binario no permitía seleccionar Sistema y sólo persistía en
   `jakawi.club.appearance`, sin preferencia de cuenta.
2. **Cobertura:** cabeceras pública/de cuenta, LEAD_VSL, WEBINAR, WAITLIST, login,
   recuperación, activación/restablecimiento, dashboard, referidos/afiliados,
   Admin, editor, preview, confirmación y checkout existentes. Tokens para
   superficies, inputs, botones, tablas, navegación, diálogos y estados. El
   banner de soporte también usa advertencias semánticas y tiene selector.
   No se cambian copy, precios, orden editorial ni implementación del VSL.
3. **Contrato:** copia literal consultada directamente del COM verificado en
   `e7269cad1f1c19f135efd4dcbb2d8c5c07a80cb4`, disponible en
   [appearance-contract-v1.md](../../docs/design/appearance-contract-v1.md).
   Valores `light`, `dark`, `system`; labels Claro, Oscuro, Sistema. Arranque
   inline antes de CSS, `.dark`, `color-scheme`, `data-appearance`, media query
   reactivo, almacén externo y snapshots estables. Sin módulos COM copiados.
4. **Persistencia:** autoridad `users.theme_preference` para cuentas, incluso
   sobre páginas públicas. PATCH autenticado/CSRF, validación canónica, cola de
   guardados, aviso de error y comprobación UUID contra peticiones obsoletas.
   Invitado: localStorage `appearance`, cookie host-only, Sistema. Eventos
   `appearance:<uuid CLUB>` para cuentas; identidad actualizada en Inertia.
   Verificadas recarga, navegación, dos pestañas nativas, cookie cuando se bloquea
   localStorage, login desde otro navegador e independencia entre cuentas.
5. **Migración preparada:** sólo
   `2026_11_21_000000_add_theme_preference_to_users.php`, varchar(6), default
   `system`. `users.id bigint` y UUID permanecen. **La aplicación efectiva se registra en `LIVE.json`.**
   Respaldo privado restaurado en PostgreSQL temporal; ALTER probado sobre esa
   copia LIVE. [Validación del respaldo](backup-validation.json).
6. **Capturas:** 390 y 1440 px, Claro y Oscuro, para las 15 superficies de la
   matriz; formularios de las tres landings, modal y error móvil adicionales.
   Ejemplos: [390 Claro](WEBINAR-390-light.png),
   [390 Oscuro](WEBINAR-390-dark.png),
   [1440 Claro](WEBINAR-1440-light.png),
   [1440 Oscuro](WEBINAR-1440-dark.png).
7. **Validación:** 50 comprobaciones del kernel/DB aislados; TypeScript y Vite
   PASS; 72 comprobaciones de apariencia y video aislado en Chromium PASS, sin overflow ni
   errores JS de la aplicación. Los pares de texto medidos superan 4.5:1;
   controles/foco superan 3:1. Evidencia: [tests](tests.json),
   [build](build.json), [navegador/contraste](browser.json).
   **VSL QA PASS con fuente reproducible real:** clip WebM VP8/Opus, reloj nativo,
   fotogramas decodificados y pista de audio. En 390/1440 se verificaron autoplay,
   sonido, pausa, fin/replay y cambios de tema, usando el reproductor original y
   un adaptador de transporte sólo en QA. [Resultado](video-local.json),
   [390](video-local-390-dark.png), [1440](video-local-1440-dark.png).
   **YouTube externo no verificado:** su prueba separada recibió código 150 y
   verificación de bot. [Diagnóstico](video.json). No se reparó ni sustituyó el
   proveedor productivo. Los dos resultados no se confunden.
8. **SHA:** GitHub main verificado
   `c755ffa438997e2a5c660f4b43915f3ffc6dd729`;
   baseline runtime LIVE real anterior al release,
   `35bf6d9b2f7a8aa1a55ec4d61451b2d1edacee68`.
   El SHA final del candidato se registra en `CANDIDATE.json` y en el paquete
   canónico, fuera de Git para evitar autorreferencia. HTTP `/up`, portada y
   login 200; bundle servido idéntico al archivo LIVE.
9. **Rollback:** preparado en
   `/srv/sites/jakawi.club/rollback-global-appearance-v1`, con SHA anterior,
   manifest y respaldo validado. El procedimiento canónico conserva chunks,
   publica manifest al final y reinicia sólo app. El rollback de código
   conservaría la columna aditiva; no restaura datos antiguos sobre producción.
   El release exige PASS del clip real aislado y las demás comprobaciones de apariencia.
10. **Identidad global futura:** requiere contrato autenticado de preferencias
    por identidad común, fuente de autoridad, versión/fecha, conflictos y
    transporte entre backends. No se añadió SSO ni sincronización entre dominios.

El estado real observado fue registro abierto, `MAIL_MAILER=smtp` y correo
transaccional habilitado. El propietario confirmó posteriormente conservar esos
valores reales y las credenciales vigentes; el contexto anterior cerrado/log queda
corregido. El deploy compara las banderas, toda la configuración efectiva de correo
y lanzamiento, el archivo de configuración/credenciales y los datos protegidos
antes/después. No envía correos de prueba ni ejecuta operaciones comerciales.

El release sigue el procedimiento canónico de CLUB. GitHub main sólo se actualiza
por fast-forward después de LIVE PASS, con rollback de código preparado y
conservación de la columna aditiva y de los datos posteriores al despliegue.
