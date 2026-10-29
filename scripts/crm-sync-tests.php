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
$schema = 'crm_test_'.bin2hex(random_bytes(6));
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

function crmFake(?callable $callback=null):void {
    Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory);
    Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Http::fake($callback);
}
try {
    DB::statement('CREATE SCHEMA '.$schema);
    config(['database.connections.pgsql.search_path'=>$schema,'jakawi.registration_open'=>true,'jakawi.transactional_mail_ready'=>true,
        'mail.default'=>'smtp','mail.mailers.smtp.host'=>'smtp.example.test','mail.from.address'=>'equipo@jakawi.club','queue.default'=>'database',
        'crm.secret_file'=>'/tmp/club-hmac']);
    file_put_contents('/tmp/club-hmac',str_repeat('t',64));
    DB::purge();Artisan::call('migrate',['--force'=>true]);Notification::fake();
    Illuminate\Support\Facades\Http::preventStrayRequests();crmFake();
    $home=App\Models\MarketingLanding::where('slug','principal')->firstOrFail();
    $home->update(['campaign'=>'crm_fixture','published_campaign'=>'crm_fixture']);
    $first=User::create(['name'=>'First referrer','email'=>'referrer@example.test']);
    $other=User::create(['name'=>'Second referrer','email'=>'other@example.test']);
    http('GET','/r/'.$first->referral_code);http('GET','/r/'.$other->referral_code);
    http('GET','/principal?utm_source=fixture&utm_campaign=source-campaign&utm_medium=email');
    $request=registration('integration@example.test',['landing_id'=>$home->id,'referral_code'=>$other->referral_code]);
    check(http('POST','/registro',$request)->isRedirect('https://jakawi.club/principal/gracias'),'registro funciona sin contactar CRM');
    check(Illuminate\Support\Facades\Http::recorded()->count()===0,'ninguna llamada CRM durante registro');
    $user=User::where('email','integration@example.test')->firstOrFail();
    check($user->registration_origin['landing_slug']==='principal' && $user->registration_origin['campaign']==='crm_fixture','procedencia individual persistida');
    check($user->registration_origin['utms']['utm_source']==='fixture' && $user->registration_origin['utms']['utm_campaign']==='source-campaign','UTMs preservadas');
    check($user->referred_by_id===$first->id,'referente original preservado');
    function queuedPayload():array {
        $job=DB::table('jobs')->where('queue','crm')->first();
        $data=json_decode($job->payload,true);
        check(!str_contains($job->payload,'integration@example.test'),'payload de contacto cifrado en cola');
        return unserialize(app('encrypter')->decrypt($data['data']['command']))->payload;
    }
    check(DB::table('jobs')->where('queue','crm')->count()===1,'evento durable en cola existente');
    $pending=queuedPayload();
    check($pending['activation']['status']==='pending' && $pending['external_id']===$user->uuid,'registro emite pending con UUID');
    check($pending['attribution']['referrer_external_id']===$first->uuid,'referido externo usa UUID');
    check(!str_contains(json_encode($pending),'password') && !str_contains(json_encode($pending),'token') && $pending['marketing']['action']==='UNCHANGED','sin passwords tokens ni opt-in artificial');
    try {DB::transaction(function()use($user){app(App\Services\Crm\ClubContact::class)->enqueue($user);throw new RuntimeException('rollback');});} catch(RuntimeException){}
    check(DB::table('jobs')->where('queue','crm')->count()===1,'rollback no deja evento para un registro no confirmado');
    crmFake(fn($r)=>Illuminate\Support\Facades\Http::response(['result_code'=>'UNAVAILABLE'],503));
    Artisan::call('queue:work',['connection'=>'database','--queue'=>'crm','--once'=>true,'--sleep'=>0,'--tries'=>12]);
    $retry=DB::table('jobs')->where('queue','crm')->first();
    check($retry && $retry->attempts===1 && $retry->available_at>time(),'fallo CRM programa reintento');
    check(queuedPayload()['event_id']===$pending['event_id'],'reintento conserva event_id');
    check($user->fresh()!==null && !$user->activated_at,'fallo CRM no altera registro');
    DB::table('jobs')->where('id',$retry->id)->update(['available_at'=>time()]);
    crmFake(fn()=>Illuminate\Support\Facades\Http::response('<html>Unexpected response</html>',200,['Content-Type'=>'text/html']));
    Artisan::call('queue:work',['connection'=>'database','--queue'=>'crm','--once'=>true,'--sleep'=>0]);
    $retry=DB::table('jobs')->where('queue','crm')->first();
    check($retry && $retry->attempts===2 && $retry->available_at>time(),'HTML con HTTP 200 se reintenta y no se acepta como entrega');
    check(queuedPayload()['event_id']===$pending['event_id'],'respuesta inválida conserva evento');
    DB::table('jobs')->where('id',$retry->id)->update(['available_at'=>time()]);
    crmFake(function($r)use($pending){
        $raw=$r->body();$time=$r->header('X-Jakawi-Timestamp')[0];$id=$r->header('X-Jakawi-Event-Id')[0];
        check(hash_equals(hash_hmac('sha256',$time."\n".$id."\n".$raw,str_repeat('t',64)),$r->header('X-Jakawi-Signature')[0]),'firma HMAC del cuerpo exacto');
        check(json_decode($raw,true)['event_id']===$pending['event_id'],'mismo evento en entrega HTTP reintentada');
        return Illuminate\Support\Facades\Http::response(['result_code'=>'OK','provider_contact_id'=>123,'idempotent'=>false],200);
    });
    Artisan::call('queue:work',['connection'=>'database','--queue'=>'crm','--once'=>true,'--sleep'=>0]);
    check(DB::table('jobs')->where('queue','crm')->count()===0,'entrega satisfactoria elimina trabajo');
    $activation=Notification::sent($user,ActivateAccount::class)->first();
    $credentials=['email'=>$user->email,'token'=>$activation->token,'password'=>'ActivatedPassword123','password_confirmation'=>'ActivatedPassword123'];
    check(http('POST','/activar',$credentials)->isRedirect('https://jakawi.club/bienvenida'),'activación SES existente conserva flujo');
    $activated=queuedPayload();
    check($activated['activation']['status']==='activated' && $activated['activation']['activated_at']!==null && $activated['external_id']===$pending['external_id'],'activación encola actualización del mismo UUID');
    check($activated['event_id']!==$pending['event_id'] && $activated['attribution']==$pending['attribution'],'evento de activación nuevo con procedencia original');
    $origin=$user->registration_origin;
    try {$user->registration_origin=['landing_slug'=>'other'];$user->save();throw new RuntimeException('mutation allowed');}catch(LogicException){}
    check($user->fresh()->registration_origin===$origin,'procedencia no se reatribuye');
    crmFake(fn()=>Illuminate\Support\Facades\Http::response(['result_code'=>'IDENTITY_CONFLICT'],409));
    Artisan::call('queue:work',['connection'=>'database','--queue'=>'crm','--once'=>true,'--sleep'=>0]);
    check(DB::table('jobs')->where('queue','crm')->count()===0 && DB::table('failed_jobs')->count()===1,'conflicto visible en failed_jobs sin reintentos destructivos');
    $failedId=DB::table('failed_jobs')->value('uuid');Artisan::call('queue:retry',['id'=>[$failedId]]);
    check(queuedPayload()['event_id']===$activated['event_id'],'reenvío manual conserva idempotencia');
    crmFake(fn()=>Illuminate\Support\Facades\Http::response(['result_code'=>'OK','provider_contact_id'=>123,'idempotent'=>true],200));
    Artisan::call('queue:work',['connection'=>'database','--queue'=>'crm','--once'=>true,'--sleep'=>0]);
    check(DB::table('jobs')->where('queue','crm')->count()===0 && DB::table('failed_jobs')->count()===0,'trabajo recuperado sin duplicación');
    freshBrowser();app('cache')->flush();
    check(http('POST','/registro',$request)->isRedirect('https://jakawi.club/principal/gracias'),'email repetido mantiene respuesta genérica');
    check(DB::table('jobs')->where('queue','crm')->count()===0 && User::where('email','integration@example.test')->count()===1,'email repetido no duplica contacto ni evento');
    $beforeState=hash('sha256',json_encode($user->fresh()->getRawOriginal()));
    check(Artisan::call('crm:sync-contact',['--registered'=>true])===0,'leads históricos se encolan sin inventar procedencia');
    check(DB::table('jobs')->where('queue','crm')->count()===1 && hash('sha256',json_encode($user->fresh()->getRawOriginal()))===$beforeState,'sincronizar histórico no modifica estado ni identidad');
    file_put_contents('/evidence/crm-payloads.json',json_encode(['pending'=>$pending,'activated'=>$activated],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    echo json_encode(['passed'=>count($passed),'isolated_postgresql'=>true,'notifications'=>'fake','live_users_created'=>0],JSON_PRETTY_PRINT).PHP_EOL;
} catch(Throwable $e) {fwrite(STDERR, 'FAILED '.get_class($e).' '.$e->getMessage().' at '.$e->getLine().PHP_EOL);$failed=true;}
finally {DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');}
exit(isset($failed)?1:0);
