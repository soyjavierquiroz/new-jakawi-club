# Copy y CTA del formulario por landing

Se amplía `content.registration` del motor existente con nueve campos opcionales:
`form_title`, `form_subtitle`, `name_placeholder`, `phone_placeholder`,
`email_placeholder`, `submit_label`, `submit_microcopy`, `existing_account_label`
y `existing_account_link_label`. Los controles están en **Registro**, sin otra
pestaña ni otro formulario. El subtítulo interno es independiente de la descripción
exterior. Se conserva el mismo componente público/preview.

El CTA explícito se usa en el formulario, los botones hacia registro y el sticky.
Sin él, WEBINAR usa `hero.cta`; LEAD_VSL y WAITLIST mantienen el CTA histórico.
Omisión en parches conserva el dato; null o texto vacío/solo espacios restablece
el CTA automático. Los defaults de snapshots históricos se agregan en memoria.
Los auxiliares vacíos no se muestran. El enlace de acceso conserva `/login`.

Solo textos planos, hasta 1200 caracteres, sin HTML ni URLs; no se admite cambiar
nombres técnicos, destinos, validaciones ni consentimiento. El formulario real,
CSRF, E.164, privacidad, doble envío, referido, UTM, activación y confirmación se
conservan. No hay migraciones ni nuevas dependencias.

Importación, exportación, validación, parches y preview reutilizan el contrato
existente. v1/v2 y v3 anteriores son compatibles. Contrato v3, ejemplo WEBINAR,
prompt maestro y brief actualizados. Crear con IA descarga los mismos documentos
y ZIP autorizados desde el montaje existente.

Validación previa: 577 comprobaciones de landings/editor/JSON/kit; 73 de registro;
134 de posregistro/atribución y 21 comprobaciones focalizadas del contrato:
805 PASS, en esquemas PostgreSQL temporales y notificaciones falsas, sin altas
LIVE. TypeScript y Vite PASS. Chromium: 32 grupos PASS con assets previos a
publicación, incluidos nueve campos, edición/exportación/render, defaults de las
tres plantillas y equivalencia público/preview a 390/430/1440 px. Capturas de 390 y
1440 inspeccionadas. Las mutaciones del navegador se interceptan; no se acredita
entrega SMTP ni reproducción de proveedores de video.

Despliegue exclusivo de CLUB: integración por fast-forward, copia de chunks
conservando los anteriores y publicación del manifest al final; reinicio solo de
app para OPcache. Sin modificar automáticamente filas editoriales ni snapshots,
secretos, COM, CRM, SSO o infraestructura compartida. Repetir Chromium con --live,
validar con scripts/validate-local.py y sellar Git/origin, montaje PHP/documentos,
kit/ZIP, HTTPS/assets, tablas protegidas y contenedores con
scripts/verify-registration-copy-live.py. LIVE.json se ignora para evitar
referencias al propio SHA.

Rollback privado en `/srv/sites/jakawi.club/rollback-registration-copy`: revertir
el commit funcional mediante Git, restaurar únicamente su manifest previo y
reiniciar app. Los chunks antiguos siguen disponibles. No revertir tablas,
secretos ni infraestructura. Sin incidencias funcionales abiertas en la validación
previa.

Despliegue completado con código funcional `9964decaeb2a15bba432784abf88d45e772d66be`:
30 assets copiados conservando los anteriores y manifest publicado al final.
35 grupos de Chromium con assets HTTPS LIVE PASS, incluidos los nueve campos,
editor/export/render, las tres plantillas y home/principal con registro abierto.
Validación local PASS; app/web/db saludables; IDs de contenedores intactos y web/db
sin reinicio. Contrastados antes/después: todas las filas y snapshots de landings,
las nueve tablas protegidas, secretos y fuentes ajenas a la entrega sin cambios.
Sin altas LIVE, publicaciones editoriales automáticas ni correos reales.
Sin incidencias funcionales nuevas. El SHA final de Git/LIVE se sella en LIVE.json.
