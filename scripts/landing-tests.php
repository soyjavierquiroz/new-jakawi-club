<?php

// Focused integration checks using the real HTTP kernel and PostgreSQL, without PHPUnit/dev dependencies.
use App\Models\User;
use App\Notifications\ActivateAccount;
use App\Support\LaunchSettings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

chdir('/var/www/html');
foreach (['logs', 'framework/views', 'framework/sessions', 'framework/cache/data'] as $dir) {
    @mkdir('storage/'.$dir, 0777, true);
}
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.connections.pgsql.database') !== 'jakawi_club') {
    throw new RuntimeException('Test environment isolation required.');
}
$schema = 'landings_test_'.bin2hex(random_bytes(6));
$passed = [];
$browserCookies = [];

function check(bool $condition, string $name): void
{
    global $passed;
    if (! $condition) {
        throw new RuntimeException('FAILED: '.$name);
    }
    $passed[] = $name;
    echo 'PASS '.$name.PHP_EOL;
}

function http(string $method, string $path, array $data = [], bool $inertia = false): \Symfony\Component\HttpFoundation\Response
{
    global $app, $browserCookies;
    Auth::forgetGuards();
    $server = ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '192.0.2.10'];
    if ($inertia) {
        $server['HTTP_X_INERTIA'] = 'true';
        $server['HTTP_X_INERTIA_VERSION'] = (new App\Http\Middleware\HandleInertiaRequests)->version(Request::create('/'));
    }
    $request = Request::create('https://jakawi.club'.$path, $method, $data, $browserCookies, [], $server);
    $kernel = $app->make(Kernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() < time()) {
            unset($browserCookies[$cookie->getName()]);
        } else {
            $browserCookies[$cookie->getName()] = $cookie->getValue();
        }
    }
    foreach (app('cookie')->getQueuedCookies() as $cookie) {
        app('cookie')->unqueue($cookie->getName(), $cookie->getPath());
    }
    return $response;
}

function freshBrowser(): void
{
    global $browserCookies;
    $browserCookies = [];
    Auth::forgetGuards();
    app('session')->flush();
    app('session')->regenerate();
}

function payload(\Symfony\Component\HttpFoundation\Response $response): array
{
    return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
}

function registration(string $email, array $extra = []): array
{
    return array_merge(['name' => '  Persona   Prueba  ', 'email' => $email, 'phone' => '+591 (700) 000-00', 'privacy' => true], $extra);
}

