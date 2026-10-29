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
$schema = 'postconversion_test_'.bin2hex(random_bytes(6));
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
    config(['database.connections.pgsql.search_path' => $schema, 'jakawi.video_url' => null, 'jakawi.video_poster' => null,
        'jakawi.registration_open' => true, 'jakawi.transactional_mail_ready' => true, 'mail.default' => 'log', 'jakawi.whatsapp_group_url' => null]);
    DB::purge();
    check(DB::selectOne('select current_schema() as name')->name === $schema, 'PostgreSQL aislado, sin usuarios LIVE');
    Artisan::call('migrate', ['--force' => true]);
    Notification::fake();
    $home = App\Models\MarketingLanding::where('slug', 'principal')->firstOrFail();
    $content = App\Support\LandingContent::defaults();
    $content['after_registration'] = ['title' => 'Gracias por sumarte a esta landing.', 'message' => 'Tu solicitud fue procesada.', 'instructions' => 'Continúa con tu siguiente paso.', 'cta_label' => 'Acceder al grupo'];
    $content['confirmation_video']['youtube_url'] = 'https://www.youtube.com/watch?v=M7lc1UVf-VE';
    $content['confirmation_video']['source_aspect_ratio'] = '16:9';
    $content['confirmation_video']['focal_x'] = 0.25;
    $content['confirmation_video']['poster'] = '/marketing/poster-fixture.png';
    $content['next_step'] = ['action' => 'whatsapp', 'redirect_seconds' => 10];
    $home->update(['configuration' => $content, 'published_configuration' => $content, 'campaign' => 'origin_2026', 'published_campaign' => 'origin_2026']);
    foreach (['webinar' => 'WEBINAR', 'fundadores' => 'WAITLIST'] as $slug => $template) {
        $other = $content; $other['presentation']['template'] = $template;
        App\Models\MarketingLanding::create(['title' => $slug, 'slug' => $slug, 'template' => $template, 'configuration' => $other, 'published_configuration' => $other, 'status' => 'published', 'published_at' => now()]);
    }
    foreach (['principal', 'webinar', 'fundadores'] as $slug) {
        check(http('GET', '/'.$slug.'/confirmado')->isRedirect('https://jakawi.club/'.$slug), 'sin recibo no afirma conversión: '.$slug);
    }
    check(http('GET', '/no-existe/confirmado')->getStatusCode() === 404, 'no crea gracias para landing inexistente');
    foreach (['admin', 'registro', 'activar', 'confirmado', 'medicion'] as $slug) {
        check(! App\Support\LandingSlug::allowed($slug) && http('GET', '/'.$slug.'/confirmado')->getStatusCode() === 404, 'namespace protegido: '.$slug);
    }
    check(http('GET', '/admin/landings/'.$home->id.'/preview/confirmado')->getStatusCode() === 401, 'preview de gracias requiere autenticación');
    check(http('POST', '/registro', registration('closed@example.test', ['landing_id' => $home->id]))->getStatusCode() === 403, 'log bloquea registro aun con banderas true');
    check(User::count() === 0 && DB::table('password_reset_tokens')->count() === 0, 'log no crea cuenta ni token');
    check(http('GET', '/principal/confirmado')->isRedirect('https://jakawi.club/principal'), 'rechazo no genera recibo');
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'equipo@jakawi.club']);
    foreach ([false, null, '', 'no', 0] as $privacy) {
        app('cache')->flush();
        check(http('POST', '/registro', registration('bad@example.test', ['privacy' => $privacy, 'landing_id' => $home->id]))->getStatusCode() === 422, 'backend rechaza consentimiento no aceptado');
    }
    check(http('GET', '/principal/confirmado')->isRedirect('https://jakawi.club/principal'), 'validación fallida no genera recibo');
    $first = User::create(['name' => 'Referente original', 'email' => 'first@example.test']);
    $second = User::create(['name' => 'Otro referente', 'email' => 'second@example.test']);
    http('GET', '/r/'.$first->referral_code);
    http('GET', '/r/'.$second->referral_code);
    app('cache')->flush();
    $request = registration('conversion@example.test', ['landing_id' => $home->id, 'referral_code' => $second->referral_code]);
    $response = http('POST', '/registro', $request);
    check($response->isRedirect('https://jakawi.club/principal/confirmado'), 'backend dirige a gracias de landing de origen');
    $user = User::where('email', 'conversion@example.test')->firstOrFail();
    check($user->privacy_accepted_at !== null && $user->privacy_version === config('jakawi.privacy_version'), 'aceptación guarda fecha y versión existentes');
    check($user->referred_by_id === $first->id && ! $user->activated_at && ! $user->hasVerifiedEmail(), 'referente original y activación pendiente');
    $pending = payload(http('GET', '/principal/confirmado', [], true));
    check($pending['component'] === 'Registered' && $pending['props']['confirmation']['activation'] === 'pending' && $pending['props']['referral_url'] === null, 'gracias pendiente no afirma activación ni expone invitación');
    check(! isset($pending['props']['email'], $pending['props']['name'], $pending['props']['token']) && $pending['props']['confirmation']['landing_slug'] === 'principal', 'recibo vinculado sin datos de cuenta');
    check($pending['props']['confirmation']['next'] === null && $pending['props']['confirmation']['unavailable'], 'sin grupo aprobado no muestra enlace ni contador');
    check(app('session')->get('registration_receipt')['campaign'] === 'origin_2026', 'recibo conserva campaña publicada');
    $activation = Notification::sent($user, ActivateAccount::class)->first();
    $conversionCount = (int) DB::table('marketing_event_totals')->where('event', 'registration_completed')->sum('total');
    check($conversionCount === 1 && (int) DB::table('marketing_landing_event_totals')->where('landing_id', $home->id)->where('campaign', 'origin_2026')->where('event', 'registration_completed')->sum('total') === 1, 'conversión real de kernel transaccional con landing y campaña');
    http('GET', '/principal/confirmado', [], true); http('GET', '/principal/confirmado', [], true);
    check((int) DB::table('marketing_event_totals')->where('event', 'registration_completed')->sum('total') === $conversionCount, 'refrescar gracias no cuenta conversiones');
    check(http('GET', '/webinar/confirmado')->isRedirect('https://jakawi.club/webinar'), 'recibo no sirve para otra landing');
    check(http('GET', '/bienvenida')->isRedirect('https://jakawi.club/principal/confirmado'), 'bienvenida existente reutiliza landing de sesión');
    $newContent = $content; $newContent['after_registration']['title'] = 'Otra edición';
    $home->update(['published_configuration' => $newContent, 'published_campaign' => 'later_campaign']);
    check(payload(http('GET', '/principal/confirmado', [], true))['props']['confirmation']['content']['title'] === $content['after_registration']['title'], 'recibo conserva snapshot confirmado ante cambios editoriales');
    $withoutVideo = $pending; $withoutVideo['props']['confirmation']['video'] = App\Support\LandingContent::videoDefaults();
    check($pending['props']['confirmation']['video']['youtube_url'] === $content['confirmation_video']['youtube_url'], 'recibo conserva video específico');
    config(['jakawi.confirmation_video_url' => 'https://youtu.be/aqz-KE-bpKQ']);
    check(App\Support\LandingContent::confirmationVideo()['youtube_url'] === 'https://www.youtube.com/watch?v=aqz-KE-bpKQ', 'confirmación usa default operativo');
    check(App\Support\LandingContent::confirmationVideo($content['confirmation_video'])['youtube_url'] === $content['confirmation_video']['youtube_url'], 'video específico tiene prioridad sobre default');
    config(['jakawi.confirmation_video_url' => null]);
    check(App\Support\LandingContent::confirmationVideo()['youtube_url'] === null, 'sin URL no inventa video');
    check(LaunchSettings::defaultVideoUrl('https://youtu.be/P42gDOTL6Ys') === 'https://www.youtube.com/watch?v=P42gDOTL6Ys', 'default aprobado no se excluye por ID ni por fallos observados del proveedor');
    config(['jakawi.whatsapp_group_url' => 'https://chat.whatsapp.com/ApprovedGroupFixture123']);
    check(payload(http('GET', '/principal/confirmado', [], true))['props']['confirmation']['whatsapp_group_url'] === null, 'recibo sin grupo permanece sin grupo tras cambiar fallback');
    freshBrowser(); app('cache')->flush();
    http('POST', '/registro', $request);
    $whatsapp = payload(http('GET', '/principal/confirmado', [], true));
    check($whatsapp['props']['confirmation']['next'] === ['url' => config('jakawi.whatsapp_group_url'), 'external' => true, 'redirect_seconds' => 10], 'CTA y contador resueltos solo desde grupo operativo aprobado');
    foreach (['https://evil.example/group', 'javascript:alert(1)', 'https://chat.whatsapp.com.evil.example/test', 'https://user@chat.whatsapp.com/ApprovedGroupFixture123', 'https://chat.whatsapp.com/ApprovedGroupFixture123?redirect=https://evil.example'] as $bad) {
        config(['jakawi.whatsapp_group_url' => $bad]);
        check(App\Support\RegistrationNextStep::whatsappGroup() === null, 'destino de grupo inválido rechazado');
    }
    config(['jakawi.whatsapp_group_url' => 'https://chat.whatsapp.com/ApprovedGroupFixture123']);
    $credentials = ['email' => $user->email, 'token' => $activation->token, 'password' => 'PasswordExample123', 'password_confirmation' => 'PasswordExample123'];
    check(http('POST', '/activar', $credentials)->isRedirect('https://jakawi.club/bienvenida'), 'activación existente consume el token sin reemplazo');
    check(http('GET', '/bienvenida')->isRedirect('https://jakawi.club/principal/confirmado'), 'activación misma sesión vuelve a landing original');
    $confirmed = payload(http('GET', '/principal/confirmado', [], true));
    check($confirmed['props']['confirmation']['activation'] === 'confirmed' && $confirmed['props']['referral_url'] === $user->referralUrl(), 'solo cuenta activada y verificada muestra confirmación e invitación');
    check((int) DB::table('marketing_event_totals')->where('event', 'registration_completed')->sum('total') === $conversionCount && $user->fresh()->referred_by_id === $first->id, 'activar y refrescar no altera conversiones ni referente');
    $user->refresh(); $user->email_verified_at = null; $user->save();
    check(payload(http('GET', '/principal/confirmado', [], true))['props']['confirmation']['activation'] === 'pending', 'activated_at sin verificación no basta');
    $user->email_verified_at = now(); $user->save();
    $user->activated_at = null; $user->save();
    check(payload(http('GET', '/principal/confirmado', [], true))['props']['confirmation']['activation'] === 'pending', 'verificación sin activated_at no basta');
    $user->activated_at = now(); $user->save();
    app('session')->put('welcome_expires_at', now()->subSecond()->timestamp); app('session')->save();
    check(http('GET', '/principal/confirmado')->isRedirect('https://jakawi.club/principal') && app('session')->get('registration_receipt') === null, 'recibo vence y elimina atribución temporal');
    freshBrowser(); app('cache')->flush();
    $response = http('POST', '/registro', $request);
    $duplicate = payload(http('GET', '/principal/confirmado', [], true));
    check($response->isRedirect('https://jakawi.club/principal/confirmado') && $duplicate['props']['confirmation']['activation'] === 'pending' && (int) DB::table('marketing_event_totals')->where('event', 'registration_completed')->sum('total') === $conversionCount, 'email existente recibe respuesta genérica sin otra conversión');
    check($user->fresh()->referred_by_id === $first->id, 'email existente conserva referente inmutable');
    freshBrowser();
    check(http('GET', '/principal/confirmado?success=1&activation=confirmed')->isRedirect('https://jakawi.club/principal'), 'query no puede fabricar confirmación');
    check(http('POST', '/registro', registration('bad@example.test', ['landing_id' => 99999]))->getStatusCode() === 422, 'landing inexistente rechazada');
    $legacy = $content; unset($legacy['after_registration'], $legacy['next_step'], $legacy['presentation']);
    $normalized = App\Support\LandingContent::validate($legacy);
    check(isset($normalized['after_registration'], $normalized['next_step'], $normalized['presentation']), 'snapshots antiguos reciben defaults solo en memoria');
    $blockedLegacy = $legacy;
    $blockedLegacy['video'] = ['youtube_url' => 'https://youtu.be/P42gDOTL6Ys', 'poster' => null, 'aspect_ratio' => '16:9', 'source_aspect_ratio' => '9:16'];
    check(App\Support\LandingContent::withPresentation($blockedLegacy)['video']['youtube_url'] === $blockedLegacy['video']['youtube_url']
        && App\Support\LandingContent::withPresentation($blockedLegacy)['video']['aspect_ratio'] === '3:4'
        && $blockedLegacy['video']['youtube_url'] !== null, 'URL publicada heredada se conserva al añadir metadatos y marco 3:4');
    foreach (['source_type' => 'html', 'focal_x' => -0.1, 'focal_y' => 1.1, 'youtube_url' => 'https://youtube.com.evil.test/watch?v=M7lc1UVf-VE', 'poster' => 'https://evil.test/poster.png'] as $key => $bad) {
        $rejected = false;
        try { App\Support\LandingContent::validate([...$content, 'confirmation_video' => [...$content['confirmation_video'], $key => $bad]]); } catch (Illuminate\Validation\ValidationException) { $rejected = true; }
        check($rejected, 'video explicativo rechaza proveedor, encuadre o URL inseguros: '.$key);
    }
    $patch = ['schema' => 'jakawi-club-landing-v1', 'content' => ['hero' => ['title' => 'Compatible']]];
    check(App\Support\LandingContent::import(json_encode($patch), $legacy)['after_registration'] === App\Support\LandingContent::confirmationDefaults(), 'JSON v1 anterior sigue importable');
    $patch = ['schema' => App\Support\LandingContent::SCHEMA, 'content' => ['after_registration' => ['title' => 'Gracias del editor']]];
    $import = App\Support\LandingContent::import(json_encode($patch), $content);
    check($import['after_registration']['title'] === 'Gracias del editor' && $import['after_registration']['instructions'] === $content['after_registration']['instructions'] && $import['next_step'] === $content['next_step'], 'JSON v2 parcial conserva textos omitidos y operaciones');
    check(App\Support\LandingContent::envelope($content)['schema'] === 'jakawi-club-landing-v2' && !isset(App\Support\LandingContent::envelope($content)['content']['next_step']), 'export v2 excluye destino y contador');
    foreach ([['confirmation_video' => ['youtube_url' => 'https://youtu.be/aqz-KE-bpKQ']], ['video' => ['focal_x' => 0]], ['whatsapp_group_url' => 'https://chat.whatsapp.com/Unapproved123'], ['after_registration' => ['url' => 'https://evil.example']], ['next_step' => ['action' => 'whatsapp']], ['after_registration' => ['redirect_seconds' => 1]], ['after_registration' => ['title' => 'Cuenta activada']], ['after_registration' => ['message' => '<a href="https://evil.example">grupo</a>']]] as $bad) {
        $rejected = false;
        try { App\Support\LandingContent::import(json_encode(['schema' => App\Support\LandingContent::SCHEMA, 'content' => $bad]), $content); } catch (Illuminate\Validation\ValidationException) { $rejected = true; }
        check($rejected, 'JSON rechaza operaciones, enlaces ejecutables o afirmación de activación');
    }
    foreach ([['action' => 'https://evil.example', 'redirect_seconds' => 0], ['action' => 'whatsapp', 'redirect_seconds' => 1], ['action' => 'whatsapp', 'redirect_seconds' => 10, 'url' => 'https://evil.example']] as $bad) {
        $rejected = false;
        try { App\Support\LandingContent::validate([...$content, 'next_step' => $bad]); } catch (Illuminate\Validation\ValidationException) { $rejected = true; }
        check($rejected, 'controles operativos validados por allowlist');
    }
    check(App\Support\RegistrationNextStep::resolve(['action' => 'account'], false)['url'] === '/login' && App\Support\RegistrationNextStep::resolve(['action' => 'account'], true)['url'] === '/dashboard', 'acción cuenta distingue activación');
    foreach (['webinar', 'fundadores'] as $slug) {
        freshBrowser(); app('cache')->flush();
        $origin = App\Models\MarketingLanding::where('slug', $slug)->firstOrFail();
        $response = http('POST', '/registro', registration($slug.'@example.test', ['landing_id' => $origin->id]));
        check($response->isRedirect('https://jakawi.club/'.$slug.'/confirmado') && payload(http('GET', '/'.$slug.'/confirmado', [], true))['props']['confirmation']['landing_slug'] === $slug, 'registro usa gracias de origen: '.$slug);
    }
    $conversionCount = (int) DB::table('marketing_event_totals')->where('event', 'registration_completed')->sum('total');
    // An unrelated verified login cannot turn a pending receipt into an activation.
    http('POST', '/login', ['email' => $user->email, 'password' => 'PasswordExample123']);
    check(payload(http('GET', '/fundadores/confirmado', [], true))['props']['confirmation']['activation'] === 'pending', 'login ajeno al recibo no afirma activación de su solicitud');
    http('POST', '/logout');
    $user->is_marketing_admin = true; $user->save();
    http('POST', '/login', ['email' => $user->email, 'password' => 'PasswordExample123']);
    $preview = payload(http('GET', '/admin/landings/'.$home->id.'/preview/confirmado', [], true));
    check($preview['props']['confirmation']['preview'] && $preview['props']['confirmation']['activation'] === 'pending' && $preview['props']['referral_url'] === null, 'preview usa renderer real sin acreditar conversión ni activación');
    check((int) DB::table('marketing_event_totals')->where('event', 'registration_completed')->sum('total') === $conversionCount, 'preview no suma conversiones');
    // Reproduce the historical clock failure, then exercise real broker reissue/consumption.
    freshBrowser(); app('cache')->flush();
    $clockUser = User::create(['name' => 'Reloj aislado', 'email' => 'clock@example.test']);
    $mail = app(App\Services\AccountMail::class);
    $mail->send($clockUser->email);
    $clockToken = Notification::sent($clockUser, ActivateAccount::class)->last()->token;
    $stamp = DB::table('password_reset_tokens')->where('email', $clockUser->email)->value('created_at');
    $display = now()->setTimezone('America/La_Paz')->toIso8601String();
    check(date_default_timezone_get() === 'UTC' && config('app.timezone') === 'UTC'
        && abs(Carbon::parse($stamp, 'UTC')->timestamp - time()) < 5
        && Password::tokenExists($clockUser, $clockToken), 'regresión UTC: mostrar hora Bolivia no cambia timestamp del broker');
    $clockCredentials = ['email' => $clockUser->email, 'token' => $clockToken, 'password' => 'ClockPasswordExample123', 'password_confirmation' => 'ClockPasswordExample123'];
    $link = Notification::sent($clockUser, ActivateAccount::class)->last()->toMail($clockUser)->actionUrl;
    check(str_contains($link, '/activar/'.$clockToken.'?email=clock%40example.test') && http('GET', '/activar/'.$clockToken.'?email=clock%40example.test', [], true)->getStatusCode() === 200
        && Password::tokenExists($clockUser, $clockToken), 'ruta y parámetros correctos; GET no consume token');
    DB::table('password_reset_tokens')->where('email', $clockUser->email)->update(['created_at' => now()->subHours(4)]);
    check(! Password::tokenExists($clockUser, $clockToken) && http('POST', '/activar', $clockCredentials)->getStatusCode() === 422
        && ! $clockUser->fresh()->activated_at, 'reproduce token cuatro horas antiguo sin activar cuenta');
    $mail->send($clockUser->email);
    $reissued = Notification::sent($clockUser, ActivateAccount::class)->last()->token;
    check($reissued !== $clockToken && ! Password::tokenExists($clockUser, $clockToken) && Password::tokenExists($clockUser, $reissued), 'reemisión segura invalida enlace anterior');
    $clockCredentials['token'] = $reissued;
    check(http('POST', '/restablecer-password', $clockCredentials)->getStatusCode() === 422 && Password::tokenExists($clockUser, $reissued), 'token de activación no se consume en recuperación');
    DB::table('password_reset_tokens')->where('email', $clockUser->email)->update(['created_at' => now()->subMinutes(59)]);
    check(Password::tokenExists($clockUser, $reissued), 'broker acepta token antes de 60 minutos');
    DB::table('password_reset_tokens')->where('email', $clockUser->email)->update(['created_at' => now()->subMinutes(61)]);
    check(! Password::tokenExists($clockUser, $reissued), 'broker rechaza token después de 60 minutos');
    $mail->send($clockUser->email);
    $clockCredentials['token'] = Notification::sent($clockUser, ActivateAccount::class)->last()->token;
    check(http('POST', '/activar', $clockCredentials)->isRedirect('https://jakawi.club/bienvenida')
        && $clockUser->fresh()->activated_at && $clockUser->fresh()->hasVerifiedEmail()
        && ! DB::table('password_reset_tokens')->where('email', $clockUser->email)->exists(), 'activación consume token y verifica cuenta atómicamente');
    freshBrowser(); app('cache')->flush();
    check(http('POST', '/activar', $clockCredentials)->getStatusCode() === 422, 'enlace consumido no se puede reutilizar');
    require '/tests/campaigns-receipt-tests.php';
    file_put_contents('/evidence/confirmation-fixtures.json', json_encode(['pending' => $pending, 'whatsapp' => $whatsapp, 'confirmed' => $confirmed, 'preview' => $preview, 'without_video' => $withoutVideo], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    config(['mail.default' => 'log']); freshBrowser();
    check(http('POST', '/registro', $request)->getStatusCode() === 403, 'registro cerrado al terminar con log');
    echo json_encode(['passed' => count($passed), 'database' => 'isolated PostgreSQL schema', 'notifications' => 'fake', 'live_test_users' => 0], JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, ($e instanceof RuntimeException && str_starts_with($e->getMessage(), 'FAILED:') ? $e->getMessage() : 'ERROR '.get_class($e).' at line '.$e->getLine()).PHP_EOL);
    $failed = true;
} finally {
    DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');
}
exit(isset($failed) ? 1 : 0);
