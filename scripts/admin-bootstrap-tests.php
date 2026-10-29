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
$schema = 'bootstrap_test_'.bin2hex(random_bytes(6));
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

/** Exercise real console questions; only the physical TTY probe is simulated. */
function bootstrapAdmin(array $answers = [], bool $terminal = true, bool $interactive = true): array
{
    global $app;
    $command = new class extends App\Console\Commands\BootstrapLandingAdmin
    {
        public bool $terminal = true;

        protected function hasInteractiveTerminal(): bool
        {
            return $this->terminal;
        }
    };
    $command->terminal = $terminal;
    $command->setLaravel($app);
    $command->setApplication(Artisan::all()['landings:bootstrap-admin']->getApplication());
    $tester = new Symfony\Component\Console\Tester\CommandTester($command);
    $tester->setInputs($answers);
    $status = $tester->execute([], ['interactive' => $interactive]);
    return [$status, $tester->getDisplay()];
}

// A separate bounded container runs this branch with a genuine pseudoterminal.
if (in_array('--tty-scenario', $argv, true)) {
    $status = 1;
    try {
        DB::statement('CREATE SCHEMA '.$schema);
        config(['database.connections.pgsql.search_path' => $schema, 'jakawi.registration_open' => false]);
        DB::purge();
        Artisan::call('migrate', ['--force' => true]);
        $command = Artisan::all()['landings:bootstrap-admin'];
        $status = $command->run(new Symfony\Component\Console\Input\ArrayInput([]), new Symfony\Component\Console\Output\ConsoleOutput());
        if ($status !== 0 || User::where('is_marketing_admin', true)->count() !== 1 || LaunchSettings::registrationOpen()) {
            $status = 1;
        } else {
            echo 'TTY_BOOTSTRAP_OK; live_users_created=0'.PHP_EOL;
        }
    } catch (Throwable) {
        echo 'TTY_BOOTSTRAP_FAILED'.PHP_EOL;
        $status = 1;
    } finally {
        DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');
    }
    exit($status);
}

