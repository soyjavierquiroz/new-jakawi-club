# Iconos configurables de beneficios

Base LIVE: `36e58e31940de2b4537c727882781e1a0013e4aa`.

WEBINAR muestra los iconos sobre círculos de `var(--reward)` (#FFC83D), con
`var(--ink)` (#111111). Desaparece la excepción de color de la tarjeta central.
`benefits.items[].icon` es opcional en el contrato v3, con diez nombres cerrados:
play, users, gift, compass, calendar, star, heart, arrow-right, lightbulb y megaphone.
Se reutiliza geometría SVG estática Lucide 0.475.0 y la licencia local existente;
no se incorporan dependencias ni se interpretan componentes procedentes de JSON.

El editor reutiliza su select y los campos de cada beneficio. «Automático» elimina
la propiedad; añadir un beneficio no introduce un icono vacío. Sin selección se
conservan exactamente los SVG anteriores por posición. LEAD_VSL y WAITLIST mantienen
sus números, incluso si el documento contiene iconos para una futura plantilla.
Importación, exportación, guardado, publicación y previews conservan los valores;
se rechazan nombres ajenos, código, cadenas vacías, null y tipos no textuales.

Contrato, ejemplo y prompt maestro oficiales de «Crear con IA» actualizados, junto
con el brief generado por el editor. El ejemplo solo añade tres propiedades icon;
no cambia ningún texto ni otro ajuste. Las descargas y el ZIP mantienen su control
de acceso y utilizan los mismos archivos oficiales.

Verificación previa: 455 comprobaciones PHP en un esquema PostgreSQL temporal,
con notificaciones falsas y ninguna cuenta LIVE; TypeScript y Vite PASS. La suite
incluye compatibilidad v1/v2/v3, las tres plantillas, guardado/preview/publicación
independientes, roundtrip y valores inseguros. Los tests de navegador interceptan
mutaciones y proveedores: verifican los diez SVG, selección de las tres tarjetas,
colores exactos, fallback, edición, exportación y las plantillas conservadas.
27 grupos Chromium previos PASS a 390/430/1440 px, con comprobaciones específicas
de iconos a 390/1440 en captación y preview. Capturas revisadas en ambas anchuras.
No se acredita reproducción real de proveedores de video.

Integración por fast-forward, assets anteriores conservados y nuevo manifest al
final. Solo se reinicia app para renovar OPcache; no hay migraciones ni cambios de
infraestructura, registro, videos o CRM. El validador local admite opcionalmente
JAKAWI_BASELINE_PATH para comparar el baseline vigente de esta entrega, conservando
su ruta anterior como valor predeterminado.

Rollback privado de código/assets/cachés en
`/srv/sites/jakawi.club/rollback-benefit-icons`. Revertir el commit funcional con
Git, restaurar únicamente el manifest de ese backup y reiniciar solo app; los
chunks previos permanecen disponibles. No revertir tablas ni modificar secretos.
El verificador `scripts/verify-benefit-icons-live.py` contrasta Git/origin/main,
archivos montados, tablas protegidas y snapshots, secretos, contenedores, importación
y validación real de PHP, kit/ZIP, assets HTTPS y resultados de Chromium LIVE.
El sello final `LIVE.json` está excluido de Git para evitar autorreferencia.
