# Selector compacto de apariencia V2

Baseline CLUB: beab670d299c86856c492df03529f5ccbc37b43d.
Referencia COM inspeccionada: 52b6beae0d075a018cab607974a19cd496af73ac.

AccountTheme reemplaza el select visible por botón 44 px sol/luna/monitor y menú
radio compacto: selección activa, flechas/Home/End, búsqueda por inicial, Escape,
Tab, clic exterior y devolución de foco. Usa React y SVG existentes, sin nuevas
dependencias. Suscripción íntegra al appearanceStore existente; bootstrap, endpoint,
preferencias, identidad y persistencia no cambian. Las cabeceras público/auth,
cuenta/afiliados y Admin ya comparten el componente. CSS limitado al selector.

Pruebas: test-appearance.sh (50 verificaciones HTTP en esquema temporal eliminado),
build-frontend.sh (TypeScript y Vite secuenciales), check-appearance-menu.sh con
JAKAWI_EVIDENCE_DIR explícito. El navegador usa fixtures HTTP de esos tests, assets
reales y escrituras interceptadas; nunca crea cuentas LIVE. Verifica 390/1440,
modos, teclado, foco, persistencia, pestañas, sistema, formulario y video nativo.
La prueba de video comprueba conservación del nodo y reproducción/pausa; no es
paridad VSL ni modifica el reproductor/proveedor. --live usa los assets desplegados.

Release: fuente limpia/commit, package-source-release.sh a carpeta nueva bajo
/srv/workspaces/jakawi/club, deploy-appearance-menu.sh con evidencia PASS y baseline
exacto. No migraciones. Backup pg_dump validado, SHA previo y manifest. Chunks
inmutables primero, manifest al final, cachés route/view y reinicio solo app.
Compara esquema, usuarios con preferencias, datos comerciales/editoriales,
configuración mail/lanzamiento, credenciales y contenedores protegidos COM/CRM.
Conserva registro abierto y SMTP actuales. main avanza solo tras LIVE y navegador PASS.

Rollback: previous_sha en release.json del respaldo privado; git switch --detach
al SHA anterior en /opt/sites/jakawi.club, restaurar manifest.json del respaldo,
regenerar route:cache/view:cache y docker compose restart app. No restaurar DB,
no down, no borrar assets. Evidencia fuera de Git:
/srv/workspaces/jakawi/club/appearance-menu-v2-evidence.

El baseline operativo contiene un pin Nginx local en compose.yaml. El release
acepta exclusivamente ese diff verificado, respalda el archivo y exige hash
idéntico después; no lo incorpora al cambio UX ni recrea el contenedor web.
