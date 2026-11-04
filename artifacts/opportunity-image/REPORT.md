# RF-LANDING-OPP-IMAGE — CLUB

Una imagen opcional administrable dentro de la sección Oportunidad WEBINAR existente. Se reutilizan `ImageUpload`, GD, tokens cifrados, promoción MinIO, URLs imgproxy firmadas, referencias/snapshots y GC. Sin migraciones, dependencias, buckets ni servicios nuevos.

En `/admin/landings` → Contenido del webinar → Problema / oportunidad aparece **Imagen de apoyo**: selección/arrastre, preview inmediato, progreso/éxito/error, reemplazo, retirada lógica, texto alternativo y formato/peso/dimensiones. Editar textos conserva la imagen. Guardar mantiene el borrador; publicar es la única acción que cambia la vista pública. Quitar o reemplazar no borra físicamente recursos compartidos ni históricos. También permite retirar una referencia que dejó de estar disponible.

JPEG/PNG/WebP, MIME real y decodificación GD; máximo 5 MiB para este control. Pipeline existente hasta 1600 px de lado mayor, sin ampliar ni recortar, proporción original y EXIF JPEG. WebP calidad 82 con `imagealphablending(false)` y `imagesavealpha(true)`. Error controlado si WebP no está disponible. El preset público nuevo usa `rs:fit:1600:0:0` y `f:webp`, sin fondo/aplanado; no utiliza los presets comerciales con recorte/fondo/JPEG.

Contrato definitivo, conservando `jakawi-club-landing-v3`:

```json
{"content":{"webinar":{"opportunity":{"title":"Título","description":"Descripción","image":{"asset_id":"club/landings/ID/cover/UUID.webp","alt":"Texto alternativo","position":"right","fit":"contain"}}}}}
```

El identificador conceptual debe sustituirse por la clave real y única del recurso ya registrado por el pipeline existente. Se comprueba namespace CLUB, referencia legítima en landing/snapshot y existencia física. No se aceptan URL, base64, claves temporales ni identificadores inventados. Ausencia conserva compatibilidad y, al importar parcialmente, la imagen anterior; `null` retira la imagen. El exportador contiene solo `asset_id`, nunca la URL de entrega. El ejemplo descargable tiene `image:null` y sigue siendo importable sin recursos previos.

El renderizador común resuelve la URL desde la misma `published_configuration` para `/`, `/principal` y `/ads/meta/webinar`. No hay bloques ni imágenes por canal; cualquier futura ruta WEBINAR que use ese renderizador recibe el mismo comportamiento. No se habilitó TikTok ni se modificó publicidad, registro, atribución, confirmación, video o WhatsApp. El trabajo general de canales pendiente en `feat/landing-channel-rule` permanece intacto. Base de esta entrega: LIVE `6f83b5c`.

Diseño público: mismo fondo negro y tokens oficiales. Con imagen, dos columnas 55/45 a partir de 1024 px, centradas verticalmente; móvil coloca imagen debajo del texto. Sin imagen mantiene el markup y diseño anteriores, sin placeholder.

Validación:

- **60 PASS** del runner focalizado: 58 comprobaciones del requisito más dos de aislamiento/login. PNG y WebP con alfa, incluido resize 2000→1600; JPEG; SVG/MIME inválido; >5 MiB; invitados/miembros; tokens adulterados, ajenos, caducados y de otro propósito; ID inexistente/no autorizado; edición persistente; import/export V3 y JSON antiguo; preview; reemplazo/retirada; referencias compartidas y GC; publicación única actualizando las tres rutas.
- **635 PASS** de regresiones del editor, kit IA, contratos V1/V2/V3, webinar y copy, incluyendo la primera versión de las pruebas de imagen.
- TypeScript y Vite **PASS**, sin dependencias nuevas. Manifest verificado en `build.json` y `browser.json`.
- **14 PASS** de navegador a 390/1440: layout/ratio/orden, sin desbordamiento, tres canales visualmente iguales, ausencia de imagen, editor persistente, metadata, selección/arrastre, errores recuperables y retirada. Cero errores JavaScript/recursos.

Todo el almacenamiento de pruebas usa `Storage::fake('minio-media')` sobre tmpfs y un esquema PostgreSQL temporal eliminado. Navegador con respuestas de imagen y mutaciones interceptadas: acredita UI/renderizado, no una subida real al bucket o un roundtrip real por imgproxy. No se creó ninguna imagen de ejemplo en LIVE. La infraestructura MinIO/imgproxy existente y sus permisos se conservan.

Capturas locales con fixture transparente: [desktop 1440](opportunity-1440.png), [móvil 390](opportunity-390.png), [sin imagen desktop](opportunity-no-image-1440.png), [sin imagen móvil](opportunity-no-image-390.png).

Despliegue exclusivo de código/assets CLUB mediante `scripts/deploy-opportunity-image.sh`, condicionado a pruebas y manifest revisados, baseline LIVE exacto y Git limpio. Conserva chunks, respalda manifest/SHA, regenera cachés (incluido el preset media) y reinicia únicamente `jakawi-club-app`. Comprueba hash editorial idéntico antes/después y contenedores ajenos intactos. No publica borradores, ejecuta migraciones ni escribe media. Resultado/SHA en `LIVE.json`; ver [rollback](ROLLBACK.md).
