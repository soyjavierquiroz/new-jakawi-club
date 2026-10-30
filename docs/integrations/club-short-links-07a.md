# Fase 07A — enlaces cortos permanentes

Piloto único: Dossis 43 — Café y antojo, oferta original
`https://jakawi.com/l/dossis-43-cafe-y-antojo`. No hay importación de catálogo.

`GET /r/{shortCode}` usa ocho caracteres alfanuméricos aleatorios criptográficos.
Los códigos CLUB de doce caracteres mayúsculos mantienen su ruta de invitación
al registro. No se muestran IDs secuenciales en los enlaces comerciales.

`commercial_offers` conserva clave estable, nombre, URL original, destino vigente
y estado. `affiliate_short_links` conserva código, afiliado existente, oferta y
código CLUB real. Las claves únicas de código y afiliado/oferta evitan duplicados,
incluso ante solicitudes simultáneas. No existe operación para reasignar enlaces.

- `active`: HTTP 302 al destino vigente, con un único `club_ref` que contiene
  exactamente el código CLUB del afiliado. Se conservan fragmentos y parámetros
  propios sin recodificar sus bytes; se eliminan atribuciones anteriores.
- `paused`: página pública «Esta oferta no está disponible por ahora».
- `finished`: página pública «Esta oferta ha finalizado».

Las páginas de aviso ofrecen explorar COM mediante una acción explícita.
No hay redirección automática a otra oferta. Código comercial inexistente: 404.
Todas estas respuestas son `no-store`, sin sesiones ni cookies. El access log
propio de Nginx omite los accesos comerciales `/r/`; las invitaciones de registro
mantienen su comportamiento. No se crean métricas de clics ni hechos comerciales.

Administración existente: `/admin/ofertas-comerciales`, con autenticación,
verificación, activación y permiso administrativo CLUB ya utilizado por las
landings. Permite consultar destinos y enlaces asociados, actualizar la URL de
la misma oferta y seleccionar estado. Los PUT tienen CSRF y límite de frecuencia.
Solo se admiten HTTPS y hosts exactos `jakawi.com` y `www.jakawi.com`, sin
credenciales ni puertos explícitos; se vuelve a validar al redirigir. Ningún
parámetro público puede elegir destino, afiliado, estado o código de atribución.

`/afiliados` reserva/reutiliza su enlace, con COPIAR ENLACE y COMPARTIR POR WHATSAPP;
el destino largo aparece como detalle secundario. La oferta finalizada permanece
en el historial con su mismo enlace. Las consultas económicas y de conversiones
conservan sus reglas y su alcance originales.

Operación de publicación: migración aditiva
`2026_10_30_000004_create_affiliate_short_links` y
`php artisan short-links:prepare-pilot`, idempotente y limitado a cuentas reales
activadas y verificadas. No crea cuentas ni eventos. Para afiliados posteriores,
el panel emite el enlace al abrirlo. El cambio de slug se gestiona actualizando
el destino de `dossis-43`, sin cambiar su clave ni sus enlaces. La sincronización
con COM queda para 07B.

Validación focalizada:

```bash
bash scripts/build-frontend.sh
bash scripts/test-short-links.sh
bash scripts/check-short-links-visual.sh
bash scripts/test-affiliates.sh
bash scripts/test-commercial.sh
bash scripts/test-commissions.sh
```

Pruebas HTTP y de concurrencia en un esquema PostgreSQL temporal eliminado al
terminar. Las páginas públicas se renderizan desde el kernel real con fixtures;
el navegador intercepta paneles y formularios, sin sesiones ni cambios LIVE.
No se modifica COM, CRM, SSO, captación, receptor comercial, reglas, libro ni pagos.

Respaldo privado de publicación: `/srv/sites/jakawi.club/rollback-short-links-07a/`.
Para recuperar código previo se conserva la migración aditiva y los enlaces;
no ejecutar `migrate:rollback` ni eliminar tablas que contienen URLs compartidas.
Restaurar los assets y cachés compatibles con ese código. Conservar el inode del
archivo Nginx montado, validar su configuración y recargar solo Nginx de CLUB.
Reiniciar únicamente PHP de CLUB para renovar OPcache.
