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
$schema = 'activation_test_'.bin2hex(random_bytes(6));
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
    config(['database.connections.pgsql.search_path' => $schema]); DB::purge();
    check(DB::selectOne('select current_schema() as name')->name === $schema, 'PostgreSQL aislado');
    Artisan::call('migrate', ['--force' => true]);
    config(['jakawi.registration_open' => true, 'jakawi.transactional_mail_ready' => true, 'mail.default' => 'smtp', 'identity.enabled' => false]);
    Notification::fake();
    $tokens = app(App\Services\ActivationTokens::class);
    $email = 'activacion@example.test';
    check(http('POST', '/registro', registration($email))->isRedirect(), 'registro inicial');
    $user = User::where('email', $email)->firstOrFail();
    $original = $user->registration_origin;
    $notification = Notification::sent($user, ActivateAccount::class)->last();
    check($notification !== null, 'registro envía bienvenida');
    $token = $notification->token;
    check(strlen($token) === 64 && DB::table('account_activation_tokens')->where('user_id', $user->id)->value('token_hash') === hash('sha256', $token), 'token aleatorio de 256 bits; solo hash persistido');
    check(! DB::table('password_reset_tokens')->where('email', $email)->exists(), 'broker de contraseña separado');
    $url = '/activar/'.$token.'?email='.urlencode($email);
    DB::table('account_activation_tokens')->where('user_id', $user->id)->update(['created_at' => now()->subDays(45)]);
    $page = http('GET', $url, [], true);
    check(payload($page)['props']['mode'] === 'activate' && $tokens->valid($user, $token), 'enlace válido después de 45 días; GET no consume');
    check(Auth::guest() && ! $user->fresh()->activated_at, 'abrir enlace no autentica ni activa');
    check($page->headers->get('Referrer-Policy') === 'no-referrer' && str_contains($page->headers->get('Cache-Control'), 'no-store'), 'enlace sin referer ni caché');
    file_put_contents('/evidence/activation.html', http('GET', $url)->getContent());
    $mail = $notification->toMail($user);
    $html = view($mail->view, $mail->viewData)->render();
    file_put_contents('/evidence/email.html', $html);
    check($mail->subject === '¡Bienvenido a JAKAWI! Activa tu cuenta' && $mail->from[1] === 'JAKAWI' && str_contains($html, 'ACTIVAR MI CUENTA') && ! str_contains($html, '60 minutos') && ! str_contains($html, 'Regards') && ! str_contains($html, 'Launch Engine'), 'correo JAKAWI español sin caducidad y con URL alternativa');
    $credentials = ['email'=>$email, 'token'=>$token, 'password'=>'PasswordExample123', 'password_confirmation'=>'PasswordExample123'];
    $before = DB::table('marketing_event_totals')->where('event', 'registration_completed')->sum('total');
    $ads = DB::table('ad_events')->count();
    check(http('POST', '/activar', array_replace($credentials, ['password_confirmation'=>'Different123456']))->getStatusCode() === 422 && $tokens->valid($user, $token), 'contraseña no confirmada permite corregir sin consumir');
    check(http('POST', '/activar', $credentials)->isRedirect('https://jakawi.club/login') && $user->fresh()->activated_at && $user->fresh()->hasVerifiedEmail() && Hash::check($credentials['password'], $user->fresh()->password), 'primer uso activa y ofrece ingreso normal');
    check(! $tokens->valid($user->fresh(), $token) && ! DB::table('account_activation_tokens')->where('user_id', $user->id)->exists(), 'consumo único dentro de transacción');
    freshBrowser(); app('cache')->flush();
    check(http('POST', '/activar', array_replace($credentials, ['password'=>'ChangedPassword123', 'password_confirmation'=>'ChangedPassword123']))->isRedirect() && Hash::check($credentials['password'], $user->fresh()->password), 'segundo uso rechazado sin cambiar contraseña');
    check(payload(http('GET', $url, [], true))['props']['mode'] === 'activation-recovery', 'cuenta activada ofrece recuperación con Ingresar');
    check(http('POST', '/login', ['email'=>$email, 'password'=>$credentials['password']])->isRedirect() && Auth::check(), 'ingreso normal después de activar');
    freshBrowser(); app('cache')->flush();
    check(http('POST', '/registro', registration($email))->isRedirect() && User::where('email', $email)->count() === 1, 'registro repetido no duplica cuenta');
    $pending = User::create(['email'=>'pendiente@example.test', 'name'=>'Pendiente']);
    $old = Password::createToken($pending);
    DB::table('password_reset_tokens')->where('email', $pending->email)->update(['created_at'=>now()->subDays(3)]);
    $oldUrl = '/activar/'.$old.'?email='.urlencode($pending->email);
    check(payload(http('GET', $oldUrl, [], true))['props']['mode'] === 'activation-recovery', 'enlace antiguo expirado abre reenvío directamente');
    file_put_contents('/evidence/recovery.html', http('GET', $oldUrl)->getContent());
    check(payload(http('GET', '/activar/enlace-invalido?email='.urlencode($pending->email), [], true))['props']['email'] === $pending->email, 'enlace malformado recuperable con correo prellenado');
    freshBrowser(); app('cache')->flush();
    check(http('POST', '/recuperar-password', ['email'=>$pending->email])->isRedirect(), 'reenvío sin repetir registro');
    $first = Notification::sent($pending, ActivateAccount::class)->last()->token;
    check($tokens->valid($pending, $first), 'reenvío emite token durable');
    http('POST', '/recuperar-password', ['email'=>$pending->email]);
    $replacement = Notification::sent($pending, ActivateAccount::class)->last()->token;
    check($first !== $replacement && ! $tokens->valid($pending, $first) && $tokens->valid($pending, $replacement), 'reemplazo invalida anterior');
    check(http('POST', '/recuperar-password', ['email'=>$pending->email])->getStatusCode() === 429, 'límite de dos reenvíos por correo y minuto');
    check(http('POST', '/activar', array_replace($credentials, ['email'=>$pending->email, 'token'=>$first]))->isRedirect() && ! $pending->fresh()->activated_at, 'POST de enlace reemplazado no activa cuenta');
    $tokens->revoke($pending);
    check(! $tokens->valid($pending, $replacement), 'revocación invalida invitación');
    check(http('POST', '/activar', array_replace($credentials, ['email'=>$pending->email, 'token'=>$replacement]))->isRedirect() && ! $pending->fresh()->activated_at, 'POST de invitación revocada no activa cuenta');
    $replacement = $tokens->issue($pending);
    check(http('POST', '/restablecer-password', array_replace($credentials, ['email'=>$pending->email, 'token'=>$replacement]))->getStatusCode() === 422 && $tokens->valid($pending, $replacement), 'recuperación de contraseña no consume activación');
    app('cache')->flush();
    app(App\Services\AccountMail::class)->send($email);
    $reset = Notification::sent($user, ResetPassword::class)->last()->token;
    check(Password::tokenExists($user, $reset), 'cuenta activa conserva recuperación normal');
    DB::table('password_reset_tokens')->where('email', $email)->update(['created_at'=>now()->subMinutes(61)]);
    check(! Password::tokenExists($user, $reset), 'contraseña conserva expiración de 60 minutos');
    check(DB::table('marketing_event_totals')->where('event', 'registration_completed')->sum('total') === $before && (int)$before === 1 && DB::table('ad_events')->count() === $ads && $user->fresh()->registration_origin === $original, 'registro y conversión únicos; activación/reenvío conservan atribución y no crean eventos Ads');
    $raceUser = User::create(['email'=>'concurrente@example.test', 'name'=>'Concurrente']);
    $raceToken = $tokens->issue($raceUser);
    $workers = [];
    for ($i=0; $i<2; $i++) {
        $process = proc_open([PHP_BINARY, '/tests/activation-durable-race.php'], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
        fwrite($pipes[0], json_encode(['schema'=>$schema, 'credentials'=>array_replace($credentials, ['email'=>$raceUser->email, 'token'=>$raceToken])]));
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $outcomes = [];
    foreach ($workers as [$process, $pipes]) {
        $outcomes[] = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        check(proc_close($process) === 0 && $stderr === '', 'proceso concurrente aislado sin errores');
    }
    sort($outcomes);
    check($outcomes === ['consumed','rejected'] && $raceUser->fresh()->activated_at !== null && ! $tokens->valid($raceUser->fresh(), $raceToken), 'dos POST simultáneos: exactamente un consumo exitoso');
    file_put_contents('/evidence/tests.json', json_encode(['passed'=>count($passed), 'checks'=>$passed, 'live_users_created'=>0], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    fwrite(STDERR, ($e instanceof RuntimeException && str_starts_with($e->getMessage(), 'FAILED:') ? $e->getMessage() : 'ERROR '.get_class($e).' at line '.$e->getLine()).PHP_EOL);
    $failed = true;
} finally {
    DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');
}
exit(isset($failed) ? 1 : 0);