try {
    DB::statement('CREATE SCHEMA '.$schema);
    config(['database.connections.pgsql.search_path' => $schema, 'jakawi.video_url' => null, 'jakawi.video_poster' => null, 'jakawi.registration_open' => false, 'jakawi.transactional_mail_ready' => false]);
    DB::purge();
    check(DB::selectOne('select current_schema() as name')->name === $schema, 'PostgreSQL aislado en jakawi_club');
    Artisan::call('migrate', ['--force' => true]);
    check(isset(Artisan::all()['landings:bootstrap-admin']), 'comando descubierto por Artisan sin dependencias nuevas');
    check(config('mail.default') === 'log', 'pruebas con MAIL_MAILER=log');
    Illuminate\Support\Facades\Log::error('Bootstrap test logger active (no credentials).');
    $settingsBefore = [config('jakawi.registration_open'), config('jakawi.transactional_mail_ready'), config('mail.default')];
    check(http('GET', '/admin/landings')->getStatusCode() === 401, 'invitado sin acceso administrativo');

    // Generated only in test-process memory; never write a password fixture or print it.
    $normalPassword = bin2hex(random_bytes(16)).'A9';
    $normal = new User(['name' => 'Miembro aislado', 'email' => 'existing@example.test', 'password' => $normalPassword]);
    $normal->activated_at = now(); $normal->email_verified_at = now(); $normal->save();
    $normal->refresh();
    $normalBefore = $normal->getAttributes();
    [$status] = bootstrapAdmin([], true, false);
    check($status === 1 && User::count() === 1, 'modo no interactivo rechazado sin alta');
    [$status] = bootstrapAdmin([], false);
    check($status === 1 && User::count() === 1, 'terminal sin TTY rechazada sin pedir contraseña');
    [$status] = bootstrapAdmin(['Cuenta existente', ' EXISTING@EXAMPLE.TEST ']);
    check($status === 1 && $normal->fresh()->getAttributes() === $normalBefore, 'correo existente normalizado rechazado sin cambiar contraseña ni permisos');
    [$status] = bootstrapAdmin(['Nombre aislado', 'correo-invalido']);
    check($status === 1 && User::count() === 1, 'correo inválido no crea cuenta');
    [$status] = bootstrapAdmin(['Nombre aislado', 'owner@example.test', 'no']);
    check($status === 1 && ! User::where('email', 'owner@example.test')->exists(), 'titularidad sin confirmar cancela verificación y alta');
    [$status] = bootstrapAdmin(['Nombre aislado', 'owner@example.test', '']);
    check($status === 1 && User::count() === 1, 'confirmación de titularidad usa no por defecto');

    $password = ' '.bin2hex(random_bytes(16)).'A9 ';
    $otherPassword = bin2hex(random_bytes(16)).'B8';
    $weakPassword = bin2hex(random_bytes(3)).'A9';
    $longPassword = bin2hex(random_bytes(40)).'A9';
    $displays = [];
    foreach ([[$password, $otherPassword], [$weakPassword, $weakPassword], [$longPassword, $longPassword]] as [$first, $second]) {
        [$status, $display] = bootstrapAdmin(['Nombre aislado', 'owner@example.test', 'yes', $first, $second]);
        $displays[] = $display;
        check($status === 1 && User::count() === 1, 'contraseña inválida o confirmación distinta no persiste cuenta');
    }
    [$status, $display] = bootstrapAdmin(['Nombre aislado', 'owner@example.test', 'yes', $password, $password, 'no']);
    $displays[] = $display;
    check($status === 1 && User::count() === 1, 'cancelación final no crea cuenta ni concede permisos');

    // Unexpected exceptions carrying sensitive data must not reach console/log output.
    $failCreating = true;
    Illuminate\Support\Facades\Event::listen('eloquent.creating: '.User::class, function () use (&$failCreating, $password): void {
        if ($failCreating) throw new RuntimeException($password);
    });
    [$status, $display] = bootstrapAdmin(['Nombre aislado', 'owner@example.test', 'yes', $password, $password, 'yes']);
    $displays[] = $display;
    check($status === 1 && User::count() === 1, 'error inesperado revierte el alta sin detalles sensibles');
    $failCreating = false;

    $console = Artisan::all()['landings:admin']->getApplication();
    $grantCommand = $console->find('landings:admin');
    $deniedGrant = new class extends Illuminate\Console\Command
    {
        protected $signature = 'landings:admin {email} {--revoke}';
        public int $calls = 0;
        public function handle(): int { $this->calls++; return self::FAILURE; }
    };
    $deniedGrant->setLaravel($app);
    $console->add($deniedGrant);
    [$status, $display] = bootstrapAdmin(['Nombre aislado', 'owner@example.test', 'yes', $password, $password, 'yes']);
    $displays[] = $display;
    check($status === 1 && $deniedGrant->calls === 1 && User::count() === 1, 'concesión reutiliza landings:admin y su fallo revierte toda el alta');
    $console->add($grantCommand);

    [$status, $display] = bootstrapAdmin(['  Propietario   aislado  ', ' OWNER@EXAMPLE.TEST ', 'yes', $password, $password, 'yes']);
    $displays[] = $display;
    check($status === 0, 'bootstrap interactivo confirmado crea primer administrador');
    $admin = User::where('email', 'owner@example.test')->firstOrFail();
    check($admin->name === 'Propietario aislado' && Illuminate\Support\Str::isUuid($admin->uuid), 'identidad normalizada y UUID generado por modelo existente');
    check((bool) preg_match('/^[A-Z0-9]{12}$/D', $admin->referral_code) && $admin->referred_by_id === null, 'código de referido propio sin atribución inventada');
    check($admin->activated_at !== null && $admin->hasVerifiedEmail() && $admin->is_marketing_admin, 'cuenta activada y verificada tras confirmación explícita');
    check(Hash::check($password, $admin->password) && $admin->password !== $password, 'contraseña almacenada con hash existente y espacios conservados');
    check($normal->fresh()->getAttributes() === $normalBefore, 'otras cuentas y sus permisos intactos');
    check(DB::table('password_reset_tokens')->count() === 0, 'no se generan tokens de activación ni restablecimiento');

    $adminBefore = $admin->getAttributes();
    [$status] = bootstrapAdmin();
    check($status === 1 && User::count() === 2 && $admin->fresh()->getAttributes() === $adminBefore, 'repetición rechazada cuando ya existe administrador sin alterar cuentas');
    check(http('POST', '/login', ['email' => $admin->email, 'password' => $password])->isRedirect(), 'administrador inicia sesión con autenticación existente');
    check(http('GET', '/admin/landings', [], true)->getStatusCode() === 200, 'administrador autorizado en creador existente');
    $home = App\Models\MarketingLanding::where('slug', 'principal')->firstOrFail();
    check(http('GET', '/admin/landings/'.$home->id.'/preview', [], true)->getStatusCode() === 200, 'administrador autorizado en preview protegido');
    http('POST', '/logout'); freshBrowser();
    check(http('POST', '/login', ['email' => $normal->email, 'password' => $normalPassword])->isRedirect(), 'usuario normal conserva login existente');
    check(http('GET', '/admin/landings')->getStatusCode() === 403, 'usuario normal sin acceso a administración');
    http('POST', '/logout'); freshBrowser();
    check(http('GET', '/admin/landings')->getStatusCode() === 401, 'invitado sigue sin acceso después del bootstrap');
    check(http('POST', '/registro', registration('closed@example.test'))->getStatusCode() === 403, 'registro público permanece cerrado');
    check(! User::where('email', 'closed@example.test')->exists(), 'registro rechazado no crea usuarios');
    check($settingsBefore === [config('jakawi.registration_open'), config('jakawi.transactional_mail_ready'), config('mail.default')] && ! LaunchSettings::registrationOpen(), 'registro y correo sin cambios de configuración');

    $sensitiveValues = [$password, $otherPassword, $normalPassword, $weakPassword, $longPassword];
    $captured = implode('', $displays);
    $logContent = '';
    foreach (glob(storage_path('logs/*')) as $file) {
        if (is_file($file)) $logContent .= file_get_contents($file);
    }
    check(str_contains($logContent, 'Bootstrap test logger active (no credentials).'), 'logger realmente activo durante las pruebas');
    foreach ($sensitiveValues as $value) {
        check(! str_contains($captured, $value), 'entrada secreta ausente de salida de consola incluso ante errores');
        check(! str_contains($logContent, $value), 'contraseña ausente de archivos de log con logger activo');
    }
    check(! str_contains($logContent, '/activar/') && ! str_contains($logContent, '/restablecer-password/'), 'no se registran enlaces de acceso');
    echo json_encode(['passed' => count($passed), 'database' => 'isolated PostgreSQL schema', 'live_users_created' => 0, 'passwords' => 'random; memory only; hidden console input', 'logger' => 'active single-file; mail=log'], JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, ($e instanceof RuntimeException && str_starts_with($e->getMessage(), 'FAILED:') ? $e->getMessage() : 'ERROR '.get_class($e).' at line '.$e->getLine()).PHP_EOL);
    $failed = true;
} finally {
    DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');
}
exit(isset($failed) ? 1 : 0);
