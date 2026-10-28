# JAKAWI Launch Engine

Este repositorio pertenece exclusivamente a `jakawi.club`.

- Código: `/opt/sites/jakawi.club`; persistencia y secretos: `/srv/sites/jakawi.club`.
- Respetar el stack Laravel 13, PHP 8.4, Inertia 3, React 19, TypeScript, Tailwind 4 y PostgreSQL 16.
- No modificar otros proyectos, sus contenedores ni configuración compartida.
- No publicar el proyecto ni conectarlo a Traefik sin una petición explícita posterior.
- Mantener `APP_DEBUG=false`, credenciales independientes y PostgreSQL sin puertos publicados.
- No imprimir secretos ni incluirlos en Git, logs de herramientas o imágenes.
- No instalar herramientas globales en este servidor multiproyecto. Usar los contenedores limitados de `scripts/`.
- Ejecutar instalaciones y builds en secuencia, con los límites establecidos. No ejecutar benchmarks ni suites extensas.
- Validación local: `python3 scripts/validate-local.py`; no necesita dependencias de desarrollo de PHP.
- Revisar `README.md` antes de operar el entorno.
