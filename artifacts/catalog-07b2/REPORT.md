# Fase 07B2 · Catálogo comercial en CLUB

Desplegado exclusivamente en `jakawi.club`. Se sincronizan tres ofertas COM:
`benefit:1` (Dossis 43), `benefit:2` (El Re-Pollo) y `experience:5` (Pádel desde cero).
La landing principal publicada se incorpora como captación CLUB, mediante el
mecanismo real de invitaciones; las demás landings no se incluyen sin un
mecanismo válido. No se creó contenido editorial ni fixtures en LIVE.

`/afiliados` presenta Explorar, Mis enlaces e Historial. Búsqueda por nombre y
todos los Partners, filtros de tipo/ciudad/disponibilidad y páginas de 12 tarjetas.
Enlaces personales bajo demanda, copia, WhatsApp, accesos rápidos y resultados
históricos del afiliado. La privacidad y el cálculo original de conversiones y
comisiones se conservan. Administración en `/admin/ofertas-comerciales`, con
origen, sincronización, estado efectivo y pausa local restrictiva.

Dossis usa `benefit:1` manteniendo `dossis-43` como clave interna. `/r/Ms8xvfMr`
sigue siendo el mismo enlace. No se generaron enlaces masivos. Cambios de slug,
pausas, finalizaciones o retiros no cambian la identidad ni borran el historial.
Los enlaces detenidos muestran su oferta original; no redirigen a otras ofertas.

El servicio exclusivo `jakawi-club-catalog.service` fue ejecutado con resultado
`success`/0; su timer está habilitado/activo cada diez minutos. El contenedor
es efímero y limitado. Sin cursor `updated_at` ni solicitudes al feed por visita.
Solo se aplica un recorrido completo validado, en una transacción; los errores
conservan el catálogo válido previo. La vigencia se aplica también entre recorridos.

Validación completada:

- 75 comprobaciones de catálogo y enlaces en esquema PostgreSQL aislado, con
  conexiones Query Builder/Eloquent verificadas antes de toda escritura de fixtures.
- 65 comprobaciones de atribución existente, deduplicación, privacidad y paginación,
  también aisladas; sin correo, trabajos CRM ni solicitudes externas.
- TypeScript y Vite, secuenciales en contenedores limitados.
- Chromium 390/1440, con assets preview y LIVE: filtros, paginación, generación,
  copia real de portapapeles, WhatsApp, historial, comisiones, pausa administrativa
  y páginas informativas. Sin errores JS, assets fallidos ni desbordamientos.
  Datos privados interceptados desde las pruebas aisladas; cero escrituras LIVE.
- `scripts/validate-local.py` aprobado. Se actualizaron sus expectativas heredadas
  de registro cerrado/correo log/seis migraciones para comprobar la configuración
  vigente, sin cambiar registro, SES ni el entorno. El baseline se limita a CLUB.
- Migración aditiva, sincronización inicial, assets con chunks anteriores conservados,
  cachés renovadas y reinicio únicamente de `app`. Web y PostgreSQL sin reinicios.

[Sistema y rollback](../../docs/integrations/club-catalog-07b2.md).
El sello final `LIVE.json`, excluido de Git para evitar autorreferencia, compara
SHA local/remoto, archivos montados, assets HTTPS, `/up` público/local, enlaces,
tablas históricas, entorno y fuentes protegidas contra el baseline previo.
