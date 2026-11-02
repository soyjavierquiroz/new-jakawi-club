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
    $cookies=[]; Auth::forgetGuards(); app('session')->flush(); app('session')->regenerate();
    $user=User::create(['name'=>'Commercial fixture', 'email'=>bin2hex(random_bytes(5)).'@example.test', 'password'=>'FixturePassword123']);
    $user->forceFill(['activated_at'=>$activated?now():null,'email_verified_at'=>$verified?now():null,'is_marketing_admin'=>$admin])->save();
    web('POST','/login',['email'=>$user->email,'password'=>'FixturePassword123'],false);
    return $user->fresh();
}


function b64(string $s): string {return rtrim(strtr(base64_encode($s),'+/','-_'),'=');}
function sso(string $subject,string $email='owner@example.test',bool $newBrowser=true): void {
 global $cookies,$ssoClaims;
 if($newBrowser){$cookies=[];Auth::forgetGuards();app('session')->flush();app('session')->regenerate();}
 $response=web('GET','/afiliados/sso',[],false);
 check($response->isRedirect() && str_starts_with($response->headers->get('Location'),'https://jakawi.com/oauth/authorize'),'SSO usa proveedor existente sin formulario Partner');
 $pending=json_decode(Illuminate\Support\Facades\Crypt::decryptString(app('session')->get('jakawi_oidc')),true);
 $ssoClaims=['iss'=>'https://jakawi.com','sub'=>$subject,'aud'=>config('oidc.client_id'),'iat'=>time(),'exp'=>time()+3600,'nonce'=>$pending['values']['openid_connect_nonce'],'email'=>$email,'email_verified'=>true,'name'=>'Owner COM'];
 $response=web('GET','/auth/jakawi/callback?code=isolated-code&state='.$pending['values']['openid_connect_state'],[],false);
 check($response->isRedirect() && $response->headers->get('Location')==='https://jakawi.club/afiliados','callback SSO regresa a afiliados sin segunda inscripción');
}
function panels(): array {
 $response=web('GET','/afiliados?tab=results&global_subject_id=00000000-0000-4000-8000-000000000001');
 check($response->getStatusCode()===200,'área personal disponible con ruta directa');
 check(str_contains($response->headers->get('Cache-Control'),'no-store'),'resultados no almacenables HTTP');
 return payload($response)['props'];
}
try {
 DB::statement('CREATE SCHEMA '.$schema);
 config(['database.connections.pgsql.search_path'=>$schema,'identity.enabled'=>false,
 'oidc.enabled'=>true,'oidc.client_id'=>'8898bf42-9f49-4cc8-8b79-6496d93d2536','oidc.client_secret'=>str_repeat('x',64),'purchases.secret'=>str_repeat('h',64)]);
 DB::purge('pgsql');Artisan::call('migrate',['--force'=>true]);Notification::fake();
 app('router')->setCompiledRoutes(app('router')->getRoutes()->compile());
 app(Illuminate\Contracts\Debug\ExceptionHandler::class)->reportable(function(Throwable $e): void {fwrite(STDERR,get_class($e).' '.explode(' (SQL:', $e->getMessage())[0].PHP_EOL);});
 $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);$rsa=openssl_pkey_get_details($key)['rsa'];
 $subject=(string)Illuminate\Support\Str::uuid();$ssoClaims=[];$calls=[];$mode='valid';$rows=[];$returnedSubject=null;
 Http::swap(new Illuminate\Http\Client\Factory);Http::preventStrayRequests();
 Http::fake(function($request) use(&$ssoClaims,$key,$rsa,&$calls,&$mode,&$rows,&$returnedSubject,&$subject){
  if($request->url()==='https://jakawi.com/oauth/jwks')return Http::response(['keys'=>[['kty'=>'RSA','use'=>'sig','alg'=>'RS256','kid'=>'isolated','n'=>b64($rsa['n']),'e'=>b64($rsa['e'])]]]);
  if($request->url()==='https://jakawi.com/oauth/token'){
   $unsigned=b64(json_encode(['alg'=>'RS256','kid'=>'isolated'])).'.'.b64(json_encode($ssoClaims));openssl_sign($unsigned,$signature,$key,OPENSSL_ALGO_SHA256);
   return Http::response(['token_type'=>'Bearer','access_token'=>'isolated-access-token','id_token'=>$unsigned.'.'.b64($signature)]);
  }
  if($request->url()!=='https://jakawi.com/internal/club/partners/access')throw new RuntimeException('Unexpected request');
  $calls[]=$request;
  $time=$request->header('X-Jakawi-Timestamp')[0];$nonce=$request->header('X-Jakawi-Nonce')[0];
  $canonical=implode("\n",['jakawi-club-purchases-v1','club-purchases-v1',$time,$nonce,'POST','/internal/club/partners/access',hash('sha256',$request->body())]);
  check($request->method()==='POST' && $request->body()===json_encode(['global_subject_id'=>$subject],JSON_UNESCAPED_SLASHES),'sujeto procede de SSO e ignora UUID del navegador');
  check($request->header('X-Jakawi-Key-Id')===['club-purchases-v1'] && abs(time()-(int)$time)<=300 && Illuminate\Support\Str::isUuid($nonce),'key timestamp y nonce conforme contrato');
  check(hash_equals(hash_hmac('sha256',$canonical,config('purchases.secret')),$request->header('X-Jakawi-Signature')[0]),'firma HMAC cubre método path y bytes exactos');
  if($mode==='down')throw new Illuminate\Http\Client\ConnectionException;
  if($mode==='401')return Http::response([],401);
  if($mode==='invalid')return Http::response('{bad',200);
  return Http::response(['global_subject_id'=>$returnedSubject??$subject,'partners'=>$rows]);
 });
 $id=DB::table('affiliate_partners')->insertGetId(['source'=>'jakawi.com','partner_id'=>'1','name'=>'Dossis 43','status'=>'enabled','com_url'=>'https://jakawi.com/dossis43','code'=>str_repeat('D',24),'created_at'=>now(),'updated_at'=>now()]);
 $base=['id'=>1,'name'=>'Dossis 43','status'=>'published','role'=>'owner','economic_access'=>true];$rows=[$base];
 $guest=web('GET','/afiliados',[],false);if(!$guest->isRedirect()||!str_starts_with($guest->headers->get('Location')??'','https://jakawi.com/oauth/authorize'))fwrite(STDERR,'Guest '.$guest->getStatusCode().' '.$guest->headers->get('Location').PHP_EOL);check($guest->isRedirect()&&str_starts_with($guest->headers->get('Location'),'https://jakawi.com/oauth/authorize'),'entrada directa COM inicia OIDC automáticamente');
 sso($subject);$owner=User::where('global_subject_id',$subject)->firstOrFail();
 check($owner->password===null && $owner->sso_provisioned_at!==null && $owner->activated_at!==null,'proyección SSO interna sin contraseña');
 check(DB::table('jobs')->where('queue','crm')->count()===0,'proyección SSO sin entregas CRM');
 $count=User::count();sso($subject);check(User::count()===$count,'SSO repetido reutiliza sujeto sin otra cuenta');
 $props=panels();check(count($props['partner_panels'])===1,'owner autorizado ve Dossis habilitado');
 $before=count($calls);$request=app('request');$service=app(App\Services\PartnerEconomicAccess::class);$service->partners($request,$owner);$service->partners($request,$owner);check(count($calls)===$before,'sin llamadas redundantes dentro de una solicitud');
 DB::table('affiliate_partner_access')->insert(['partner'=>$id,'user_id'=>$owner->id,'authorized_by'=>$owner->uuid,'authorized_at'=>now()]);
 foreach ([['manager',true,true],['manager',false,false],['staff',true,false],['owner',false,false]] as [$role,$allow,$expected]) {
  $rows=[[...$base,'role'=>$role,'economic_access'=>$allow]];$p=panels();check((count($p['partner_panels'])===1)===$expected,'permiso '.$role.' economic_access='.($allow?'true':'false'));
 }
 foreach(['draft','paused','archived'] as $state){$rows=[[...$base,'status'=>$state]];check(panels()['partner_panels']===[],'estado '.$state.' denegado aunque permiso sea true');}
 $rows=[];check(panels()['partner_panels']===[],'vínculo revocado supera autorización manual histórica');
 $rows=[$base];$returnedSubject=(string)Illuminate\Support\Str::uuid();check(panels()['partner_panels']===[],'respuesta de otro sujeto denegada');$returnedSubject=null;
 foreach(['down','401','invalid'] as $failure){$mode=$failure;$before=count($calls);$p=panels();check($p['partner_panels']===[]&&$p['partner_access_unavailable'],'COM '.$failure.' deniega negocio y conserva personal');check(count($calls)===$before+1,'una llamada por acceso incluso ante error');}$mode='valid';
 $rows=[[...$base,'economic_access'=>'true']];check(panels()['partner_panels']===[],'booleano no estricto denegado');
 $rows=[$base,$base];check(panels()['partner_panels']===[],'respuesta duplicada inválida denegada');
 $rows=[$base];DB::table('affiliate_partners')->where('id',$id)->update(['status'=>'disabled']);check(panels()['partner_panels']===[],'Partner deshabilitado CLUB denegado');DB::table('affiliate_partners')->where('id',$id)->update(['status'=>'enabled']);
 DB::table('affiliate_partners')->insert(['source'=>'jakawi.com','partner_id'=>'2','name'=>'Segundo establecimiento','status'=>'enabled','com_url'=>'https://jakawi.com/segundo','code'=>str_repeat('S',24),'created_at'=>now(),'updated_at'=>now()]);
 $rows=[$base,[...$base,'id'=>2,'name'=>'Segundo establecimiento']];$p=panels();check(count($p['partner_panels'])===2,'varios establecimientos autorizados');
 // Distinct economic beneficiaries: personal referrer receives no Partner leads.
 $personId=(string)Illuminate\Support\Str::uuid();
 DB::table('acquisition_people')->insert(['id'=>$personId,'beneficiary_type'=>'PARTNER','beneficiary_id'=>'1','created_at'=>now()]);
 DB::table('acquisition_aliases')->insert(['kind'=>'subject','reference'=>(string)Illuminate\Support\Str::uuid(),'person_id'=>$personId]);
 $personalLead=new User(['name'=>'Personal lead','email'=>'personal-lead@example.test']);$personalLead->referred_by_id=$owner->id;$personalLead->save();
 $p=panels();check($p['results']['leads_captured']===1 && $p['partner_panels'][0]['leads']===1,'usuario personal y Partner tiene resultados separados por beneficiario');
 file_put_contents('/evidence/partner-browser-fixture.json',json_encode(['component'=>'Affiliates','props'=>$p,'url'=>'/afiliados?tab=results','version'=>null],JSON_THROW_ON_ERROR));
 $rows=[];check(panels()['partner_panels']===[],'usuario sin Partners conserva área personal');
 $before=count($calls);$partial=payload(web('GET','/afiliados?tab=results',[],true,['HTTP_X_INERTIA_PARTIAL_COMPONENT'=>'Affiliates','HTTP_X_INERTIA_PARTIAL_DATA'=>'results']));
 check(isset($partial['props']['partner_panels']) && $partial['props']['partner_panels']===[] && count($calls)===$before+1,'recarga parcial Inertia revalida y retira datos empresariales revocados');
 $owner->password='FixturePassword123';$owner->save();
 $cookies=[];Auth::forgetGuards();app('session')->flush();app('session')->regenerate();
 web('POST','/login',['email'=>$owner->email,'password'=>'FixturePassword123'],false);$before=count($calls);
 check(panels()['partner_panels']===[] && count($calls)===$before,'sesión local con sujeto vinculado exige acreditar SSO para acceso empresarial');
 sso($subject,'owner@example.test',false);$rows=[$base];check(count(panels()['partner_panels'])===1,'sesión personal vinculada accede mediante SSO sin otra cuenta');
 // Real local login must not inherit SSO provenance.
 $local=login(false);$before=count($calls);check(panels()['partner_panels']===[] && count($calls)===$before,'login local sin SSO no consulta por UUID no acreditado');
 // Verified email coincidence must never attach the historical local account.
 $previous=$local->global_subject_id;$subject=(string)Illuminate\Support\Str::uuid();sso($subject,$local->email);
 $projection=User::where('global_subject_id',$subject)->firstOrFail();check($projection->id!==$local->id&&$projection->email===null&&$local->fresh()->global_subject_id===$previous,'correo coincidente no vincula ni reemplaza cuenta histórica');
 config(['identity.enabled'=>true]);
 check(web('GET','/dashboard')->getStatusCode()===200,'proyección SSO puede consultar área personal');
 $state=app(App\Services\Identity\IdentityLink::class)->state($projection);
 check($state['connected'] && !$state['can_activate'],'identidad COM proyectada no pide vinculación duplicada');
 check(!$projection->hasVerifiedEmail() && web('POST','/admin/partners-afiliados/'.$id.'/acceso',['user_uuid'=>$projection->uuid,'authorized'=>true])->isRedirect(),'colisión de correo no acredita verificación y bloquea ruta admin sensible');
 $admin=login(true);check(web('POST','/admin/partners-afiliados/'.$id.'/acceso',['user_uuid'=>$projection->uuid,'authorized'=>true])->getStatusCode()===410,'asignación manual retirada incluso para admin');
 $page=web('GET','/admin/partners-afiliados');check($page->getStatusCode()===200,'administración programa y enlaces conservada');
 $nonces=array_map(fn($r)=>$r->header('X-Jakawi-Nonce')[0],$calls);check(count($nonces)===count(array_unique($nonces)),'nonce nuevo en cada consulta');
 file_put_contents('/evidence/tests.json',json_encode(['isolated_schema'=>true,'no_live_fixtures'=>true,'passed'=>$passed],JSON_PRETTY_PRINT));
} finally {DB::disconnect('pgsql');config(['database.connections.pgsql.search_path'=>'public']);DB::purge('pgsql');DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');}
