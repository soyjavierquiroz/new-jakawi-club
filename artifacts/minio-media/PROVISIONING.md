# Credencial privada exclusiva de CLUB

Bloqueo de activación: falta `MEDIA_AWS_ACCESS_KEY_ID` / `MEDIA_AWS_SECRET_ACCESS_KEY` exclusivos de CLUB. No existe un cliente administrativo MinIO configurado en este host. No reutilizar las claves S3 de COM.

El operador MinIO debe crear una credencial asociada exclusivamente a la política `club-minio-policy.json` de este directorio, limitada a `jakawi-media/club/*`, y entregarla por el canal privado en:

`/srv/sites/jakawi.club/secrets/media.env`, dueño root, modo 0600.

Ese archivo admite las dos variables secretas anteriores. La configuración no secreta ya está definida en `config/media.php` y `config/filesystems.php`; no hace falta incluir URLs manualmente en el editor.

La firma real de imgproxy se ha transferido directamente desde la configuración privada existente a `/srv/sites/jakawi.club/secrets/imgproxy.env`, modo 0600, sin copiar claves S3 de COM. No inventar una pareja de firma nueva.

Antes de activar, `scripts/check-media-storage.sh` exige claves exclusivas, comprueba que la credencial no puede listar la raíz del bucket, almacena exactamente un objeto aleatorio conocido en `club/tmp/`, verifica las tres imágenes públicas por imgproxy (OG JPEG 1200×630) y elimina ese mismo objeto en finally. No crea usuarios, landings ni objetos permanentes LIVE. No ejecutar el GC destructivo como prueba LIVE.

Si imgproxy no puede leer `club/*`, el operador del almacenamiento debe otorgar lectura de ese prefijo a la identidad existente de imgproxy. CLUB no cambia el servicio imgproxy ni COM.
