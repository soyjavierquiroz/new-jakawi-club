<?php

// Real HTTP kernel, real migrations, private PostgreSQL schema; no LIVE fixtures.
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

chdir('/var/www/html');
foreach (['logs', 'framework/views', 'framework/sessions', 'framework/cache/data'] as $dir) {
    @mkdir('storage/'.$dir, 0777, true);
}
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.connections.pgsql.database') !== 'jakawi_club') {
    throw new RuntimeException('Test isolation required.');
}
$schema = 'results_test_'.bin2hex(random_bytes(6));
$passed = [];
$cookies = [];
$secret = str_repeat('c', 64);
file_put_contents('/tmp/commercial-test-secret', $secret);

function check(bool $condition, string $name): void {
    global $passed;
    if (! $condition) throw new RuntimeException('FAILED: '.$name);
    $passed[] = $name; echo 'PASS '.$name.PHP_EOL;
}
function kernelRequest(Request $request): Symfony\Component\HttpFoundation\Response {
    global $app;
    $kernel = $app->make(Kernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    return $response;
}
function send(mixed $body, array $headers = [], string $path = '/internal/commercial/events', ?string $key = null, ?int $time = null): Symfony\Component\HttpFoundation\Response {
    global $secret;
    $raw = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);
    $time ??= time(); $key ??= $secret;
    $server = array_merge(['HTTP_ACCEPT'=>'application/json', 'CONTENT_TYPE'=>'application/json',
        'HTTP_X_JAKAWI_COM_KEY_ID'=>'com-commercial-v1', 'HTTP_X_JAKAWI_COM_TIMESTAMP'=>(string)$time,
        'HTTP_X_JAKAWI_COM_SIGNATURE'=>'sha256='.hash_hmac('sha256', $time."\nPOST\n/internal/commercial/events\n".hash('sha256', $raw), $key)], $headers);
    return kernelRequest(Request::create('https://jakawi.club'.$path, 'POST', [], [], [], $server, $raw));
}
function payload(Symfony\Component\HttpFoundation\Response $r): array {
    return json_decode($r->getContent(), true, 512, JSON_THROW_ON_ERROR);
}
function web(string $method, string $path, array $data = [], bool $inertia = true, array $extraHeaders = []): Symfony\Component\HttpFoundation\Response {
    global $cookies;
    Auth::forgetGuards();
    $server = ['HTTP_ACCEPT'=>'text/html', 'REMOTE_ADDR'=>'192.0.2.30'];
    if ($inertia) {
        $server['HTTP_X_INERTIA']='true';
        $server['HTTP_X_INERTIA_VERSION']=(new App\Http\Middleware\HandleInertiaRequests)->version(Request::create('/'));
    }
    $r = kernelRequest(Request::create('https://jakawi.club'.$path, $method, $data, $cookies, [], array_merge($server,$extraHeaders)));
    foreach ($r->headers->getCookies() as $cookie) $cookies[$cookie->getName()]=$cookie->getValue();
    foreach (app('cookie')->getQueuedCookies() as $cookie) app('cookie')->unqueue($cookie->getName(),$cookie->getPath());
    return $r;
}
function login(bool $admin, bool $verified = true, bool $activated = true): User {
    global $cookies;
    app('cache')->flush(); // Each scenario represents an independent browser, not a rate-limit test.
    $cookies=[]; Auth::forgetGuards(); app('session')->flush(); app('session')->regenerate();
    $user=User::create(['name'=>'Commercial fixture', 'email'=>bin2hex(random_bytes(5)).'@example.test', 'password'=>'FixturePassword123']);
    $user->forceFill(['activated_at'=>$activated?now():null,'email_verified_at'=>$verified?now():null,'is_marketing_admin'=>$admin])->save();
    web('POST','/login',['email'=>$user->email,'password'=>'FixturePassword123'],false);
    return $user->fresh();
}


