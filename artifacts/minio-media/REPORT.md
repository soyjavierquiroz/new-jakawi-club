# Imágenes de landings CLUB en MinIO/imgproxy

Adaptación local del patrón real de COM (componente ImageUpload, controlador, MediaUploadService, MediaUrl y MediaGcCommand), consultado únicamente en lectura. No hay almacenamiento local de imágenes de producción ni nuevo servicio de imágenes.

`/media/uploads` exige sesión, correo verificado, permiso administrativo de landings, CSRF y límite de frecuencia. El navegador envía multipart; Laravel valida tamaño real, MIME, dimensiones (16.000 por lado y 24 MP), decodifica, corrige EXIF JPEG, reduce a 1.600 px y codifica WebP 82. Un lock existente de caché limita la decodificación concurrente a una imagen en el contenedor acotado. Los originales solo existen en el temporal PHP durante la petición.

Los temporales privados usan `club/tmp/{uuid}/master.webp`. Token cifrado, usuario propietario, expiración de 24 horas y namespace se verifican antes de promover a `club/landings/{id}/cover/{uuid}.webp`. Nunca se utiliza el nombre del archivo original. Fallos de S3/DB no borran imágenes anteriores; los huérfanos se recogen posteriormente.

El editor permite arrastrar, seleccionar, reemplazar y quitar; preview local inmediata, progreso, preview temporal de MinIO y errores legibles. El payload de borrador contiene token/indicador de retirada; las configuraciones persisten únicamente referencias estables. Guardar no cambia el snapshot público. Publicar fija la imagen y conserva el snapshot publicado previo en `marketing_landing_snapshots`; GC incluye borradores, publicados, archivados y snapshots históricos.

MediaUrl genera firmas compatibles con la instancia imgproxy existente: editor 480×270 WebP, catálogo 960×540 WebP y social 1200×630 JPEG. Las URLs son absolutas y sin expiración; no se publican blobs ni presigned URLs S3. Imágenes HTTPS/locales preexistentes se conservan como compatibilidad; el control principal ya no exige escribir URLs. Poster y fallback de marca conservan su prioridad posterior.

GC solo enumera `club/tmp` y `club/landings`, y sus marcadores privados viven en `club/gc`. Temporales vencen tras 24 horas; los objetos permanentes esperan 48 horas desde la primera observación sin referencia y desde su modificación. Una segunda consulta de referencias bajo bloqueo de tabla impide borrar una imagen reinsertada durante una operación del editor. Fallos de consulta impiden borrar. El timer existente de catálogo invoca el scheduler en el mismo proceso, sin otro worker, servicio ni contenedor programado.

Se añade el adaptador S3 de Flysystem, GD/EXIF al runtime PHP y límites HTTP de 12 MiB para admitir multipart con archivos de 10 MiB. La imagen PHP nueva se construye y prueba con otro tag antes de reemplazar el tag de runtime usado por los comandos existentes de CLUB; no se cambia el código de CRM ni sus colas.

La activación real exige las credenciales exclusivas indicadas en PROVISIONING.md y el smoke de un único objeto. Hasta completar esa comprobación no se declara upload LIVE ni URL imgproxy verificada.

Validación final aislada: 147 comprobaciones de medios/catálogo, 578 del editor y contratos históricos, TypeScript, Vite y Chromium a 390/1440, todas satisfactorias. Las pruebas de navegador interceptan todos los uploads y guardados; las URLs imgproxy de esos fixtures no acreditan acceso real. No se ha creado el objeto de smoke LIVE porque falta la credencial exclusiva.
