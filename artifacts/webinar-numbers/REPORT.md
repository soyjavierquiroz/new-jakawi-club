# Corrección de indicadores numéricos WEBINAR

Entrega específica de los números 01/02/03 de participación temprana y cómo
funciona. Evidencia nueva, tomada de `/` y `/principal`, sin fixtures de contenido.

React los renderiza en `WebinarComposition.tsx` mediante `.benefit-number`.
La regla genérica usaba `--brand-subtle` (rosado); la variante
`.webinar-steps .benefit-number` lo sobrescribía con `--cream`.

Único archivo de aplicación cambiado: `app/resources/css/app.css`.
Se añade `main[data-landing-template=WEBINAR] .benefit-number` con fondo
`var(--reward)` y texto `var(--ink)`, y se retira el fondo de la variante de pasos.
No se cambian React, JSON, textos, iconos Lucide, tamaños, radios, disposición,
bordes ni estructura de tarjetas. El selector limita la corrección a WEBINAR.

TypeScript y build secuenciales PASS. Chromium previo: 24 indicadores comprobados
en las dos páginas reales a 390/1440 px. Ambos grupos muestran exactamente
`rgb(255, 200, 61)` de fondo y `rgb(17, 17, 17)` de texto. Las dimensiones de números
y tarjetas se comparan con mediciones nuevas anteriores al cambio y coinciden.
Los tests bloquean métricas, mutaciones y la carga del proveedor de video.

Rollback propio: `/srv/sites/jakawi.club/rollback-webinar-numbers`, con código y
assets previos. Despliegue conservando chunks anteriores y publicando manifest al
final; solo app se reinicia. La validación final usa ese baseline nuevo para
proteger contenido publicado, fuentes ajenas, secretos y contenedores web/DB.
El sello final se genera en `LIVE.json` excluido de Git.
