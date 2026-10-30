# WEBINAR: hero y encuadre

Base: `48b382f1b5920c884bed5c9ae6b7a71d2d7cbd21`.
Alcance: cuatro fuentes de renderer/CSS; sin cambios de player, proveedor,
contrato JSON, video de confirmación ni contenido de las landings.

- Desktop: override WEBINAR del estilo compacto, con texto a izquierda y video
  a derecha; marco completo y protagónico en la primera pantalla.
- Móvil: titular, descripción/CTA y video; tipografía/espacios compactos, sin la
  firma redundante previa al CTA. Sticky disponible también durante video, con
  ocultación conservada ante formulario, teclado, modal y fullscreen.
- Portrait YouTube en el hero WEBINAR utiliza el crop embebido existente incluso
  si el snapshot declara fuente 3:4. Los puntos focales y el marco 3:4 se conservan;
  la decisión es visual, sin reescribir metadatos ni afectar LEAD_VSL/WAITLIST.

PHP: **419 PASS** landings (incluidos v1/v2/v3 y kit), **134 PASS** registro y
confirmación, en esquemas temporales. El navegador añade comprobaciones de
geometría/CTA/crop/sticky con copy y metadatos públicos reales a 390/430/1440 y
regresión de otras plantillas. Respuestas de proveedor y mutaciones se interceptan.
No se declara reproducción real ni eliminación total del branding de YouTube.

Rollback exclusivo CLUB en `/srv/sites/jakawi.club/rollback-webinar-hero/`.
Despliegue habitual: fast-forward, assets con chunks anteriores conservados,
manifest al final, cachés y reinicio solo de app. Sin migraciones ni recreación.
`verify.py` comprueba datos/editorial/usuarios/secretos intactos, fuentes ajenas,
importación v3, kit, assets HTTPS, servicios, registro y páginas públicas.
El SHA final y las comprobaciones quedan en `LIVE.json` (no versionado).

TypeScript y build: PASS. Navegador previo: 23 grupos PASS.
`validate-local.py` encuentra el ID histórico anterior de app; la línea base de
esta entrega comprueba el contenedor vigente y la integridad del resto del stack.

Desplegado: `7bba7f40f61829983dbdb7534e9ae81fbf6dd5e2` (código).
Navegador con assets LIVE: **26 grupos PASS**, incluidos home/principal y login.
553 checks PHP, TypeScript y build aprobados. Solo app reiniciada, sin migraciones;
20 assets nuevos, conservados los chunks anteriores. No se modificó contenido
editorial, usuarios, configuración comercial ni secretos; cero registros/correos reales.
