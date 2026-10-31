# Configuración privada de imágenes CLUB

El propietario autorizó TEMPORALMENTE reutilizar las credenciales MinIO existentes de COM, el mismo bucket y la firma real de imgproxy. No se crean usuarios, políticas ni buckets. No se modifican COM, imgproxy ni Traefik.

Las credenciales se transfieren directamente en el servidor, sin mostrarlas, a `/srv/sites/jakawi.club/secrets/media.env`, dueño root, modo 0600. Incluye MEDIA_AWS_ACCESS_KEY_ID y MEDIA_AWS_SECRET_ACCESS_KEY, el endpoint existente, región us-east-1, bucket jakawi-media y path-style. La firma real reside en `/srv/sites/jakawi.club/secrets/imgproxy.env`, modo 0600. Ambos archivos están fuera de Git.

El aislamiento operativo se aplica a los objetos `club/tmp/*`, `club/landings/*` y marcadores `club/gc/*`; no se afirma aislamiento IAM con esta credencial compartida. Cada aplicación conserva su propio GC.

`scripts/check-media-storage.sh` valida la configuración autorizada, almacena un único objeto conocido en `club/tmp/`, verifica que sigue privado y comprueba las tres imágenes públicas por imgproxy, incluida social JPEG 1200×630. El objeto se elimina en finally. No crea cuentas ni landings LIVE. No ejecutar esta prueba repetidamente durante el mismo despliegue; conservar su evidencia.

Si aparece AccessDenied al escribir bajo club/* o al servir mediante imgproxy, se informa ese bloqueo sin modificar políticas globales.
