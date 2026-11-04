# WEBINAR V3 — métricas y titulares editables

Motor editorial reutilizable sobre `jakawi-club-landing-v3`. La configuración LIVE y sus textos comerciales se conservan; las métricas nuevas no se extraen de los títulos ni se publican automáticamente.

## A. Editor

En beneficios/oportunidades: activar métrica, valor, unidad (incluido %), título, descripción, condición comercial visible y estado editorial opcional; icono existente opcional. La condición es obligatoria cuando hay métrica. Preview inmediata con el mismo componente público y preview completa del último borrador guardado.

En titulares WEBINAR: seleccionar un fragmento y aplicar normal, bold, coral, underline-coral o amarillo-acento; quitar énfasis de una selección o de todo el campo. Hero conserva title/emphasis/ending independientes. Opportunity, prelanzamiento, pasos, beneficios, FAQ y cierre comparten render seguro. Los strings y las otras plantillas mantienen su comportamiento histórico.

## B. JSON definitivo

El contrato sigue siendo `jakawi-club-landing-v3`. Ver [contrato](../../docs/landings/JAKAWI-LANDING-V3-SCHEMA.md) y [ejemplo oficial completo](../../docs/landings/JAKAWI-WEBINAR-V3-EXAMPLE.json).

```json
{
  "metric": { "value": "30", "unit": "%" },
  "title": "Modalidad Pionero",
  "description": "Condiciones preferentes",
  "condition": "Requiere membresía Pionero de pago; propuesta pendiente de aprobación",
  "offer_status": "Propuesta futura"
}
```

Estos campos amplían `content.benefits.items[]`. `metric` admite null para quitarlo. `value` hasta 24 caracteres y `unit` hasta 16; condición/estado hasta 320. Sin metric se conserva la tarjeta histórica.

```json
{
  "title": "Los negocios necesitan clientes. JAKAWI los conecta.",
  "title_segments": [
    { "text": "Los negocios necesitan clientes. ", "style": "normal" },
    { "text": "JAKAWI los conecta.", "style": "coral" }
  ]
}
```

Fragmentos opcionales: 1–32, concatenación exacta del string. null quita énfasis. Sin HTML, CSS, clases ni scripts de usuario. Validación de claves, límites y estilos cerrados; espacios preservados en guardado HTTP. Cambiar texto desde el editor restablece el énfasis; un parche antiguo que cambia un string sin fragmentos elimina el énfasis obsoleto.

## C/D. Capturas de prueba

[Tarjetas 15/30/50 escritorio](cards-15-30-50-1440.png) · [móvil 390](cards-15-30-50-390.png).
[Titular destacado escritorio](headline-emphasis-1440.png) · [móvil](headline-emphasis-390.png).
[Preview de tarjeta en administrador](admin-card-390.png).

Contenido de prueba identificado en las tarjetas. No es oferta publicada ni evidencia de comisiones vigentes. Inspección del snapshot público: los porcentajes ya existentes se describen como modelos en preparación/previstos. La documentación económica PRELAUNCH-10E-v1 establece Bs 15/Bs 25/Bs 30. La preferencia del propietario por editar % se admite como presentación; no convierte las reglas fijas ni acredita otra tarifa. No se altera el motor económico.

## E. Validación

PostgreSQL en esquema temporal eliminado al finalizar; cuentas y sesiones sintéticas dentro de ese esquema. Import/export V3, strings anteriores, unidades alternativas, condición, estilos y entradas inválidas; guardado sin publicar y publicación explícita en las tres rutas. `published_configuration` sigue siendo la fuente común, sin componentes por proveedor. Las futuras rutas que reutilicen el resolver/renderer recibirán el mismo contenido; no se habilitan canales nuevos.

Regresión focalizada del editor: 584 comprobaciones PASS; nuevas pruebas focalizadas: 48 PASS. TypeScript y build PASS. Evidencia focalizada y visual en `tests.json`, `regression.json`, `build.json` y `browser.json`. Navegador 390/1440: tarjetas con/sin/mix de métricas, unidad Bs, condiciones visibles, selección/aplicación/retiro de énfasis, preview, mismas tres rutas, sin scroll interno/desbordamiento ni errores JS. Inspección visual manual de las capturas de tarjetas escritorio/móvil.

## F. Despliegue

Se permite desplegar únicamente código/assets CLUB después de validar. El script comprueba SHA base `0ce367e`, alcance de archivos, build y huellas de manifest; respalda código anterior/manifest y verifica configuración editorial, reglas, ledger, auditoría y migraciones. Solo reinicia CLUB app para OPcache; conserva contenedores ajenos, configuración y textos. No migra, no publica contenido ni modifica formularios, fechas, video, WhatsApp, Pixel/CAPI, conversiones, membresías, COM o CRM.

El SHA LIVE y resultado verificado se registran en `LIVE.json` local después de desplegar, sin autorreferencia en el commit de código. Respaldo/reversión: directorio operativo privado `/srv/sites/jakawi.club/rollback-editorial-metrics-release`.
