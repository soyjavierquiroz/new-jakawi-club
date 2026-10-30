# Catálogo comercial COM → CLUB — v1

`GET https://jakawi.com/api/catalog/v1/offers`

Lectura pública, sin credencial, cookies ni escritura de visitas, atribución u operaciones. COM conserva la autoridad del catálogo. No crea enlaces de afiliados ni modifica `club_ref` o el emisor comercial. No requiere cambios en CLUB para publicar el feed.

## Contrato

Ejemplo ilustrativo (los valores no son registros LIVE):

```json
{
  "data": [{
    "id": "benefit:123",
    "com_id": 123,
    "type": "benefit",
    "name": "Nombre público",
    "short_description": null,
    "image_url": "https://jakawi.com/storage/imagen-publica.jpg",
    "partners": [{"id": 10, "name": "Partner público"}],
    "cities": ["La Paz"],
    "category": "food",
    "landing_url": "https://jakawi.com/l/landing-publicada",
    "publication_status": "published",
    "landing_status": "PUBLISHED",
    "availability_status": "available",
    "updated_at": "2026-10-30T12:00:00.000000Z",
    "starts_at": null,
    "ends_at": null
  }],
  "meta": {"current_page": 1, "per_page": 50, "total": 1, "last_page": 1},
  "links": {"next": null, "prev": null}
}
```

- `id`: clave estable y global dentro de COM (`benefit:<PK>` o `experience:<PK>`). `com_id` es el PK numérico original; usarlo junto a `type`. No cambia por editar el título, slug o landing.
- `type`: `benefit` (beneficio), `experience` (experiencia).
- `partners`: un Partner en beneficios; todos los Partners publicados asociados en experiencias, sin duplicados por rol, ordenados por ID. No se inventa un Partner principal. Solo ID y nombre.
- `short_description`, `image_url` y `category`: valor real o `null`. Una sola URL pública de imagen, mediante el resolvedor de medios existente; no contiene imágenes embebidas ni rutas privadas. Respeta `media_mode` de la landing.
- `cities`: nombres reales, únicos y ordenados de sedes publicadas aplicables al beneficio o de sedes publicadas de sesiones `scheduled` de la experiencia. Usa nombre de ciudad vinculado o texto de la sede. `[]` si no hay ciudad comprobable; no inventa ciudad por Partner.
- `landing_url`: ruta real `/l/{slug}` de una presentación `PUBLISHED`. Selección determinista: alcance predeterminado `GUESTS`, luego `ALL`, luego otra landing publicada; menor ID en cada grupo. Una fila por oferta, aunque tenga varias landings. Las ediciones de slug o predeterminada pueden cambiar esta URL; el identificador de oferta permanece.
- `publication_status`: estado real de la oferta, `published`, `paused`, `archived`. `landing_status`: `PUBLISHED`.
- `starts_at` y `ends_at`: fechas reales de vigencia del beneficio, en ISO 8601 UTC o `null`. Se omiten en experiencias: ese modelo no tiene fechas globales y no se fabrican a partir de sus sesiones.
- `updated_at`: fecha real de edición de la oferta. No es un cursor de cambios agregados: cambios de Partner, landing, sesiones o paso del tiempo pueden cambiar la proyección sin editar esta fecha.

## Disponibilidad e historial

`availability_status` es una proyección pública del estado y calendario existentes:

| Tipo | Estados |
| --- | --- |
| Beneficio | `available`, `upcoming`, `expired`, `paused`, `archived`, `unavailable` |
| Experiencia | `scheduled`, `upcoming`, `ended`, `paused`, `archived`, `unavailable` |

`paused` y `archived` tienen prioridad. El beneficio publicado está `expired` si terminó su vigencia, `upcoming` si aún no comenzó y `available` si pasa `Benefit::isAvailable()`. Este estado no garantiza un canje para un usuario o sede concretos.

La experiencia con visibilidad `upcoming` está en preparación. `scheduled` significa que tiene una sesión programada futura según `upcomingSessions()`, **no autorización de reservas ni cupos disponibles**. `ended` requiere que todas sus sesiones programadas hayan terminado (todas deben tener fecha de fin conocida); sin sesión futura o finalización comprobable devuelve `unavailable`. Se mantienen las validaciones operativas actuales de COM.

Se exige una landing todavía `PUBLISHED` y al menos un Partner publicado. Se excluyen borradores, experiencias `hidden`, beneficios exclusivos de premios privados (`social_challenge_grant`), ofertas sin landing publicada y Partners no públicos. Las pausadas/archivadas requieren además `published_at` para acreditar publicación previa. Su enlace se conserva para historial; el estado informa que no deben promocionarse como disponibles, y la ruta existente puede dejar de servir según las reglas de publicación de COM. No se modifica esa ruta. Una landing retirada o un Partner privado dejan de exponerse: CLUB debe conservar sus enlaces históricos propios, sin interpretar la ausencia como borrado de su historial.

No se serializan modelos completos: se excluyen contactos privados, notas, revisión editorial, usuarios, PIN, precios, ahorros, comisiones, saldos, pagos, campañas y configuración de reserva. Solo se entrega contenido editorial ya autorizado para publicación.

## Paginación y sincronización

`?page=1&per_page=50`: páginas desde 1, tamaño predeterminado 50, mínimo 1, máximo 100; `page` máximo 1000000. Parámetros inválidos: HTTP 422 JSON. Solo GET/HEAD; las mutaciones no tienen ruta (405).

Seguir `links.next` hasta `null`. Orden fijo ascendente `(type, com_id)`: beneficios, luego experiencias, por PK. SQL `UNION ALL`, conteo y `LIMIT/OFFSET` reales; solo se hidratan las ofertas de la página. Relaciones y ciudades se consultan por lotes, sin N+1. Sin nuevas dependencias, tablas, migraciones ni CMS.

La respuesta usa `Cache-Control: no-store`. Ejecutar recorridos completos y hacer upsert por `id`; no usar `updated_at` para sincronización incremental. Cada recorrido refleja lecturas actuales, sin snapshot entre peticiones: si el catálogo cambia durante el recorrido, repetirlo para converger. No se promete historial de versiones ni tombstones privados.
