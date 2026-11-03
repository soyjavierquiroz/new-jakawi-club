# Header de cuenta CLUB como COM

Cambio limitado a `AccountHeader`, `AccountTheme` y estilos del header; contenido de afiliados/cuenta, backend y permisos intactos. Rama `fix/account-header-com`, baseline LIVE `782168769b247e277da8b311d93e076f2426ea0c`.

Referencia COM consultada en solo lectura: `c863327e3653ea15f8687dfa473325fde8a901de`, `consumer-layout.tsx`, `theme-toggle.tsx` y `use-appearance.tsx`. COM usa barra de 64 px desktop / 56 px móvil (más borde), logo de 36 px desktop y botón circular de 44 px con icono de 20 px. El botón muestra la acción siguiente: sol para cambiar a claro, luna para cambiar a oscuro, alternando según el tema efectivo. Se reproduce ese comportamiento y el hover/focus, bordes y tipografía con tokens CLUB.

Una sola fila: logo, enlaces alineados a la derecha y tema al final. Medición de los enlaces con ResizeObserver y fuentes cargadas; lo que no cabe se mueve a Más sin segunda fila. Móvil conserva un desplegable compacto con botón de icono; Escape, clic fuera y navegación lo cierran. URLs y enlaces autorizados proceden de las mismas props del servidor; solo se abrevian etiquetas. Logout mantiene POST de Inertia.

Se conserva localStorage `jakawi.club.appearance`, incluidos valores previos `light`, `dark`, `system`. Automático sigue siendo el valor por defecto; el botón fija claro/oscuro, como COM. Sin select visible, migraciones, cookies ni cambios en el bootstrap previo al pintado. Sin sincronización entre dominios.

Validación mínima: TypeScript/Vite secuenciales, `scripts/check-header-com.sh` y `--live` con fixtures privados existentes y sin sesiones/cuentas LIVE: 1440/390, icono y automático, refresco y navegación, enlaces normales/admin, Más al reducir espacio y Escape. Cuatro capturas recortadas al header por ejecución. Sin suite comercial ni auditoría general.

Despliegue explícitamente solicitado: `package-source-release.sh` y `deploy-header-com.sh <paquete>`, con baseline, hashes, alcance y navegador verificados. Assets antes de manifest atómico, chunks previos conservados; rutas/vistas y reinicio solo app. Evidencia privada en `/srv/sites/jakawi.club/header-com-evidence`; respaldo `/srv/sites/jakawi.club/rollback-header-com`. Rollback: checkout detached al previous_sha del respaldo, restaurar manifest atómicamente, cachear rutas/vistas y reiniciar solo app.

Validador posterior: `JAKAWI_BASELINE_PATH=/srv/sites/jakawi.club/header-com-evidence/baseline.json JAKAWI_EVIDENCE_DIR=/srv/sites/jakawi.club/header-com-evidence python3 scripts/validate-local.py`. Sello final privado sin autorreferencia Git.
