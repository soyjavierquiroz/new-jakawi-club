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
$schema = 'mvp_test_'.bin2hex(random_bytes(6));
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
    config(['database.connections.pgsql.search_path' => $schema]);
    DB::purge();
    check(DB::selectOne('select current_schema() as name')->name === $schema, 'aislamiento de PostgreSQL');

    // Exercise the additive migration against an existing legacy account, not just an empty table.
    Artisan::call('migrate', ['--force' => true, '--path' => 'database/migrations/0001_01_01_000000_create_users_table.php']);
    $legacyId = DB::table('users')->insertGetId(['name' => 'Legacy', 'email' => ' LEGACY@example.test ', 'password' => Hash::make('PasswordLegacy123'), 'email_verified_at' => now()]);
    Artisan::call('migrate', ['--force' => true]);
    $legacy = User::findOrFail($legacyId);
    check($legacy->email === 'legacy@example.test' && $legacy->uuid && $legacy->referral_code && $legacy->activated_at, 'migración compatible con usuarios existentes');

    Notification::fake();
    config(['jakawi.registration_open' => false, 'jakawi.transactional_mail_ready' => false]);
    $before = User::count();
    check(http('POST', '/registro', registration('closed@example.test'))->getStatusCode() === 403 && User::count() === $before, 'registro público cerrado');
    config(['jakawi.registration_open' => true, 'jakawi.transactional_mail_ready' => true, 'mail.default' => 'log']);
    check(! LaunchSettings::registrationOpen() && http('POST', '/registro', registration('log@example.test'))->getStatusCode() === 403, 'correo log impide apertura incluso con banderas activas');
    http('POST', '/recuperar-password', ['email' => $legacy->email]);
    check(Notification::sent($legacy, ResetPassword::class)->isEmpty(), 'correo log no escribe enlaces ni tokens');

    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'equipo@jakawi.club']);
    check(LaunchSettings::registrationOpen(), 'apertura exige SMTP y habilitación explícita');
    check(http('POST', '/registro', registration('invalid@example.test', ['privacy' => false]))->getStatusCode() === 422, 'consentimiento obligatorio');
    check(http('POST', '/registro', registration('invalid@example.test', ['phone' => '70000000']))->getStatusCode() === 422, 'validación de teléfono internacional');
    app('cache')->flush();

    $first = User::create(['name' => 'Referente Uno', 'email' => 'first@example.test', 'phone' => '+59170000001']);
    $second = User::create(['name' => 'Referente Dos', 'email' => 'second@example.test', 'phone' => '+59170000002']);
    $response = http('GET', '/r/'.$first->referral_code);
    check($response->isRedirect() && ! isset($GLOBALS['browserCookies']['jakawi_referral']), 'sin consentimiento no hay cookie de atribución de 30 días');
    http('GET', '/r/'.$second->referral_code);
    http('GET', '/r/INVALID');
    $page = payload(http('GET', '/', [], true));
    check($page['props']['referral_code'] === $first->referral_code, 'primer referente válido persistente');
    $response = http('POST', '/preferencias', ['allow_referral_cookie' => true]);
    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'jakawi_referral');
    check($cookie && $cookie->isSecure() && $cookie->isHttpOnly() && abs($cookie->getExpiresTime() - time() - 30 * 86400) < 5, 'cookie opt-in cifrada Secure HttpOnly de 30 días');
    unset($browserCookies[config('session.cookie')]);
    app('session')->flush();
    app('session')->save();
    check(payload(http('GET', '/', [], true))['props']['referral_code'] === $first->referral_code, 'cookie restaura el primer referente en una sesión nueva');

    $response = http('POST', '/registro', registration(' NEW@EXAMPLE.TEST ', ['referral_code' => $second->referral_code]));
    $new = User::where('email', 'new@example.test')->first();
    check($response->isRedirect() && $new && $new->name === 'Persona Prueba' && $new->phone === '+59170000000' && $new->password === null && $new->privacy_accepted_at && $new->uuid, 'registro normalizado sin contraseña con UUID y consentimiento');
    check($new->referred_by_id === $first->id && $first->invitees()->count() === 1, 'atribución transaccional conserva el original frente al campo oculto');
    $receipt = payload(http('GET', '/bienvenida', [], true));
    check($receipt['props']['referral_url'] === null && ! isset($receipt['props']['email']), 'bienvenida temporal no revela datos antes de verificar correo');
    $activation = Notification::sent($new, ActivateAccount::class)->first();
    check($activation && Hash::check($activation->token, DB::table('password_reset_tokens')->where('email', $new->email)->value('token')), 'activación simulada con token almacenado como hash');

    freshBrowser();
    app('cache')->flush();
    $count = User::count();
    http('GET', '/r/'.$second->referral_code);
    $duplicate = http('POST', '/registro', registration('New@Example.Test'));
    $duplicateReceipt = payload(http('GET', '/bienvenida', [], true));
    check(User::count() === $count && $duplicate->headers->get('Location') === $response->headers->get('Location') && $receipt['props'] === $duplicateReceipt['props'], 'email duplicado: respuesta indistinguible, sin cuenta ni atribución nuevas');
    check($new->fresh()->referred_by_id === $first->id, 'registro repetido conserva referente original');
    freshBrowser();
    http('GET', '/r/'.$first->referral_code);
    http('POST', '/registro', registration($first->email));
    check($first->fresh()->referred_by_id === null && $first->invitees()->count() === 1, 'autorreferido bloqueado al registrar el mismo email');
    freshBrowser();
    http('GET', '/r/'.$first->referral_code);
    http('POST', '/preferencias', ['allow_referral_cookie' => false]);
    check(payload(http('GET', '/', [], true))['props']['referral_code'] === null && ! isset($GLOBALS['browserCookies']['jakawi_referral']), 'retirar consentimiento elimina cookie y referente pendiente');

    check(User::distinct()->count('referral_code') === User::count() && User::distinct()->count('uuid') === User::count(), 'códigos públicos y UUID únicos');
    $immutable = false;
    try { $new->referral_code = 'CHANGECODE12'; $new->save(); } catch (LogicException) { $immutable = true; }
    check($immutable, 'modelo impide cambiar identidad permanente');
    $new->refresh();
    $immutable = false;
    try { DB::transaction(fn () => DB::table('users')->where('id', $new->id)->update(['referred_by_id' => $second->id])); } catch (Illuminate\Database\QueryException) { $immutable = true; }
    check($immutable && $new->fresh()->referred_by_id === $first->id, 'base de datos impide reemplazar atribuciones');
    $selfBlocked = false;
    try { DB::transaction(fn () => DB::table('users')->insert(['id' => 999, 'uuid' => (string) Illuminate\Support\Str::uuid(), 'referral_code' => 'SELFTEST1234', 'name' => 'Self', 'email' => 'self@example.test', 'referred_by_id' => 999])); } catch (Illuminate\Database\QueryException) { $selfBlocked = true; }
    check($selfBlocked, 'base de datos bloquea autorreferido');
    $duplicateBlocked = false;
    try { DB::transaction(fn () => DB::table('users')->insert(['uuid' => (string) Illuminate\Support\Str::uuid(), 'referral_code' => 'DUPETEST1234', 'name' => 'Dupe', 'email' => ' NEW@EXAMPLE.TEST '])); } catch (Illuminate\Database\QueryException) { $duplicateBlocked = true; }
    check($duplicateBlocked, 'índice único normalizado protege registros concurrentes');

    freshBrowser();
    app('cache')->flush();
    check(http('GET', '/dashboard')->getStatusCode() === 401, 'área privada rechaza visitantes');
    check(http('POST', '/login', ['email' => $new->email, 'password' => 'PasswordExample123'])->getStatusCode() === 422, 'cuenta sin activar no inicia sesión');
    $credentials = ['email' => $new->email, 'token' => $activation->token, 'password' => 'PasswordExample123', 'password_confirmation' => 'PasswordExample123'];
    check(http('POST', '/activar', $credentials)->isRedirect() && $new->fresh()->hasVerifiedEmail() && $new->fresh()->activated_at && Hash::check($credentials['password'], $new->fresh()->password), 'activación verifica email y establece contraseña segura');
    $welcome = payload(http('GET', '/bienvenida', [], true));
    check($welcome['props']['referral_url'] === $new->referralUrl(), 'bienvenida verificada muestra enlace personal');
    check(payload(http('GET', '/dashboard', [], true))['component'] === 'Dashboard', 'dashboard accesible tras activar');
    http('GET', '/r/'.$new->referral_code);
    check($new->fresh()->referred_by_id === $first->id, 'usuario autenticado no puede autoatribuirse otro referente');
    $id = $new->uuid;
    check(http('PATCH', '/perfil', ['name' => 'Nombre actualizado', 'phone' => '+591 70000009', 'city' => 'La Paz', 'referred_by_id' => $second->id, 'uuid' => 'bad'])->isRedirect() && $new->fresh()->uuid === $id && $new->fresh()->city === 'La Paz' && $new->fresh()->referred_by_id === $first->id, 'perfil básico no modifica identidad ni referente');
    http('POST', '/logout');
    check(http('GET', '/dashboard')->getStatusCode() === 401, 'logout revoca sesión');
    check(http('POST', '/activar', $credentials)->getStatusCode() === 422, 'token de activación no se reutiliza');
    check(http('POST', '/login', ['email' => ' NEW@EXAMPLE.TEST ', 'password' => $credentials['password']])->isRedirect() && payload(http('GET', '/dashboard', [], true))['component'] === 'Dashboard', 'login normaliza email y permite acceso privado');
    http('POST', '/logout');

    app('cache')->flush();
    $unknown = http('POST', '/recuperar-password', ['email' => 'absent@example.test']);
    $unknownStatus = app('session')->get('status');
    // Native broker throttles recently issued tokens; expire this fixture before recovery.
    DB::table('password_reset_tokens')->where('email', $new->email)->delete();
    $known = http('POST', '/recuperar-password', ['email' => $new->email]);
    check($unknown->getStatusCode() === $known->getStatusCode() && $unknownStatus === app('session')->get('status'), 'recuperación no revela si existe una cuenta');
    $reset = Notification::sent($new, ResetPassword::class)->last();
    check($reset !== null, 'recuperación envía notificación simulada nativa');
    http('POST', '/login', ['email' => $new->email, 'password' => 'PasswordExample123']);
    $oldSessionCookies = $browserCookies;
    freshBrowser();
    $credentials['token'] = $reset->token;
    $credentials['password'] = $credentials['password_confirmation'] = 'PasswordUpdated123';
    check(http('POST', '/restablecer-password', $credentials)->isRedirect() && Hash::check($credentials['password'], $new->fresh()->password), 'recuperación establece contraseña y consume token');
    $browserCookies = $oldSessionCookies;
    check(http('GET', '/dashboard')->getStatusCode() === 401, 'recuperación revoca sesiones anteriores en PostgreSQL');
    freshBrowser();
    check(http('POST', '/restablecer-password', $credentials)->getStatusCode() === 422, 'token de recuperación no se reutiliza');
    $expiredToken = Password::createToken($second);
    DB::table('password_reset_tokens')->where('email', $second->email)->update(['created_at' => now()->subMinutes(61)]);
    app('cache')->flush();
    check(http('POST', '/activar', array_merge($credentials, ['email' => $second->email, 'token' => $expiredToken]))->getStatusCode() === 422, 'enlace vencido no activa cuenta');
    app('session')->put('welcome_expires_at', now()->subSecond()->timestamp);
    check(http('GET', '/bienvenida')->isRedirect(), 'bienvenida vence a los 15 minutos');

    app('cache')->flush();
    for ($i = 0; $i < 5; $i++) http('POST', '/login', ['email' => 'rate@example.test', 'password' => 'wrong']);
    check(http('POST', '/login', ['email' => 'rate@example.test', 'password' => 'wrong'])->getStatusCode() === 429, 'limitación de intentos de login');
    check(LaunchSettings::publicData()['event'] === null && LaunchSettings::publicData()['whatsapp_url'] === null, 'configuración vacía no inventa eventos ni enlaces');
    config(['jakawi.event.title' => 'Evento de prueba', 'jakawi.event.starts_at' => '2026-12-01T19:00:00-04:00', 'jakawi.event.zoom_url' => 'https://zoom.us/j/fixture', 'jakawi.video_url' => 'javascript:alert(1)']);
    check(LaunchSettings::publicData()['video_url'] === null && LaunchSettings::publicData()['event']['zoom_url'] === null && LaunchSettings::publicData(true)['event']['zoom_url'] !== null, 'configuración HTTPS y enlace Zoom limitado al área privada');
    app('cache')->flush();
    $app->instance('env', 'production');
    check(http('POST', '/registro', registration('csrf@example.test'))->getStatusCode() === 419, 'CSRF protege formularios fuera de modo testing');
    $app->instance('env', 'testing');
    echo json_encode(['passed' => count($passed), 'database' => 'isolated PostgreSQL schema', 'notifications' => 'fake', 'live_test_users' => 0], JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $e) {
    // Only controlled assertions are printed, never SQL, credentials, tokens or traces.
    fwrite(STDERR, ($e instanceof RuntimeException && str_starts_with($e->getMessage(), 'FAILED:') ? $e->getMessage() : 'ERROR '.get_class($e).' at line '.$e->getLine()).PHP_EOL);
    $failed = true;
} finally {
    DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');
}
exit(isset($failed) ? 1 : 0);
