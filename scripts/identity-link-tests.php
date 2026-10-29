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
$schema = 'identity_test_'.bin2hex(random_bytes(6));
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

function fakeIdentity(callable $callback): void {
    Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory);
    Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Http::fake($callback);
}
function remoteLink(User $user, string $subject, string $method = 'CLUB_EMAIL_ACTIVATION', string $status = 'LINKED'): array {
    return ['source_app'=>'club','club_user_id'=>$user->uuid,'global_subject_id'=>$subject,'issuer'=>'https://jakawi.com','status'=>$status,
        'method'=>$method,'authorized_at'=>now()->subSecond()->toIso8601String(),'revoked_at'=>$status==='REVOKED'?now()->toIso8601String():null];
}
function envelope(array $body, string $status, array $extra=[]): array {
    return array_merge(['contract'=>'jakawi-identity-handoff-v1','issuer'=>'https://jakawi.com','handoff_id'=>$body['handoff_id'],
        'club_user_id'=>$body['club_user_id'],'status'=>$status],$extra);
}
function loginFixture(string $email, bool $admin = false): User {
    freshBrowser();app('cache')->flush();
    $user=User::create(['name'=>'Identity Fixture','email'=>$email,'password'=>'FixturePassword123','phone'=>'+59170000001']);
    $user->forceFill(['activated_at'=>now(),'email_verified_at'=>now(),'is_marketing_admin'=>$admin])->save();
    check(http('POST','/login',['email'=>$email,'password'=>'FixturePassword123'])->isRedirect(),'fixture accede mediante login CLUB nativo');
    return $user->fresh();
}
try {
    DB::statement('CREATE SCHEMA '.$schema);
    config(['database.connections.pgsql.search_path'=>$schema,'jakawi.registration_open'=>true,'jakawi.transactional_mail_ready'=>true,
        'mail.default'=>'smtp','mail.mailers.smtp.host'=>'smtp.example.test','mail.from.address'=>'info@example.test',
        'identity.enabled'=>true,'identity.base_url'=>'https://jakawi.com/internal/identity','identity.key_id'=>'club-test-v1',
        'identity.secret'=>str_repeat('s',64),'identity.proof_secret'=>str_repeat('p',64)]);
    DB::purge();Artisan::call('migrate',['--force'=>true]);Notification::fake();
    fakeIdentity(fn()=>Illuminate\Support\Facades\Http::response([],404));
    check(http('POST','/cuenta/jakawi/activar')->getStatusCode()===401 && http('GET','/cuenta/jakawi/estado')->getStatusCode()===401,'acciones identidad exigen sesión CLUB');
    $home=App\Models\MarketingLanding::where('slug','principal')->firstOrFail();
    $registration=registration('new-identity@example.test',['landing_id'=>$home->id]);
    check(http('POST','/registro',$registration)->isRedirect(),'registro público conserva su flujo');
    $user=User::where('email',$registration['email'])->firstOrFail();
    check(Illuminate\Support\Facades\Http::recorded()->count()===0 && DB::table('club_identity_handoffs')->count()===0,'registro no provisiona COM ni crea operaciones');
    check(!app(App\Services\Identity\ActivationEvidence::class)->find($user),'registro pendiente carece de evidencia de activación');
    $activation=Notification::sent($user,ActivateAccount::class)->first();
    $credentials=['email'=>$user->email,'token'=>$activation->token,'password'=>'FixturePassword123','password_confirmation'=>'FixturePassword123'];
    check(http('POST','/activar',$credentials)->isRedirect(),'activación nativa por token consumido funciona');
    $user->refresh();
    check(app(App\Services\Identity\ActivationEvidence::class)->find($user)!==null && !Password::tokenExists($user,$activation->token),'evidencia durable solo tras activación nativa y consumo del token');
    $dashboard=payload(http('GET','/dashboard',[],true));
    check($dashboard['props']['identity']['status']==='READY' && !str_contains(json_encode($dashboard),str_repeat('s',64)),'dashboard privado expone estado sin secretos');
    $subject=(string)Illuminate\Support\Str::uuid();$remote=null;$postBodies=[];$nonces=[];
    $before=$user->getRawOriginal();
    fakeIdentity(function($r)use($user,$subject,&$remote,&$postBodies,&$nonces){
        $path=parse_url($r->url(),PHP_URL_PATH);$raw=$r->body();$nonce=$r->header('X-Jakawi-Nonce')[0];$time=$r->header('X-Jakawi-Timestamp')[0];
        $canonical=implode("\n",['jakawi-identity-v1','club-test-v1',$time,$nonce,$r->method(),$path,hash('sha256',$raw)]);
        check(hash_equals(hash_hmac('sha256',$canonical,str_repeat('s',64)),$r->header('X-Jakawi-Signature')[0]),'firma real cubre método ruta y bytes exactos');
        check(!$r->hasHeader('Cookie') && !$r->hasHeader('Authorization'),'API no comparte cookies ni contraseñas de sesión');
        if($r->method()==='GET')return Illuminate\Support\Facades\Http::response($remote??[], $remote?200:404);
        $body=json_decode($raw,true);$postBodies[]=$raw;$nonces[]=$nonce;
        [$encoded,$mac]=explode('.',$body['email_activation_proof']);$claims=json_decode(base64_decode(strtr($encoded,'-_','+/')),true);
        check(hash_equals(hash_hmac('sha256',"jakawi-email-activation-v1\n".$encoded,str_repeat('p',64)),$mac),'prueba de activación usa clave independiente');
        check($claims['sub']===$user->uuid && $claims['handoff_id']===$body['handoff_id'] && $claims['email']===$user->email && $claims['method']==='email_activation_token','prueba liga cuenta email y operación');
        check(!isset($body['password'],$body['global_subject_id'],$body['roles'],$body['attribution']),'handoff solo envía datos permitidos');
        $remote=remoteLink($user,$subject);
        return Illuminate\Support\Facades\Http::response(envelope($body,'PROVISIONED',['link'=>$remote]));
    });
    check(http('POST','/cuenta/jakawi/activar')->isRedirect('https://jakawi.club/dashboard'),'provisión nueva vuelve al dashboard');
    check($user->fresh()->global_subject_id===$subject,'PROVISIONED persiste exactamente UUID emitido por COM');
    $op=App\Models\IdentityHandoff::where('user_id',$user->id)->firstOrFail();
    check($op->request_body===$postBodies[0] && !str_contains(DB::table('club_identity_handoffs')->value('request_body'),$user->email),'outbox cifrado conserva bytes originales');
    $after=$user->fresh()->getRawOriginal();unset($after['global_subject_id']);unset($before['global_subject_id']);
    check($before===$after,'vínculo conserva credenciales roles UUID interno y atribución');
    http('POST','/cuenta/jakawi/activar');
    check(count($postBodies)===1 && App\Models\IdentityHandoff::where('user_id',$user->id)->count()===1,'repetir activación recupera vínculo sin duplicar operación ni provisionar');
    $user->email='changed@example.test';$user->save();
    check(payload(http('GET','/cuenta/jakawi/estado'))['connected'] && $user->fresh()->global_subject_id===$subject,'lookup por UUID conserva vínculo después de cambio de email');
    fakeIdentity(fn()=>Illuminate\Support\Facades\Http::response(remoteLink($user,$subject,'CLUB_EMAIL_ACTIVATION','REVOKED')));
    check(!payload(http('GET','/cuenta/jakawi/estado'))['connected'] && $user->fresh()->global_subject_id===$subject,'revocación deja de mostrar conectado y conserva sujeto histórico');
    try{DB::table('users')->where('id',$user->id)->update(['global_subject_id'=>(string)Illuminate\Support\Str::uuid()]);throw new RuntimeException('Subject changed');}catch(Illuminate\Database\QueryException){check(true,'base de datos impide reasignar un sujeto global');}

    $admin=loginFixture('existing-admin@example.test',true);$adminBefore=$admin->getRawOriginal();
    $recovery=Password::createToken($admin);
    fakeIdentity(fn()=>Illuminate\Support\Facades\Http::response([],404));
    http('POST','/cuenta/jakawi/activar');
    $confirmation=Notification::sent($admin,App\Notifications\ConfirmIdentityEmail::class)->first();
    check($confirmation!==null && App\Models\IdentityHandoff::where('user_id',$admin->id)->count()===0,'administrador histórico recibe confirmación real sin inventar evidencia ni handoff');
    check(Password::tokenExists($admin,$recovery),'confirmación privada no reemplaza recuperación nativa');
    check(http('GET','/cuenta/jakawi/confirmar-email/'.$confirmation->token)->getStatusCode()===200 && !app(App\Services\Identity\ActivationEvidence::class)->find($admin),'visitar enlace no consume token ni autoriza conexión');
    check(http('POST','/cuenta/jakawi/confirmar-email',['token'=>str_repeat('a',64)])->getStatusCode()===410,'rechazo de confirmación de email inválida');
    check(http('POST','/cuenta/jakawi/confirmar-email',['token'=>$confirmation->token])->isRedirect(),'confirmación explícita consume token privado');
    check(http('POST','/cuenta/jakawi/confirmar-email',['token'=>$confirmation->token])->getStatusCode()===410,'token de confirmación no se reutiliza');
    check(Password::tokenExists($admin,$recovery),'confirmación conserva token de recuperación independiente');
    $adminCurrent=$admin->fresh()->getRawOriginal();
    check($adminBefore===$adminCurrent,'confirmación conserva cuenta contraseña y permisos');
    $pendingBody=null;
    fakeIdentity(function($r)use(&$pendingBody){
        if($r->method()==='POST')$pendingBody=json_decode($r->body(),true);
        if(str_contains($r->url(),'/links/'))return Illuminate\Support\Facades\Http::response([],404);
        return Illuminate\Support\Facades\Http::response(envelope($pendingBody,'AUTH_REQUIRED',['authorization_url'=>'https://jakawi.com/identity/club/link/'.$pendingBody['handoff_id'],'expires_at'=>now()->addMinutes(15)->toISOString()]));
    });
    $response=http('POST','/cuenta/jakawi/activar');
    check($response->isRedirect('https://jakawi.com/identity/club/link/'.$pendingBody['handoff_id']),'AUTH_REQUIRED redirige únicamente al consentimiento real de COM');
    check($admin->fresh()->global_subject_id===null && !payload(http('GET','/cuenta/jakawi/estado'))['connected'],'coincidencia email y operación pendiente nunca vinculan');
    $adminSubject=(string)Illuminate\Support\Str::uuid();
    fakeIdentity(fn()=>Illuminate\Support\Facades\Http::response(remoteLink($admin,$adminSubject,'COM_AUTHENTICATED_CONSENT')));
    check(payload(http('GET','/cuenta/jakawi/estado'))['connected'] && $admin->fresh()->global_subject_id===$adminSubject,'solo confirmación autenticada COM completa vínculo existente');
    $adminAfter=$admin->fresh()->getRawOriginal();unset($adminAfter['global_subject_id'],$adminBefore['global_subject_id']);
    check($adminAfter===$adminBefore && $admin->fresh()->is_marketing_admin,'vínculo de administrador conserva todos sus permisos y credenciales');
    $invalidUser=loginFixture('invalid-authorization@example.test');app(App\Services\Identity\ActivationEvidence::class)->record($invalidUser);
    fakeIdentity(function($r){if($r->method()==='GET')return Illuminate\Support\Facades\Http::response([],404);$b=json_decode($r->body(),true);return Illuminate\Support\Facades\Http::response(envelope($b,'AUTH_REQUIRED',['authorization_url'=>'https://evil.example/steal','expires_at'=>now()->addMinutes(15)->toISOString()]));});
    check(http('POST','/cuenta/jakawi/activar')->isRedirect('https://jakawi.club/dashboard') && $invalidUser->fresh()->global_subject_id===null,'rechazo de redirección no autorizada sin persistir vínculo');
    $other=loginFixture('wrong-subject@example.test');app(App\Services\Identity\ActivationEvidence::class)->record($other);
    fakeIdentity(function($r)use($other){if($r->method()==='GET')return Illuminate\Support\Facades\Http::response([],404);$b=json_decode($r->body(),true);$bad=remoteLink($other,(string)Illuminate\Support\Str::uuid());$bad['club_user_id']=(string)Illuminate\Support\Str::uuid();return Illuminate\Support\Facades\Http::response(envelope($b,'LINKED',['link'=>$bad]));});
    http('POST','/cuenta/jakawi/activar');check($other->fresh()->global_subject_id===null,'rechazo de autorización para otra cuenta CLUB');
    $retry=loginFixture('retry@example.test');app(App\Services\Identity\ActivationEvidence::class)->record($retry);
    $attempts=[];$retrySubject=(string)Illuminate\Support\Str::uuid();
    fakeIdentity(function($r)use($retry,$retrySubject,&$attempts){if($r->method()==='GET')return Illuminate\Support\Facades\Http::response([],404);$attempts[]=['body'=>$r->body(),'nonce'=>$r->header('X-Jakawi-Nonce')[0]];if(count($attempts)===1)return Illuminate\Support\Facades\Http::failedConnection();return Illuminate\Support\Facades\Http::response(envelope(json_decode($r->body(),true),'PROVISIONED',['link'=>remoteLink($retry,$retrySubject)]));});
    http('POST','/cuenta/jakawi/activar');
    check(count($attempts)===2 && $attempts[0]['body']===$attempts[1]['body'] && $attempts[0]['nonce']!==$attempts[1]['nonce'],'reintento de transporte conserva bytes y renueva nonce');
    check($retry->fresh()->global_subject_id===$retrySubject && App\Models\IdentityHandoff::where('user_id',$retry->id)->count()===1,'reintento no duplica operación y conserva UUID');
    $limited=loginFixture('rate-limited@example.test');app(App\Services\Identity\ActivationEvidence::class)->record($limited);$posts=[];$success=false;
    fakeIdentity(function($r)use($limited,&$posts,&$success){if($r->method()==='GET')return Illuminate\Support\Facades\Http::response([],404);$posts[]=$r->body();return $success?Illuminate\Support\Facades\Http::response(envelope(json_decode($r->body(),true),'PROVISIONED',['link'=>remoteLink($limited,(string)Illuminate\Support\Str::uuid())])):Illuminate\Support\Facades\Http::response([],429,['Retry-After'=>'120']);});
    http('POST','/cuenta/jakawi/activar');http('POST','/cuenta/jakawi/activar');
    check(count($posts)===1 && App\Models\IdentityHandoff::where('user_id',$limited->id)->first()->status==='RETRY','429 respeta Retry-After y conserva operación durable');
    Carbon::setTestNow(now()->addSeconds(121));$success=true;http('POST','/cuenta/jakawi/activar');
    check(count($posts)===2 && $posts[0]===$posts[1],'reintento posterior al backoff conserva prueba y consentimiento originales');Carbon::setTestNow();
    $conflict=loginFixture('conflict@example.test');app(App\Services\Identity\ActivationEvidence::class)->record($conflict);
    fakeIdentity(function($r){if($r->method()==='GET')return Illuminate\Support\Facades\Http::response([],404);$body=json_decode($r->body(),true);return Illuminate\Support\Facades\Http::response(['contract'=>'jakawi-identity-handoff-v1','handoff_id'=>$body['handoff_id'],'status'=>'CONFLICT','error'=>'IDEMPOTENCY_CONFLICT'],409);});
    http('POST','/cuenta/jakawi/activar');check($conflict->fresh()->global_subject_id===null && App\Models\IdentityHandoff::where('user_id',$conflict->id)->first()->status==='CONFLICT','conflicto mínimo real de COM se conserva sin vincular');
    $csrf=loginFixture('csrf@example.test');$app->instance('env','production');
    check(http('POST','/cuenta/jakawi/activar')->getStatusCode()===419,'POST identidad exige CSRF real fuera del bypass de pruebas');$app->instance('env','testing');
    $browserReady=$dashboard;$browserReady['props']['identity']=['status'=>'READY','connected'=>false,'can_activate'=>true,'needs_email_confirmation'=>false,'authorization_url'=>null,'retry_at'=>null,'message'=>'Activa tu acceso a JAKAWI.COM cuando lo decidas.'];
    file_put_contents('/evidence/identity-browser-fixtures.json',json_encode(['dashboard'=>$browserReady],JSON_PRETTY_PRINT));
    $unverified=loginFixture('unverified@example.test');$unverified->email_verified_at=null;$unverified->save();
    check(in_array(http('POST','/cuenta/jakawi/activar')->getStatusCode(),[403,409],true),'cuenta sin correo verificado no inicia identidad');
    $result=['passed'=>count($passed),'postgresql'=>'isolated schema','com_responses'=>'contract fixtures','live_users_created'=>0,'notifications'=>'fake'];
    file_put_contents('/evidence/identity-tests.json',json_encode($result,JSON_PRETTY_PRINT).PHP_EOL);echo json_encode($result).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'FAILED '.get_class($e).' '.$e->getMessage().' at '.$e->getLine().PHP_EOL);$failed=true;}
finally{Carbon::setTestNow();DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');}
exit(isset($failed)?1:0);
