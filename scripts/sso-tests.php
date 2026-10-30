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
$schema = 'sso_test_'.bin2hex(random_bytes(6));
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

function comRequest(string $method,string $path,string $raw='',array $headers=[],?array &$cookies=null):array {
    $lines=['Host: jakawi.com'];foreach($headers as $k=>$v)$lines[]=$k.': '.(is_array($v)?implode(',',$v):$v);
    if($cookies){$parts=[];foreach($cookies as $k=>$v)$parts[]=$k.'='.$v;$lines[]='Cookie: '.implode('; ',$parts);}
    $ctx=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$lines),'content'=>$raw,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>10]]);
    $body=file_get_contents('http://jakawi-club-sso-test-com:8080'.$path,false,$ctx);$responseHeaders=$http_response_header??[];
    preg_match('/\s(\d{3})\s/',$responseHeaders[0]??'',$match);$status=(int)($match[1]??0);
    if($cookies!==null)foreach($responseHeaders as $line)if(preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i',$line,$m))$cookies[$m[1]]=$m[2];
    return [$status,$body===false?'':$body,$responseHeaders];
}
function comForm(string $path,array $data,array &$cookies):array {
    $headers=['Content-Type'=>'application/x-www-form-urlencoded','Accept'=>'text/html'];
    if(isset($cookies['XSRF-TOKEN']))$headers['X-XSRF-TOKEN']=urldecode($cookies['XSRF-TOKEN']);
    return comRequest('POST',$path,http_build_query($data),$headers,$cookies);
}
try{
    DB::statement('CREATE SCHEMA '.$schema);
    config(['database.connections.pgsql.search_path'=>$schema,'jakawi.registration_open'=>true,'jakawi.transactional_mail_ready'=>true,
        'mail.default'=>'smtp','mail.mailers.smtp.host'=>'smtp.example.test','mail.from.address'=>'info@example.test',
        'oidc.enabled'=>true,'oidc.client_id'=>'8898bf42-9f49-4cc8-8b79-6496d93d2536','oidc.client_secret'=>'isolated-confidential-client-secret-000000000000','identity.enabled'=>true,'identity.base_url'=>'https://jakawi.com/internal/identity','identity.key_id'=>'club-test-v1',
        'identity.secret'=>str_repeat('s',64),'identity.proof_secret'=>str_repeat('p',64)]);
    DB::purge();Artisan::call('migrate',['--force'=>true]);Notification::fake();
    Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory);
    Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Http::fake(function($r){
        if(!str_starts_with($r->url(),'https://jakawi.com/'))throw new RuntimeException('Unexpected transport target');
        $headers=$r->headers();unset($headers['Host']);
        if($r->hasHeader('Cookie'))throw new RuntimeException('API must not send cookies');
        [$code,$body]=comRequest($r->method(),parse_url($r->url(),PHP_URL_PATH),$r->body(),$headers);
        return Illuminate\Support\Facades\Http::response($body,$code,['Content-Type'=>'application/json']);
    });
    function nativeActivatedClub(string $email):User {
        freshBrowser();app('cache')->flush();
        check(http('POST','/registro',registration($email))->isRedirect(),'alta CLUB aislada conserva formulario nativo');
        $user=User::where('email',$email)->firstOrFail();$a=Notification::sent($user,ActivateAccount::class)->first();
        check(http('POST','/activar',['email'=>$email,'token'=>$a->token,'password'=>'FixtureClubPassword123','password_confirmation'=>'FixtureClubPassword123'])->isRedirect(),'activación CLUB consume token real simulado por notificación');
        return $user->fresh();
    }
    $new=nativeActivatedClub('new-com-account@example.test');
    check(http('POST','/cuenta/jakawi/activar')->isRedirect('https://jakawi.club/dashboard'),'cliente CLUB provisiona contra autoridad COM aislada real');
    $subject=$new->fresh()->global_subject_id;check(Illuminate\Support\Str::isUuid($subject),'UUID generado realmente por COM persiste en CLUB');
    $op=App\Models\IdentityHandoff::where('user_id',$new->id)->firstOrFail();
    $again=app(App\Services\Identity\ComIdentityClient::class)->create($op->request_body);
    check($again['status']==='PROVISIONED' && $again['link']['global_subject_id']===$subject,'repetir bytes contra COM real mantiene resultado idempotente');
    http('POST','/cuenta/jakawi/activar');check(App\Models\IdentityHandoff::where('user_id',$new->id)->count()===1,'segunda acción CLUB no genera otro handoff');
    $invalid=comRequest('GET','/internal/identity/links/'.$new->uuid,'',['Accept'=>'application/json','Content-Type'=>'application/json','X-Jakawi-Signature'=>str_repeat('0',64)]);
    check($invalid[0]===401,'COM real rechaza autenticación de integración inválida');
    $existing=nativeActivatedClub('existing-com@example.test');$existing->is_marketing_admin=true;$existing->save();$before=$existing->fresh()->getRawOriginal();
    $redirect=http('POST','/cuenta/jakawi/activar');$pending=App\Models\IdentityHandoff::where('user_id',$existing->id)->firstOrFail();$path='/identity/club/link/'.$pending->id;
    check($redirect->isRedirect('https://jakawi.com'.$path) && $pending->status==='AUTH_REQUIRED' && $existing->fresh()->global_subject_id===null,'cuenta COM existente exige autorización real sin vincular por email');
    $cookies=[];check(comRequest('GET',$path,'',['Accept'=>'text/html'],$cookies)[0]===302,'consentimiento COM exige login nativo');
    comRequest('GET','/login','',['Accept'=>'text/html'],$cookies);
    check(comRequest('POST','/login',http_build_query(['email'=>'existing-com@example.test','password'=>'FixtureComPassword123']),['Content-Type'=>'application/x-www-form-urlencoded','Accept'=>'text/html'],$cookies)[0]===419,'login COM aislado valida CSRF real');
    check(comForm('/login',['email'=>'other-com@example.test','password'=>'FixtureComPassword123'],$cookies)[0]===302,'otra cuenta accede con contraseña nativa en COM aislado');
    check(comRequest('GET',$path,'',['Accept'=>'text/html'],$cookies)[0]===403 && comForm($path,['authorize_link'=>'1'],$cookies)[0]===403,'COM real rechaza autorización del titular equivocado');
    $cookies=[];comRequest('GET','/login','',['Accept'=>'text/html'],$cookies);
    check(comForm('/login',['email'=>'existing-com@example.test','password'=>'FixtureComPassword123'],$cookies)[0]===302,'titular fixture realiza login completo real en COM aislado');
    [$code,$page]=comRequest('GET',$path,'',['Accept'=>'text/html'],$cookies);
    check($code===200 && str_contains($page,'Autorizar vinculación con CLUB'),'GET de consentimiento muestra acción sin autorizar');
    check(!payload(http('GET','/cuenta/jakawi/estado'))['connected'],'CLUB sigue pendiente antes del consentimiento explícito');
    check(comForm($path,[],$cookies)[0]===302 && !payload(http('GET','/cuenta/jakawi/estado'))['connected'],'COM real no vincula sin consentimiento enviado');
    check(comForm($path,['authorize_link'=>'1'],$cookies)[0]===302,'titular fixture autoriza mediante POST COM con CSRF nativo');
    check(payload(http('GET','/cuenta/jakawi/estado'))['connected'] && Illuminate\Support\Str::isUuid($existing->fresh()->global_subject_id),'CLUB recupera y persiste vínculo realmente autorizado por COM');
    $after=$existing->fresh()->getRawOriginal();unset($after['global_subject_id'],$before['global_subject_id']);
    check($before===$after && $existing->fresh()->is_marketing_admin,'identidad compartida conserva contraseña referente y rol CLUB');
    require '/tests/sso-flow-checks.php';
    $result=['passed'=>count($passed),'com_implementation'=>'deployed immutable COM image in disposable containers','com_sha'=>'61af05e4a0b153cfa9970db435bb467bc6971d4c','com_database'=>'dedicated disposable PostgreSQL','club_database'=>'isolated schema','live_users_created'=>0,'pilot_authorization_simulated'=>false];
    file_put_contents('/evidence/sso-tests.json',json_encode($result,JSON_PRETTY_PRINT).PHP_EOL);echo json_encode($result).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'FAILED '.get_class($e).' '.$e->getMessage().' at '.$e->getLine().PHP_EOL);$failed=true;}
finally{DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');}
exit(isset($failed)?1:0);
