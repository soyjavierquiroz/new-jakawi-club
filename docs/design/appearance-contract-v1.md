# Contrato de apariencia JAKAWI V1 (COM / futuro CLUB)

Valores canónicos: `light`, `dark`, `system`. El selector muestra Claro, Oscuro y
Sistema, permite teclado y conserva la selección, sin alternar automáticamente
el valor `system` por el color resuelto.

## Resolución y persistencia

Cuenta autenticada: `users.theme_preference` es la autoridad al cargar cualquier
página, también pública. El endpoint COM existente `PATCH /settings/appearance`
valida los tres valores y modifica únicamente la cuenta de la sesión. No hay
migraciones. Los cambios se aplican inmediatamente; las peticiones se serializan
para conservar el orden. Un fallo HTTP o de red muestra un aviso de que no se
pudo guardar por cuenta. Se conserva la elección local durante esa sesión de UI;
un nuevo documento recupera la preferencia real del servidor.

Invitado: localStorage `appearance`, después cookie host-only `appearance`, después
`system`. Cuenta: los eventos entre pestañas usan `appearance:<user_id>` para no
aplicar la elección local de otra cuenta. La cuenta se recupera en otro navegador
al iniciar sesión. Entre dispositivos abiertos no hay push: la cuenta se vuelve
a resolver al cargar un documento. Inertia login/logout actualiza la identidad
del almacén del cliente. Si se bloquea localStorage, la cookie mantiene la elección
y no se garantiza sincronización inmediata entre pestañas.

`system` resuelve `prefers-color-scheme: dark` y escucha sus cambios. Un script
inline en el head resuelve la clase `.dark`, `color-scheme` y `data-appearance`
antes de CSS/pintura. React usa snapshots SSR estables y se suscribe a un almacén
externo; las vistas Blade reutilizan el mismo head y el mismo almacén.

## Tokens

La fuente COM es `app/resources/css/theme.css`; Tailwind 4, shadcn y los módulos
consumen esos tokens, incluyendo contenido Radix montado en el body.

| Familia | Significado |
| --- | --- |
| background / foreground | Fondo del documento y texto principal |
| surface / card, surface-raised, surface-muted | Contenedores, elevación y superficies secundarias |
| text-secondary / muted-foreground | Texto de apoyo; nunca usar colores decorativos para texto pequeño |
| border / border-strong / input / ring | Separadores, límites de controles y foco |
| popover / popover-foreground | Menús, selectores y diálogos, incluidos portales |
| sidebar-* | Navegación lateral, selección y límites |
| table / table-header / alert | Superficies de tabla, cabecera y avisos |
| primary / primary-foreground | Acción coral oscurecida con texto blanco |
| secondary / secondary-foreground | Acción secundaria sobre superficie neutra |
| brand / reward / success | Coral, amarillo y verde de identidad para fondos y decoración |
| action-text / success-text / warning-text / danger-text | Variantes legibles para texto de acción y estado |
| success-surface / warning-surface / danger-surface | Fondos de estado; acompañar con texto/icono |
| danger / destructive / info | Error destructivo e información |
| chart-1…chart-5 | Series coral, amarillo, verde, aviso e información |

Los alias `marketing-*` y `admin-*` apuntan a la misma paleta global. Los atributos
históricos de composición Marketing no fijan colores. Mi JAKAWI no define otra
paleta. Las utilidades de texto brand/success usan las variantes con contraste;
los fondos y decoraciones conservan el color de identidad. No añadir hex en TSX.

Contraste objetivo WCAG AA: 4.5:1 para texto normal, 3:1 para texto grande,
controles y foco. No comunicar estados sólo con color. Conservar foco visible,
texto legible en estados deshabilitados y etiquetas/leyendas para gráficos.
Imágenes, QR, fotografías, scrims y controles propios del VSL conservan sus
necesidades de contenido; no cambiar geometría ni estados del reproductor.

## Implementación independiente en CLUB

CLUB implementará sus propios tokens, almacenamiento y arranque, con idénticos
valores y semántica. COM no escribe cookies de dominio compartido, no consulta
CLUB y no agrega endpoints SSO. Para sincronizar después de SSO hará falta un
contrato autenticado y autorizado de preferencias por identidad común: fuente
de autoridad, versión/fecha, resolución de conflictos y transporte entre los
backends. No compartir localStorage ni cookies de sesión entre dominios.
