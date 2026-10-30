# WEBINAR MVP — implementación aislada

Trabajo en la rama `feat/webinar-mvp`, en `/opt/sites/jakawi.club/.worktrees/webinar`.
No se aplican cambios a la copia montada en producción. Sin despliegue, push, publicación
CMS, migraciones, instalaciones, correos reales, cuentas LIVE ni cambios de secretos.

## Inspección focalizada

Reutilizados: `LandingContent` y su contrato v2/v1, configuración JSONB y snapshots de
`MarketingLanding`, editor Inertia con pestañas de captación/confirmación, previews del
borrador guardado, controles editoriales, `MarketingHero`, beneficios, FAQ, CTA,
`MobileStickyCTA`, `RegistrationForm`, `JakawiVslPlayer` y marco 3:4.

`WelcomeController` conserva referente y UTM por sesión; `RegistrationController`
conserva la atribución inicial, origen/campaña publicados, conversión transaccional,
cola FluentCRM, activación mediante el servicio de correo existente y recibo de 15 minutos.
La confirmación sigue usando `Registered`, video/grupo fijados en el recibo y enlaces
personales sujetos a activación. No se modifican esos servicios ni sus contratos.

Faltaban fecha por landing, countdown, bloques de problema/oportunidad, participación
temprana y pasos, y controles de orden/visibilidad. El WEBINAR anterior solo adaptaba
hero, beneficios y la fecha global. No existía un mecanismo para ordenar secciones;
se añadieron botones de subir/bajar en la presentación existente, sin drag-and-drop.

## Implementación y compatibilidad

- `presentation.webinar` es opcional: título de evento, fecha confirmada y lista fija
  de nueve secciones, con orden y visibilidad. No es un nuevo motor de contenido.
- `content.webinar` añade textos de oportunidad, participación temprana y tres pasos.
  Se incluye opcionalmente en el mismo JSON editorial v2; los parches parciales siguen
  conservando campos omitidos. Fecha, orden, visibilidad, grupos y medios quedan fuera
  de importación/exportación editorial.
- La composición completa solo se usa en WEBINAR cuando está habilitada explícitamente.
  LEAD_VSL, WAITLIST y WEBINAR históricos conservan su renderer. No se normalizan ni
  reescriben sus snapshots para habilitar esta composición.
- Hero con promesa, referencia al webinar y video opcional 3:4; evento; primeros tres
  beneficios; problema/oportunidad; participación temprana; tres pasos; registro;
  FAQ y cierre. Se pueden ocultar y ordenar todos los bloques salvo ocultar el registro,
  que siempre conserva un destino válido para los CTA.
- Todos los CTA WEBINAR, incluido submit y sticky, comparten el texto del Hero y apuntan
  al único formulario. Se conservan el foco accesible y las protecciones móviles del sticky.
- Sin fecha: «Próximo webinar en vivo», sin countdown y sin heredar la fecha global.
  Fecha introducida en hora de Bolivia, almacenada ISO 8601 con `-04:00`, mostrada mediante
  `America/La_Paz` en formato de 24 horas. Countdown calculado desde el reloj actual hasta
  ese instante fijo; al vencer se retira y no afirma que el webinar esté transmitiendo.
- Formulario Laravel existente, datos conservados ante validación recuperable y bloqueo
  inmediato del doble envío, además del estado `processing` y la unicidad del backend.
- Analítica existente de visita, clic de CTA, inicio real del player y conversión del
  servidor. No se amplía el vocabulario ni se emiten eventos desde previews.
- El contenido inicial se carga solo mediante un botón explícito en el formulario de
  creación. No se crea ninguna landing de ejemplo en la base real ni se sobrescribe la portada.
- Sin escasez, inscritos, cupos, testimonios, fechas inventadas ni cambios de proveedor.

## Crear una landing tras un despliegue autorizado

1. Abrir `/admin/landings`, crear una landing con nombre y slug nuevos.
2. En «Presentación / plantilla», elegir WEBINAR y pulsar «Usar contenido inicial WEBINAR».
   Es una acción sobre el formulario, todavía sin guardar. En una landing existente,
   «Habilitar composición WEBINAR» conserva los textos y medios principales.
3. Editar Mensaje, Beneficios, Contenido del webinar, Registro, FAQ y Cierre. En presentación
   configurar el texto común de CTA, orden, visibilidad y fecha únicamente si está confirmada.
4. Configurar YouTube y encuadre en «Video y medios». El player conserva su marco 3:4.
5. Revisar «Página de confirmación»: textos, video y grupo de WhatsApp. Sin grupo efectivo,
   el acceso a WhatsApp permanece oculto; solo aparece después del registro aceptado.
