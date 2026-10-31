# Fase 10C — beneficiarios individuales y Partners

CLUB consume el contrato COM Fase 10B (`artifacts/partners-phase10b/CONTRACT.md`
en COM, contrastado con su código desplegado), sin modificar COM ni CRM.

Las asignaciones manuales de representantes descritas abajo son históricas y
quedan sin efecto de autorización desde [Fase 10D](club-partner-sso-10d.md).
COM decide los permisos económicos vigentes mediante SSO.

## Identidad, atribución y hechos

`affiliate_partners` es una referencia comercial mínima por `jakawi.com` + ID
estable COM. No es un establecimiento migrado, una cuenta CLUB ni su propietario.
`/admin/partners-afiliados` habilita/deshabilita esas referencias y autoriza/revoca
representantes por UUID CLUB. No hay permisos por correo ni por ser empleado.
El administrador debe comprobar la identidad y autorización comercial antes de
conceder consulta. La autorización no transfiere el beneficio económico.

`com_origin` verifica el formato, propósito `jakawi-com-origin-v1`, HMAC existente,
source, audiencia y fechas exactas. Carta y checkout conservan el candidato en
sesión. Registro y primera compra fijan el beneficiario durable; las intenciones
reenviadas incluyen token y código CLUB original validado. Un referente local ya
fijado prevalece; código explícito válido precede al Partner predeterminado.
Un candidato CLUB inválido permite exclusivamente el fallback Partner firmado.

El receptor HMAC conserva raw_body y los acuses 201/200/409. Acepta exactamente
los tres eventos nuevos: `member.registered`, `membership.attribution_fixed` y
`membership.attribution_identity_linked`. Conserva los contratos existentes de
`membership.activated` y `membership.identity_linked`.

Los alias de persona usan UUID CLUB, sujeto global autorizado, ID de usuario COM,
referencia de pago y referencia de compra. No usan correos. Un lead COM no crea
usuario CLUB, mensaje, notificación ni tarea CRM. Las compras CLUB mantienen su
registro público explícito existente. Se retiró la recuperación de compras
invitadas por coincidencia de correo, incluso verificado; el acceso exige sesión
de compra, titular original o identidad global vinculada.

Los hechos permiten activar antes de recibir atribución, vincular más tarde y
recuperar duplicados. Las activaciones se deduplican por referencia de membresía;
los paneles cuentan personas, de modo que renovaciones y enlaces de identidad no
suman nuevas captaciones. Al unir identidades se preserva el referente original
del usuario; una valoración incompatible se conserva y bloquea para revisión.

## Panel y enlaces

`/afiliados` muestra al representante autorizado sus negocios, leads, activaciones,
tasa, enlaces y comisiones. La actividad personal permanece en sección separada.
Los enlaces empresariales permanentes `/a/{code}/carta`, `/checkout` y `/com`
usan las rutas COM publicadas del Partner. COM emite la procedencia firmada al
cruzar a CLUB; su página orgánica ya fija procedencia sin enlace corto adicional.
Los enlaces cortos históricos de beneficios no cambian ni originan nuevas
captaciones de membresía en CLUB.

## Economía

Mismo `commission_ledger`, auditoría, versiones, corte económico y aprobación manual.
Las reglas distinguen INDIVIDUAL/PARTNER. Para `membership.activated`, `partner_id`
en el formulario de reglas representa ID beneficiario (UUID CLUB para INDIVIDUAL,
ID COM para PARTNER), o `*` para la escala de ese tipo. La regla específica precede
a la general; luego se elige la versión efectiva más reciente. Los canjes anteriores
conservan su alcance por Partner de oferta e INDIVIDUAL.

Solo una activación auténtica con regla activa aplicable crea asiento de captación.
Sin regla no hay asiento. Una persona no gana otra comisión por renovar. La
referencia de oferta no se usa como beneficiario. Importe, moneda, tipo e identidad
quedan congelados. Las discrepancias de identidad bloquean aprobación; nunca se
transfiere una valoración histórica automáticamente. No hay pagos automáticos,
reglas LIVE nuevas, porcentajes ni importes predeterminados.

Al habilitar un Partner se reprocesan los hechos ya recibidos, sin inventar altas
históricas. Las reglas conservan su fecha efectiva y sus snapshots. Para rollback,
restaurar código/manifest anterior; no eliminar tablas ni revertir datos económicos.

## Validación y despliegue

`bash scripts/test-partners.sh`: HTTP/kernel y PostgreSQL en esquema temporal,
contrato COM, referentes, invitados, orden, duplicados, autorización, escalas y
aprobación manual. Fixtures económicos únicamente aislados.
Regresiones: checkout público, resultados y comisiones existentes.
`bash scripts/build-frontend.sh`; `bash scripts/check-partners.sh` y `--live`:
390/1440 con Chromium existente, props HTTP aisladas y navegación interceptada.
No crean usuarios ni compras LIVE. Evidencia en `artifacts/partners-10c`.

Despliegue: `scripts/deploy-partners.sh`. Prepara rollback privado, aplica solo la
migración aditiva, publica main y assets verificados, regenera cachés y reinicia
exclusivamente app CLUB. Verifica `/up`, SHA, assets, producción y referencias.
