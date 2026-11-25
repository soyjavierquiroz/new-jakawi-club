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
    config(['database.connections.pgsql.search_path' => $schema, 'jakawi.registration_open' => false]);
    DB::purge('pgsql');
    check(DB::selectOne('select current_schema() as name')->name === $schema, 'isolated schema');
    Artisan::call('migrate', ['--force' => true]);
    $admin = new User(['name'=>'Visual Editor', 'email'=>'visual@example.test', 'password'=>'PasswordEditor123']);
    $admin->email_verified_at=now(); $admin->activated_at=now(); $admin->save();
    Artisan::call('landings:admin', ['email'=>$admin->email]);
    check(http('POST','/login',['email'=>$admin->email,'password'=>'PasswordEditor123'])->isRedirect(),'editor login');
    $content=App\Support\LandingContent::webinarDefaults();
    $content['presentation']['webinar']['sections'][3]['background']='dark';
    $content['presentation']['webinar']['sections'][3]['layout']='flow';
    $data=['title'=>'Visual QA','slug'=>'visual-qa','campaign'=>'visual','configuration'=>$content];
    check(http('POST','/admin/landings',$data)->isRedirect(),'save visual draft');
    $landing=App\Models\MarketingLanding::where('slug','visual-qa')->sole();
    $base='/admin/landings/'.$landing->id;
    check($landing->configuration['presentation']['webinar']['sections'][3]['background']==='dark','preset persisted');
    Illuminate\Support\Facades\Storage::fake(config('media.disk'));
    $key='club/landings/'.$landing->id.'/cover/'.Illuminate\Support\Str::uuid().'.webp';
    Illuminate\Support\Facades\Storage::disk(config('media.disk'))->put($key, 'isolated fixture');
    $content['webinar']['opportunity']['image']=['asset_id'=>$key,'alt'=>'Imagen autorizada QA','position'=>'right','fit'=>'contain'];
    $landing->update(['configuration'=>$content]);
    $content['webinar']['opportunity']['image']['position']='left';
    $content['presentation']['webinar']['sections'][3]['background']='soft';
    $content['presentation']['webinar']['sections'][3]['layout']='split';
    check(http('PUT',$base,[...$data,'configuration'=>$content,'revision'=>$landing->revision])->isRedirect(),'edit visual section');
    check(http('POST',$base.'/publicar',['revision'=>$landing->fresh()->revision])->isRedirect(),'publish saved section');
    $public=payload(http('GET','/visual-qa',[],true));
    check($public['props']['landing']['content']['presentation']['webinar']['sections'][3]['background']==='soft','public preset');
    check($public['props']['landing']['content']['presentation']['webinar']['sections'][3]['layout']==='split','editorial layout saved and published');
    check($public['props']['landing']['content']['webinar']['opportunity']['image']['position']==='left','image position preserved through publish');
    check($public['props']['landing']['content']['hero']==$content['hero'],'hero and CTA preserved');
    $content['presentation']['webinar']['sections'][3]['background']='arbitrary-css';
    check(http('PUT',$base,[...$data,'configuration'=>$content,'revision'=>$landing->fresh()->revision])->getStatusCode()===422,'arbitrary style rejected');
    check($landing->fresh()->published_configuration['video']==$content['video'],'VSL preserved');
    file_put_contents('/evidence/tests.json',json_encode(['result'=>'PASS','checks'=>$passed,'isolated_schema'=>true]));
} finally {
    DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');
}