try {
    DB::statement('CREATE SCHEMA '.$schema);
    config(['database.connections.pgsql.search_path' => $schema, 'jakawi.video_url' => null, 'jakawi.video_poster' => null, 'jakawi.registration_open' => false]);
    DB::purge();
    check(DB::selectOne('select current_schema() as name')->name === $schema, 'esquema aislado exclusivo de jakawi_club');
    Artisan::call('migrate', ['--force' => true]);
    $home = App\Models\MarketingLanding::where('slug', 'principal')->firstOrFail();
    check($home->isPublic() && $home->isHome(), 'portada inicial publicada desde configuración local');
    $page = payload(http('GET', '/', [], true));
    check($page['props']['landing']['id'] === $home->id && ! $page['props']['launch']['registration_open'], 'portada real configurada y registro cerrado');
    check(payload(http('GET', '/principal', [], true))['props']['landing']['content'] === $page['props']['landing']['content'], 'ruta pública usa el mismo renderer y snapshot');

    check(http('GET', '/l/principal?utm_source=historico')->getStatusCode() === 301 && str_ends_with(http('GET', '/l/principal?utm_source=historico')->headers->get('Location'), '/principal?utm_source=historico'), 'alias histórico 301 conserva query');
    check(http('GET', '/l/no-existe')->getStatusCode() === 404, 'alias inexistente no redirige');
    check(http('GET', '/login', [], true)->getStatusCode() === 200 && http('GET', '/registro')->getStatusCode() === 405 && http('GET', '/up')->getStatusCode() === 200, 'login registro y up conservan comportamiento');
    check(http('GET', '/r/no-existe')->getStatusCode() === 404, 'código fuera del namespace de referidos conserva 404 vigente');
    check(http('GET', '/r/ZZZZZZZZZZZZ')->isRedirect(), 'referido de 12 caracteres inexistente conserva redirección existente');
    $protected = [
        ['POST', '/admin/landings/import'],
        ['GET', '/admin/landings'], ['GET', '/admin/landings/crear'], ['GET', '/admin/landings/'.$home->id.'/editar'],
        ['GET', '/admin/landings/'.$home->id.'/preview'], ['GET', '/admin/landings/'.$home->id.'/preview/confirmado'], ['GET', '/admin/landings/'.$home->id.'/export'],
        ['POST', '/admin/landings'], ['PUT', '/admin/landings/'.$home->id],
    ];
    foreach (['import', 'publicar', 'duplicar', 'archivar'] as $action) $protected[] = ['POST', '/admin/landings/'.$home->id.'/'.$action];
    foreach ($protected as [$method, $url]) check(http($method, $url)->getStatusCode() === 401, 'invitado sin acceso: '.$method.' '.$url);
    $admin = new User(['name' => 'Editor aislado', 'email' => 'editor@example.test', 'password' => 'PasswordEditor123']);
    $admin->email_verified_at = now(); $admin->activated_at = now(); $admin->save();
    check(http('POST', '/login', ['email' => $admin->email, 'password' => 'PasswordEditor123'])->isRedirect(), 'login con autenticación existente');
    foreach ($protected as [$method, $url]) check(http($method, $url)->getStatusCode() === 403, 'miembro sin permiso: '.$method.' '.$url);
    check(Artisan::call('landings:admin', ['email' => $admin->email]) === 0, 'concesión explícita sobre cuenta existente');
    check(http('GET', '/admin/landings', [], true)->getStatusCode() === 200, 'administrador autorizado');
    check(http('GET', '/admin/landings/crear', [], true)->getStatusCode() === 200, 'formulario de creación protegido');
    $admin->refresh(); $admin->email_verified_at = null; $admin->save();
    check(http('GET', '/admin/landings')->getStatusCode() === 403, 'admin sin verificar bloqueado');
    $admin->email_verified_at = now(); $admin->activated_at = null; $admin->save();
    check(http('GET', '/admin/landings')->getStatusCode() === 403, 'admin sin activar bloqueado');
    $admin->activated_at = now(); $admin->save();

    $content = App\Support\LandingContent::defaults();
    $data = ['title' => 'Campaña de prueba', 'slug' => 'campana-prueba', 'campaign' => 'cocha_2026', 'configuration' => $content, 'status' => 'published', 'is_marketing_admin' => true];
    check(http('POST', '/admin/landings', $data)->isRedirect(), 'crear landing con JSONB válido');
    $landing = App\Models\MarketingLanding::where('slug', 'campana-prueba')->firstOrFail();
    $base = '/admin/landings/'.$landing->id;
    check($landing->status === 'draft' && $landing->published_configuration === null, 'crear no publica e ignora estado enviado');
    check(http('GET', '/campana-prueba')->getStatusCode() === 404, 'borrador invisible públicamente');
    check(http('POST', '/admin/landings', $data)->getStatusCode() === 422, 'slugs únicos');
    check(http('POST', '/admin/landings', [...$data, 'slug' => '../mal'])->getStatusCode() === 422, 'slug inválido rechazado');
    $preview = payload(http('GET', $base.'/preview', [], true));
    check($preview['component'] === 'Welcome' && $preview['props']['landing']['preview'] && ! $preview['props']['launch']['registration_open'], 'preview real protegido sin registro ni publicación');
    check(DB::table('marketing_landing_event_totals')->count() === 0, 'preview no genera conteos de servidor');
    $export = payload(http('GET', $base.'/export'));
    check($export == App\Support\LandingContent::envelope($content), 'exportación editorial completa v3');
    check(! isset($export['status'], $export['slug'], $export['campaign']), 'exportación excluye operaciones y campaña');
    $partial = ['schema' => App\Support\LandingContent::SCHEMA, 'content' => ['hero' => ['title' => 'Nuevo titular']]];
    $imported = http('POST', $base.'/import', ['editorial_json' => json_encode($partial), 'configuration' => $content]);
    check($imported->getStatusCode() === 200, 'importación parcial válida devuelve formulario local');
    check($landing->fresh()->revision === 1 && $landing->fresh()->configuration == $content, 'importar no persiste ni publica');
    $data['configuration'] = payload($imported)['configuration'];
    check(http('PUT', $base, [...$data, 'revision' => 1])->isRedirect(), 'guardado independiente tras importar');
    $landing->refresh();
    check($landing->configuration['hero']['title'] === 'Nuevo titular' && $landing->configuration['hero']['description'] === $content['hero']['description'] && $landing->configuration['benefits'] == $content['benefits'], 'importación parcial conserva todos los campos omitidos');
    check($landing->revision === 2 && $landing->status === 'draft', 'guardar importación no publica');
    $full = http('POST', '/admin/landings/import', ['editorial_json' => json_encode(App\Support\LandingContent::envelope($landing->configuration)), 'configuration' => $landing->configuration]);
    check($full->getStatusCode() === 200 && payload($full)['configuration'] == $landing->configuration, 'JSON completo conserva medios y datos existentes');
    foreach ([
        '{', '{"schema":"otra","content":{"hero":{"title":"x"}}}',
        json_encode([...$partial, 'status' => 'published']),
        json_encode([...$partial, 'content' => ['price' => 10]]),
        json_encode([...$partial, 'content' => ['hero' => ['endpoint' => '/admin']]]),
        json_encode([...$partial, 'content' => ['hero' => ['css' => 'display:none']]]),
        json_encode([...$partial, 'content' => ['hero' => ['title' => '<script>alert(1)</script>']]]),
        json_encode([...$partial, 'content' => ['hero' => ['title' => null]]]),
        json_encode([...$partial, 'schema' => App\Support\LandingContent::V2_SCHEMA, 'content' => ['video' => ['youtube_url' => 'https://www.youtube.com/watch?v=M7lc1UVf-VE']]]),
        json_encode([...$partial, 'campaign' => 'otra']),
        json_encode([...$partial, 'template' => 'OTRA']),
        json_encode([...$partial, 'schema' => App\Support\LandingContent::V2_SCHEMA, 'content' => ['presentation' => ['template' => 'WEBINAR']]]),
        json_encode([...$partial, 'content' => ['video' => ['poster' => '/marketing/../secret.png']]]),
        json_encode([...$partial, 'content' => ['benefits' => ['items' => [['title' => 'Incompleto']]]]]),
        json_encode([...$partial, 'content' => ['faq' => ['items' => ['x' => ['question' => 'a', 'answer' => 'b']]]]]),
    ] as $invalid) check(http('POST', $base.'/import', ['revision' => 2, 'editorial_json' => $invalid])->getStatusCode() === 422, 'JSON inválido o campo no permitido rechazado');
    check($landing->fresh()->revision === 2, 'JSON inválido no modifica el borrador');
    foreach (App\Support\LandingSlug::reserved() as $reserved) {
        check(http('POST', '/admin/landings', [...$data, 'slug' => $reserved])->getStatusCode() === 422, 'slug reservado: '.$reserved);
    }
    Illuminate\Support\Facades\Route::post('/futuro/endpoint', fn () => response('ok'));
    check(! App\Support\LandingSlug::allowed('futuro') && http('POST', '/admin/landings', [...$data, 'slug' => 'futuro'])->getStatusCode() === 422, 'futuro espacio de rutas reservado dinámicamente');
    check(http('POST', $base.'/publicar', ['revision' => 1])->getStatusCode() === 422, 'publicación rechaza revisión obsoleta');
    check(http('POST', $base.'/publicar', ['revision' => 2, 'configuration' => ['hero' => ['title' => 'No guardado']]])->isRedirect(), 'publicación usa exclusivamente el borrador persistido');
    $landing->refresh();
    $published = payload(http('GET', '/campana-prueba', [], true));
    check($published['props']['landing']['content']['hero']['title'] === 'Nuevo titular' && $landing->published_campaign === 'cocha_2026', 'publicación snapshot y asociación campaña');
    $data['configuration'] = $landing->configuration;
    $data['configuration']['hero']['title'] = 'Solo borrador';
    $data['configuration']['video']['youtube_url'] = 'https://youtu.be/M7lc1UVf-VE';
    $data['configuration']['video']['aspect_ratio'] = '16:9'; // legacy input normalizes to the fixed frame
    $data['configuration']['video']['focal_x'] = 0.2;
    $data['configuration']['video']['poster'] = '/marketing/poster-fixture.png';
    $data['configuration']['video']['focal_y'] = 0.8;
    $data['configuration']['confirmation_video']['youtube_url'] = 'https://youtu.be/M7lc1UVf-VE';
    $data['configuration']['video']['source_aspect_ratio'] = '3:4';
    $data['campaign'] = 'otra_campana';
    check(http('PUT', $base, [...$data, 'revision' => 3])->isRedirect(), 'editar y guardar');
    $landing->refresh();
    check($landing->configuration['video']['youtube_url'] === 'https://www.youtube.com/watch?v=M7lc1UVf-VE' && $landing->configuration['video']['poster'] === '/marketing/poster-fixture.png', 'YouTube normalizado independiente del poster y encuadre');
    check($landing->configuration['video']['aspect_ratio'] === '3:4' && $landing->configuration['video']['focal_x'] === 0.2, 'marco fijo y encuadre guardados desde controles');
    check(payload(http('GET', $base.'/preview/confirmado', [], true))['props']['confirmation']['video']['youtube_url'] === 'https://www.youtube.com/watch?v=M7lc1UVf-VE', 'preview real usa video de confirmación guardado');
    check(payload(http('GET', $base.'/preview', [], true))['props']['landing']['content']['hero']['title'] === 'Solo borrador', 'preview muestra último borrador');
    check(payload(http('GET', '/campana-prueba', [], true))['props']['landing']['content']['hero']['title'] === 'Nuevo titular', 'guardar no altera versión publicada');
    check(http('PUT', $base, [...$data, 'slug' => 'otro-slug', 'revision' => 4])->getStatusCode() === 422, 'slug publicado protegido');
    check(http('POST', '/medicion', ['event' => 'landing_visit', 'placement' => 'landing', 'landing_id' => $landing->id])->getStatusCode() === 204, 'analítica asociada a landing');
    http('POST', '/medicion', ['event' => 'landing_visit', 'placement' => 'landing', 'landing_id' => $landing->id]);
    check((int) DB::table('marketing_landing_event_totals')->where('landing_id', $landing->id)->where('campaign', 'cocha_2026')->sum('total') === 1, 'analítica deduplicada usa campaña publicada');
    check(http('POST', $base.'/duplicar')->isRedirect(), 'duplicar landing');
    $copy = App\Models\MarketingLanding::orderByDesc('id')->firstOrFail();
    check($copy->id !== $landing->id && $copy->slug !== $landing->slug && $copy->status === 'draft' && $copy->published_configuration === null && $copy->configuration === $landing->configuration, 'copia independiente sin estado público');
    check(http('POST', '/medicion', ['event' => 'landing_visit', 'placement' => 'landing', 'landing_id' => $copy->id])->getStatusCode() === 422, 'no se miden borradores');
    check(http('POST', $base.'/archivar', ['revision' => 4])->isRedirect(), 'archivar landing');
    check(http('GET', '/campana-prueba')->getStatusCode() === 404, 'archivada invisible públicamente');
    check(http('POST', $base.'/publicar', ['revision' => 5])->isRedirect(), 'republicar versión guardada');
    check(payload(http('GET', '/campana-prueba', [], true))['props']['landing']['content']['hero']['title'] === 'Solo borrador', 'republicación aplica cambios guardados');
    check(http('POST', '/admin/landings/'.$home->id.'/archivar', ['revision' => $home->revision])->getStatusCode() === 422, 'portada no puede archivarse');
    $homeContent = $home->configuration;
    $homeContent['hero']['title'] = 'Portada configurada';
    check(http('PUT', '/admin/landings/'.$home->id, ['title' => $home->title, 'slug' => 'principal', 'configuration' => $homeContent, 'revision' => $home->revision])->isRedirect(), 'guardar portada configurada');
    check(payload(http('GET', '/', [], true))['props']['landing']['content']['hero']['title'] !== 'Portada configurada', 'portada conserva versión pública al guardar');
    check(http('POST', '/admin/landings/'.$home->id.'/publicar', ['revision' => $home->fresh()->revision])->isRedirect(), 'publicar portada');
    check(payload(http('GET', '/', [], true))['props']['landing']['content'] === payload(http('GET', '/principal', [], true))['props']['landing']['content'], 'portada y principal idénticas después de publicar');
    check(payload(http('GET', '/', [], true))['props']['landing']['content']['hero']['title'] === 'Portada configurada', 'portada sirve configuración publicada local');
    foreach (App\Support\LandingContent::TEMPLATES as $template) {
        $next = $landing->fresh()->configuration;
        $next['presentation']['template'] = $template;
        $oldPublic = $landing->fresh()->published_configuration;
        check(http('PUT', $base, [...$data, 'configuration' => $next, 'revision' => $landing->fresh()->revision])->isRedirect(), 'guardar plantilla '.$template);
        check($landing->fresh()->published_configuration === $oldPublic, 'plantilla pública preservada hasta publicar '.$template);
        $rendered = payload(http('GET', $base.'/preview', [], true));
        check($rendered['props']['landing']['content']['presentation']['template'] === $template && $rendered['props']['landing']['content']['hero'] === $next['hero'], 'renderer y textos de plantilla '.$template);
        check(http('POST', $base.'/publicar', ['revision' => $landing->fresh()->revision])->isRedirect(), 'publicar plantilla '.$template);
        check(payload(http('GET', '/campana-prueba', [], true))['props']['landing']['content']['presentation']['template'] === $template, 'snapshot público de plantilla '.$template);
    }
    $fixtureContent = $landing->fresh()->configuration; $fixtureContent['presentation']['template'] = 'LEAD_VSL';
    http('PUT', $base, [...$data, 'configuration' => $fixtureContent, 'revision' => $landing->fresh()->revision]);
    $savedPublic = $landing->fresh()->published_configuration;
    $next = $landing->fresh()->configuration;
    $next['after_registration']['title'] = 'Gracias desde el editor';
    $next['next_step'] = ['action' => 'whatsapp', 'redirect_seconds' => 15];
    check(http('PUT', $base, [...$data, 'configuration' => $next, 'revision' => $landing->fresh()->revision])->isRedirect(), 'guardar posconversión con acción segura y contador');
    check($landing->fresh()->published_configuration === $savedPublic, 'guardar gracias no publica ni cambia snapshot');
    $confirmationPreview = payload(http('GET', $base.'/preview/confirmado', [], true));
    check($confirmationPreview['component'] === 'Registered' && $confirmationPreview['props']['confirmation']['content']['title'] === 'Gracias desde el editor' && $confirmationPreview['props']['confirmation']['preview'], 'preview de gracias usa borrador guardado y renderer real');
    $json = ['schema' => App\Support\LandingContent::SCHEMA, 'content' => ['after_registration' => ['title' => 'Gracias importadas']]];
    $import = payload(http('POST', $base.'/import', ['editorial_json' => json_encode($json), 'configuration' => $next]));
    check($import['configuration']['after_registration']['title'] === 'Gracias importadas' && $import['configuration']['next_step'] === $next['next_step'] && $landing->fresh()->configuration === $next && $landing->fresh()->published_configuration === $savedPublic, 'importación de gracias no persiste ni publica y conserva destino');
    $legacy = $home->configuration; unset($legacy['presentation']);
    check(App\Support\LandingContent::withPresentation($legacy)['presentation']['template'] === 'LEAD_VSL', 'snapshots anteriores compatibles sin migración');
    $originalHome = $home->fresh()->published_configuration;
    $publishedLegacy = $originalHome;
    unset($publishedLegacy['presentation'], $publishedLegacy['video']['source_type']);
    $publishedLegacy['video']['youtube_url'] = 'https://www.youtube.com/watch?v=P42gDOTL6Ys';
    $home->update(['published_configuration' => $publishedLegacy]);
    foreach (['/', '/principal'] as $route) {
        $liveProps = payload(http('GET', $route, [], true))['props'];
        check($liveProps['launch']['video_url'] === $publishedLegacy['video']['youtube_url'] && $liveProps['landing']['content']['presentation']['show_video'], 'URL VSL publicada heredada llega al renderer: '.$route);
    }
    check($home->fresh()->published_configuration === $publishedLegacy, 'normalización de lectura no reescribe el snapshot publicado');
    $publishedLegacy['presentation'] = ['show_video' => false];
    $home->update(['published_configuration' => $publishedLegacy]);
    $hiddenProps = payload(http('GET', '/principal', [], true))['props'];
    check(! $hiddenProps['landing']['content']['presentation']['show_video'] && $hiddenProps['launch']['video_url'] === $publishedLegacy['video']['youtube_url'], 'ocultar video conserva la URL publicada');
    $publishedLegacy['video']['youtube_url'] = null;
    $home->update(['published_configuration' => $publishedLegacy]);
    check(payload(http('GET', '/principal', [], true))['props']['launch']['video_url'] === null, 'ausencia explícita de video de campaña no recibe fallback de otra campaña');
    $home->update(['published_configuration' => $originalHome]);
    require '/tests/webinar-tests.php';
    require '/tests/campaigns-admin-tests.php';
    require '/tests/landing-json-v3-tests.php';
    file_put_contents('/evidence/browser-fixtures.json', json_encode([
        'invalid_import' => payload(http('POST', '/admin/landings/import', ['editorial_json' => json_encode(['schema' => App\Support\LandingContent::SCHEMA, 'content' => ['hero' => ['title' => null]]])])),
        'editor' => payload(http('GET', $base.'/editar', [], true)),
        'preview' => payload(http('GET', $base.'/preview', [], true)),
        'confirmation_preview' => $confirmationPreview,
        'create' => payload(http('GET', '/admin/landings/crear', [], true)),
        'index' => payload(http('GET', '/admin/landings', [], true)),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    check(Artisan::call('landings:admin', ['email' => $admin->email, '--revoke' => true]) === 0 && http('GET', '/admin/landings')->getStatusCode() === 403, 'revocación inmediata del permiso');
    freshBrowser();
    check(http('GET', '/r/'.$admin->referral_code)->isRedirect(), 'ruta de referido válido conserva redirección');
    check(http('POST', '/registro', registration('closed@example.test', ['landing_id' => $landing->id, 'is_marketing_admin' => true]))->getStatusCode() === 403, 'registro público cerrado aun con landing publicada');
    check(! User::where('email', 'closed@example.test')->exists(), 'ninguna cuenta creada por registro cerrado');
    $app->instance('env', 'production');
    check(http('POST', '/admin/landings', $data)->getStatusCode() === 419, 'CSRF en acciones administrativas');
    $app->instance('env', 'testing');
    echo json_encode(['passed' => count($passed), 'database' => 'isolated PostgreSQL schema', 'live_test_users' => 0], JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, ($e instanceof RuntimeException && str_starts_with($e->getMessage(), 'FAILED:') ? $e->getMessage() : 'ERROR '.get_class($e).' at line '.$e->getLine()).PHP_EOL);
    $failed = true;
} finally {
    DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');
}
exit(isset($failed) ? 1 : 0);