6. «Guardar borrador» y revisar la preview a 390, 430 y desktop. Guardar no publica.
   «Publicar cambios» promueve únicamente el borrador guardado; requiere que no haya
   cambios sin guardar. Esta entrega no ejecutó esa operación en LIVE.

## Validación

Resultados: `bash scripts/test-webinar.sh landings` **302 PASS**, incluyendo contratos v1/v2,
plantillas históricas, orden/visibilidad, fecha y aislamiento borrador/publicación;
`bash scripts/test-webinar.sh postconversion` **134 PASS**, incluyendo registro WEBINAR,
referente inicial, UTM, activación simulada, confirmación y snapshots del recibo;
`bash scripts/build-frontend.sh` **TypeScript y Vite PASS**, solo `public/build-next`;
`bash scripts/check-webinar-visual.sh` **12 grupos PASS**, a 390, 430 y 1440 px,
CTA/formulario, sticky, video 3:4, fecha Bolivia, countdown activo/vencido/ausente,
editor, exportación y conservación de datos/doble envío. `git diff --check`: PASS.
No existe un script de lint separado en package.json.

`python3 scripts/validate-local.py` ejecutado sobre el entorno vigente: **FAIL** en
la línea 52, al exigir `registration_open === false` cuando el registro está abierto.
Es una expectativa histórica del validador, no una prueba contra este worktree.
No se alteró el estado del registro ni el validador. No se ejecutó despliegue para
forzar una validación de la implementación en LIVE.

Se actualizaron expectativas heredadas de pruebas, sin cambiar las rutas o los
controladores de confirmación: `/gracias` es el destino canónico actual y
`/confirmado` sigue probado como alias. Las pruebas de campañas ahora exigen ausencia
de WhatsApp en captación y conservan sus verificaciones de grupo en confirmación.

Evidencia: [resultados](tests.json), [navegador](browser-results.json),
[captura móvil](webinar-390.png), [player desktop](webinar-video-1440.png). Los runners PHP usan
esquemas PostgreSQL temporales, dependencias existentes de solo lectura y notificaciones
simuladas. El navegador intercepta toda mutación y usa `build-next`; nunca envía altas LIVE.

## Límites y riesgos

- El código permanece en el worktree: la funcionalidad aún no está disponible en el
  administrador de producción. Aplicarla y renovar assets/OPcache requiere despliegue posterior.
- Sin agenda recurrente, automatización de invitaciones, integración nueva de Zoom ni
  garantía de emisión del webinar. Fecha y contenidos requieren confirmación editorial.
- El reloj del visitante determina el countdown; se usa un instante fijo sin reinicios
  ni sincronización adicional del reloj con un nuevo endpoint.
- YouTube se comprueba mediante render y proveedor simulado, no reproducción real.
  La restricción histórica de inserción del video aprobado no se resuelve en este cambio.
- Chromium cubre 390/430/1440 px; no se acredita prueba física en iPhone o Android.
- Activación y cola CRM se reutilizan: no se acredita entrega real SES ni sincronización
  externa FluentCRM mediante estas pruebas aisladas.
- El validador local heredado exige registro cerrado y se detiene ante la apertura real
  documentada del entorno. No se cambia el guard ni la configuración para satisfacerlo.

La rama parte de `891f662`. Durante el trabajo `main` avanzó con cambios económicos
ajenos a esta entrega. Para integrar después, aplicar este commit sobre `main`
vigente y reconstruir assets; no reemplazar la copia de producción por el worktree
ni copiar sus chunks como un despliegue de todo el sitio. No se incorporaron ni
modificaron esos cambios económicos.

## Archivos modificados o añadidos

- `.gitignore`
- `README.md`
- `app/app/Http/Controllers/LandingAdminController.php`
- `app/app/Support/LandingContent.php`
- `app/resources/js/components/forms/RegistrationForm.tsx`
- `app/resources/js/components/marketing/MobileStickyCTA.tsx`
- `app/resources/js/components/marketing/WebinarComposition.tsx`
- `app/resources/js/components/marketing/primitives.tsx`
- `app/resources/js/lib/landing-editorial.ts`
- `app/resources/js/pages/Welcome.tsx`
- `app/resources/js/pages/admin/LandingEditor.tsx`
- `app/resources/js/types.ts`
- `scripts/campaigns-admin-tests.php`
- `scripts/campaigns-receipt-tests.php`
- `scripts/check-webinar-visual.mjs`
- `scripts/check-webinar-visual.sh`
- `scripts/landing-tests.php`
- `scripts/postconversion-tests.php`
- `scripts/test-webinar.sh`
- `scripts/webinar-tests.php`

Se añaden además este informe, `tests.json`, `browser-results.json` y las capturas
en `artifacts/webinar/`. Los fixtures de sesiones/cuentas de prueba están excluidos de Git.
