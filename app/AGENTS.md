# Aplicación JAKAWI Launch Engine

La configuración inicial de Laravel y Boost ya está completa. Aplicar las restricciones de `../AGENTS.md` y el procedimiento de `../README.md`.

- PHP 8.4 y Laravel 13, con tipos explícitos y controladores para las rutas web.
- Inertia 3 conecta `WelcomeController` con React 19 en `resources/js/pages/`.
- TypeScript estricto; Tailwind CSS 4 mediante Vite. No agregar autenticación, referidos o pagos sin una solicitud posterior.
- Composer, Artisan y Node se ejecutan en los contenedores propios y con los límites de `../scripts/`.
- Las dependencias de desarrollo de PHP no se instalan en la aplicación en ejecución. La configuración real se inyecta desde `/srv/sites/jakawi.club/secrets/app.env`.
- Verificar cambios con TypeScript, build y comprobaciones locales apropiadas; no ejecutar pruebas extensas ni benchmarks en este servidor.