function b64(string $s): string {return rtrim(strtr(base64_encode($s),'+/','-_'),'=');}
function oidc(string $subject, bool $verified, string $email = 'free@example.test', bool $partner = false): Symfony\Component\HttpFoundation\Response {
    global $cookies, $claims;
    app('cache')->flush(); // Each scenario represents an independent browser, not a rate-limit test.
    $cookies=[]; Auth::forgetGuards(); app('session')->flush(); app('session')->regenerate();
    $r=web('GET',$partner?'/afiliados/sso':'/auth/jakawi',[],false);
    if (!$r->isRedirect()) fwrite(STDERR, 'SSO start status '.$r->getStatusCode().PHP_EOL);
    check($r->isRedirect() && str_starts_with($r->headers->get('Location'),'https://jakawi.com/oauth/authorize'), 'OIDC inicia en COM');
    $pending=json_decode(Illuminate\Support\Facades\Crypt::decryptString(app('session')->get('jakawi_oidc')),true);
    $claims=['iss'=>'https://jakawi.com','sub'=>$subject,'aud'=>config('oidc.client_id'),'iat'=>time(),'exp'=>time()+3600,'nonce'=>$pending['values']['openid_connect_nonce'],'email'=>$email,'email_verified'=>$verified,'name'=>'Drex fixture'];
    return web('GET','/auth/jakawi/callback?code=isolated-code&state='.$pending['values']['openid_connect_state'],[],false);
}
function dashboard(): array {
    global $mode, $browserFixtures;
    $r=web('GET','/mi-cuenta');
    check($r->getStatusCode()===200,'mi-cuenta accesible tras retorno COM');
    $browserFixtures[$mode]=payload($r);
    file_put_contents('/evidence/fixtures.json',json_encode($browserFixtures,JSON_THROW_ON_ERROR));
    return payload($r)['props'];
}
try {
 DB::statement('CREATE SCHEMA '.$schema);
 config(['database.connections.pgsql.search_path'=>$schema,'identity.enabled'=>true,
 'oidc.enabled'=>true,'oidc.client_id'=>'8898bf42-9f49-4cc8-8b79-6496d93d2536','oidc.client_secret'=>str_repeat('x',64),'purchases.secret'=>str_repeat('h',64)]);
 DB::purge('pgsql');Artisan::call('migrate',['--force'=>true]);Notification::fake();
 app('router')->setCompiledRoutes(app('router')->getRoutes()->compile());
 $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);$rsa=openssl_pkey_get_details($key)['rsa'];
 $subject=(string)Illuminate\Support\Str::uuid();$claims=[];$calls=[];$mode='free';$badNonce=false;
 Http::swap(new Illuminate\Http\Client\Factory);Http::preventStrayRequests();
 Http::fake(function($request) use(&$claims,$key,$rsa,&$calls,&$mode,&$badNonce){
  if($request->url()==='https://jakawi.com/oauth/jwks') return Http::response(['keys'=>[['kty'=>'RSA','use'=>'sig','alg'=>'RS256','kid'=>'isolated','n'=>b64($rsa['n']),'e'=>b64($rsa['e'])]]]);
  if($request->url()==='https://jakawi.com/oauth/token'){
   $signed=$claims;if($badNonce)$signed['nonce']='wrong';
   $unsigned=b64(json_encode(['alg'=>'RS256','kid'=>'isolated'])).'.'.b64(json_encode($signed));openssl_sign($unsigned,$signature,$key,OPENSSL_ALGO_SHA256);
   return Http::response(['token_type'=>'Bearer','access_token'=>'isolated-access-token','id_token'=>$unsigned.'.'.b64($signature)]);
  }
  if($request->url()!=='https://jakawi.com/internal/club/memberships/status') throw new RuntimeException('Unexpected request');
  $calls[]=$request;
  $timestamp=$request->header('X-Jakawi-Timestamp')[0];$nonce=$request->header('X-Jakawi-Nonce')[0];
  $path='/internal/club/memberships/status';
  $canonical=implode("\n",['jakawi-club-purchases-v1','club-purchases-v1',$timestamp,$nonce,'POST',$path,hash('sha256',$request->body())]);
  check(hash_equals(hash_hmac('sha256',$canonical,config('purchases.secret')),$request->header('X-Jakawi-Signature')[0]),'consulta membresía firmada con HMAC existente');
  if($mode==='down')throw new Illuminate\Http\Client\ConnectionException;
  $type=in_array($mode,['pioneer','expired','scheduled'],true)?'pioneer':($mode==='standard'?'standard':'free');
  $active=in_array($mode,['pioneer','standard'],true);
  return Http::response(['contract'=>'jakawi-com-membership-status-v1','global_subject_id'=>$mode==='mismatch'?(string)Illuminate\Support\Str::uuid():$request['global_subject_id'],
   'membership'=>['type'=>$type,'status'=>$active?'active':(in_array($mode,['expired','scheduled'],true)?$mode:'free'),'is_active'=>$active,
   'starts_at'=>$type==='free'?null:'2026-11-01T00:00:00Z','effective_ends_at'=>$type==='standard'?'2027-11-01T00:00:00Z':null,
   'expiry_policy'=>$type==='pioneer'?'official_launch_plus_15_calendar_months':($type==='standard'?'fixed':null),'official_launch_date'=>null,
   'benefits'=>['public_catalog'=>true,'club_access'=>true,'exclusive_member_benefits'=>$active]]]);
 });
 // Same signed identity reaches the old projection guard before the fix.
 if (is_file('/evidence/legacy-sso-account.php')) {
  $legacy=file_get_contents('/evidence/legacy-sso-account.php');$legacy=str_replace('final class SsoAccount','final class LegacySsoAccount',$legacy);eval(substr($legacy,5));
  try {(new App\Services\Identity\LegacySsoAccount)->resolve($subject,['sub'=>$subject,'email'=>'free@example.test','email_verified'=>false]);check(false,'legacy must reject');}
  catch(RuntimeException $e){check($e->getMessage()==='Verified SSO profile required','reproducción exacta: guard legacy rechaza email_verified=false');}
 }
 // Existing certified Dossis attribution must survive every login and status read.
 $person=(string)Illuminate\Support\Str::uuid();DB::table('acquisition_people')->insert(['id'=>$person,'beneficiary_type'=>'PARTNER','beneficiary_id'=>'1','fixed_at'=>now(),'created_at'=>now()]);
 DB::table('acquisition_aliases')->insert(['kind'=>'subject','reference'=>$subject,'person_id'=>$person]);
 $origin=hash('sha256',json_encode(DB::table('acquisition_people')->get()).json_encode(DB::table('acquisition_aliases')->get()));
 $r=oidc($subject,false);check($r->headers->get('Location')==='https://jakawi.club/dashboard','gratuito no verificado completa callback');
 $user=User::where('global_subject_id',$subject)->firstOrFail();check(!$user->hasVerifiedEmail() && $user->password===null,'login no inventa verificación ni contraseña');
 check(count($calls)===0,'callback independiente de pago y consulta membresía');
 $p=dashboard();check($p['com_membership']['type']==='free'&&!$p['membership_activated']&&!$p['email_verified'],'Gratuito sin beneficios exclusivos');
 check(web('PATCH','/perfil',['name'=>'Changed'])->isRedirect(),'edición sensible conserva verified');
 check(web('POST','/cuenta/beneficios')->isRedirect(),'acceso sensible conserva verified');
 $count=User::count();oidc($subject,false);check(User::count()===$count,'retorno repetido reutiliza sub sin duplicados');
 $r=oidc($subject,false,'changed@example.test');check(User::count()===$count&&$user->fresh()->email==='free@example.test','cambio de email no cambia identidad ni enlaza otra cuenta');
 foreach(['pioneer','standard','expired','scheduled'] as $mode){$p=dashboard();check($p['com_membership']['available']&&$p['membership_activated']===in_array($mode,['pioneer','standard'],true),'estado efectivo '.$mode);}
 foreach(['down','mismatch'] as $mode){$p=dashboard();check(!$p['com_membership']['available']&&!$p['membership_activated'],'error '.$mode.' bloquea beneficios sin impedir acceso');}
 $mode='free';$other=(string)Illuminate\Support\Str::uuid();oidc($other,false,'free@example.test');$projection=User::where('global_subject_id',$other)->firstOrFail();
 check(User::count()===$count+1&&$projection->email===null&&$user->fresh()->global_subject_id===$subject,'coincidencia de correo no vincula cuentas distintas');dashboard();
 oidc($subject,true);check($user->fresh()->hasVerifiedEmail(),'claim verificado posterior acredita mismo correo sin duplicación');
 $badNonce=true;$before=User::count();$r=oidc((string)Illuminate\Support\Str::uuid(),false,'bad@example.test');check($r->headers->get('Location')==='https://jakawi.club/login'&&User::count()===$before,'nonce inválido sigue rechazado');$badNonce=false;
 $r=oidc($subject,false,'free@example.test',true);check($r->headers->get('Location')==='https://jakawi.club/afiliados','retorno Partner COM conserva destino local');
 check($origin===hash('sha256',json_encode(DB::table('acquisition_people')->get()).json_encode(DB::table('acquisition_aliases')->get())),'origen Dossis 43 Partner 1 inmutable');
 check(DB::table('commission_ledger')->count()===0 && DB::table('commercial_events')->count()===0,'registro SSO no genera comisión ni evento económico');
 check(DB::table('membership_purchases')->count()===0&&DB::table('jobs')->count()===0,'login y consultas no crean compras ni entregas CRM');
 file_put_contents('/evidence/tests.json',json_encode(['isolated_schema'=>true,'no_live_fixtures'=>true,'passed'=>$passed],JSON_PRETTY_PRINT));
} finally {DB::disconnect('pgsql');config(['database.connections.pgsql.search_path'=>'public']);DB::purge('pgsql');DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');}
