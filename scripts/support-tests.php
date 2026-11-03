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
}function reauth(?string $as = null, bool $badNonce = false): void {
    global $claims, $actor;
    app('cache')->flush();
    $r = web('POST', '/admin/soporte/reautenticar', ['sub' => $GLOBALS['target']->global_subject_id]);
    check($r->getStatusCode() === 409 && str_contains($r->headers->get('X-Inertia-Location', ''), 'prompt=login'), 'reauth solicita login completo COM, sin max_age no admitido');
    $pending = json_decode(Illuminate\Support\Facades\Crypt::decryptString(app('session')->get('jakawi_oidc')), true);
    $claims = ['iss'=>'https://jakawi.com','sub'=>$as ?? $actor->global_subject_id,'aud'=>config('oidc.client_id'),'iat'=>time(),'exp'=>time()+3600,
        'nonce'=>$badNonce ? 'incorrect' : $pending['values']['openid_connect_nonce'],'email'=>$actor->email,'email_verified'=>true];
    web('GET', '/auth/jakawi/callback?code=isolated-reauth&state='.$pending['values']['openid_connect_state'], [], false);
}
function openSupport(?User $target = null, ?string $sub = null): array {
    app('cache')->flush();
    reauth();
    $r = web('POST','/admin/soporte/iniciar', ['sub'=>$sub ?? $target?->global_subject_id, 'reason'=>'Diagnóstico de acceso solicitado por usuario.']);
    check($r->isRedirect(), 'superadmin explícito inicia soporte con motivo');
    $r = web('GET','/admin/soporte/cuenta');
    check($r->getStatusCode() === 200, 'consulta de soporte HTTP 200');
    return payload($r);
}
function snapshot(): string {
    $data=[];
    foreach(['users','membership_purchases','commission_ledger','commercial_events','acquisition_people','acquisition_aliases','jobs'] as $table) $data[$table]=DB::table($table)->orderBy('id')->get();
    return hash('sha256', json_encode($data));
}
try {
 DB::statement('CREATE SCHEMA '.$schema);
 config(['database.connections.pgsql.search_path'=>$schema, 'identity.enabled'=>true, 'oidc.enabled'=>true,
 'oidc.client_id'=>'8898bf42-9f49-4cc8-8b79-6496d93d2536','oidc.client_secret'=>str_repeat('x',64),'purchases.secret'=>str_repeat('h',64),
 'support.grants_path'=>'/evidence/grants-test.json','support.audit_path'=>'/evidence/audit-test.jsonl']);
 @unlink('/evidence/audit-test.jsonl');
 DB::purge('pgsql'); Artisan::call('migrate',['--force'=>true]); Notification::fake();
 app('router')->setCompiledRoutes(app('router')->getRoutes()->compile());
 $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);$rsa=openssl_pkey_get_details($key)['rsa'];
 $claims=[];$mode='free';$calls=[];$badNonce=false;
 Http::swap(new Illuminate\Http\Client\Factory);Http::preventStrayRequests();
 Http::fake(function($request) use(&$claims,$key,$rsa,&$calls,&$mode){
  if($request->url()==='https://jakawi.com/oauth/jwks')return Http::response(['keys'=>[['kty'=>'RSA','use'=>'sig','alg'=>'RS256','kid'=>'isolated','n'=>b64($rsa['n']),'e'=>b64($rsa['e'])]]]);
  if($request->url()==='https://jakawi.com/oauth/token'){
   $unsigned=b64(json_encode(['alg'=>'RS256','kid'=>'isolated'])).'.'.b64(json_encode($claims));openssl_sign($unsigned,$signature,$key,OPENSSL_ALGO_SHA256);
   return Http::response(['token_type'=>'Bearer','access_token'=>'isolated-access-token','id_token'=>$unsigned.'.'.b64($signature)]);
  }
  $calls[]=$request;
  if($request->url()==='https://jakawi.com/internal/club/partners/access') return Http::response(['global_subject_id'=>$request['global_subject_id'],'partners'=>[]]);
  if($request->url()!=='https://jakawi.com/internal/club/memberships/status')throw new RuntimeException('Unexpected support egress');
  if($mode==='down')throw new Illuminate\Http\Client\ConnectionException;
  return Http::response(['contract'=>'jakawi-com-membership-status-v1','global_subject_id'=>$mode==='mismatch'?(string)Illuminate\Support\Str::uuid():$request['global_subject_id'],
   'membership'=>['type'=>$mode==='pioneer'?'pioneer':'free','status'=>$mode==='pioneer'?'active':'free','is_active'=>$mode==='pioneer',
   'starts_at'=>null,'effective_ends_at'=>null,'expiry_policy'=>$mode==='pioneer'?'official_launch_plus_15_calendar_months':null,'official_launch_date'=>null,
   'benefits'=>['public_catalog'=>true,'club_access'=>true,'exclusive_member_benefits'=>$mode==='pioneer']]]);
 });
 $unauthorized=login(true);check(web('GET','/admin/soporte')->getStatusCode()===403,'admin marketing no es superadmin soporte');
 oidc((string)Illuminate\Support\Str::uuid(),true,'support-actor@example.test');
 $actor=User::where('email','support-actor@example.test')->firstOrFail();
 check(Artisan::call('support:superadmin',['subject'=>$actor->global_subject_id])===0,'comando autoriza solo identidad CLUB existente por sub');
 $target=User::create(['name'=>'Usuario gratuito de prueba','email'=>'support-target@example.test']);
 $target->forceFill(['global_subject_id'=>(string)Illuminate\Support\Str::uuid(),'activated_at'=>now(),'sso_provisioned_at'=>now()])->save();
 // Incoming COM selection survives an entirely normal OIDC login of the administrator.
 $cookies=[];Auth::forgetGuards();app('session')->flush();app('session')->regenerate();
 $r=web('GET','/admin/soporte?subject='.$target->global_subject_id,[],false);
 check($r->isRedirect()&&str_starts_with($r->headers->get('Location'),'https://jakawi.com/oauth/authorize'),'entrada guest inicia OIDC normal del actor');
 $pending=json_decode(Illuminate\Support\Facades\Crypt::decryptString(app('session')->get('jakawi_oidc')),true);
 check($pending['support_return_sub']===$target->global_subject_id&&$pending['support_entry'],'selección guardada en transacción cifrada, sin autorización');
 check(!str_contains($r->headers->get('Location'),$target->global_subject_id),'OIDC no recibe identidad del destino');
 $claims=['iss'=>'https://jakawi.com','sub'=>$actor->global_subject_id,'aud'=>config('oidc.client_id'),'iat'=>time(),'exp'=>time()+3600,'nonce'=>$pending['values']['openid_connect_nonce'],'email'=>$actor->email,'email_verified'=>true];
 $r=web('GET','/auth/jakawi/callback?code=normal-admin-login&state='.$pending['values']['openid_connect_state'],[],false);
 check($r->headers->get('Location')==='https://jakawi.club/admin/soporte?subject='.$target->global_subject_id&&Auth::id()===$actor->id,'callback normal regresa a selección sin login del destino');
 $entry=payload(web('GET','/admin/soporte?subject='.$target->global_subject_id));
 check($entry['props']['query']['sub']===$target->global_subject_id&&!$entry['props']['recent']&&!app('session')->has('support_view'),'preselección conserva reauth y autorización pendientes');
 check(web('GET','/admin/soporte?subject=invalid',[],true,['HTTP_ACCEPT'=>'application/json'])->getStatusCode()===422,'UUID de selección inválido rechazado');
 check(web('GET','/admin/soporte?subject='.$target->global_subject_id.'&sub='.$actor->global_subject_id)->getStatusCode()===422,'aliases contradictorios rechazados');
 $probe=web('GET','/admin/soporte');
 check($probe->getStatusCode()===200,'superadmin vinculado explícitamente por UUID global y local');
 check(web('POST','/admin/soporte/iniciar',['sub'=>$target->global_subject_id,'reason'=>'Motivo de prueba válido'])->getStatusCode()===403,'reauth reciente obligatoria');
 reauth((string)Illuminate\Support\Str::uuid());check(!app('session')->has('support_reauth') && Auth::id()===$actor->id,'otro sub no reautentica ni cambia actor');
 reauth(null,true);check(!app('session')->has('support_reauth'),'nonce incorrecto no acredita reautenticación');
 reauth();check(app('session')->has('support_reauth'),'RS256 nonce y mismo sub acreditan reauth');
 check(str_contains(web('GET','/admin/soporte?sub='.$target->global_subject_id)->getContent(),$target->uuid),'enlace directo por sub resuelve cuenta existente');
 check(web('POST','/admin/soporte/iniciar',['sub'=>$target->global_subject_id])->getStatusCode()===302&&!app('session')->has('support_view'),'motivo obligatorio antes de consultar');
 app('session')->put('support_reauth',['actor'=>$actor->uuid,'subject'=>$actor->global_subject_id,'at'=>time()-301]);app('session')->save();
 check(web('POST','/admin/soporte/iniciar',['sub'=>$target->global_subject_id,'reason'=>'Motivo de prueba válido'])->getStatusCode()===403,'reauth de más de cinco minutos rechazada');
 $before=snapshot();$fixtures=[];
 $page=openSupport($target);$fixtures['free']=$page;
 $supportId=app('session')->get('support_view.id');$deadline=app('session')->get('support_view.expires_at');
 check(web('GET','/admin/soporte?subject='.$target->global_subject_id)->isRedirect()&&app('session')->get('support_view.id')===$supportId&&app('session')->get('support_view.expires_at')===$deadline,'destino vigente reutiliza soporte sin nueva autorización ni extensión');
 $otherSelection=(string)Illuminate\Support\Str::uuid();
 $switchPage=payload(web('GET','/admin/soporte?subject='.$otherSelection));
 check($switchPage['props']['switch_required']&&$switchPage['props']['query']['sub']===$otherSelection&&app('session')->get('support_view.id')===$supportId,'otro destino se preselecciona sin reemplazar consulta por GET');
 check($page['props']['view']['membership']['type']==='free'&&!$page['props']['view']['affiliate_allowed'],'gratuito no verificado no recibe afiliación');
 $pinned=payload(web('GET','/admin/soporte/cuenta?sub='.$actor->global_subject_id));
 check($pinned['props']['view']['target']['uuid']===$target->uuid,'query del navegador no sustituye destino autorizado');
 check(Auth::id()===$actor->id && app('session')->get('jakawi_sso_subject')===$actor->global_subject_id,'actor REAL conservado; no login ni OIDC del destino');
 check(app('session')->get('support_view.expires_at') <= time()+900,'límite fijo de 15 minutos');
 $mode='pioneer';$page=payload(web('GET','/admin/soporte/cuenta'));$fixtures['pioneer']=$page;
 check($page['props']['view']['membership']['type']==='pioneer'&&$page['props']['view']['membership']['is_active'],'Pionero efectivo consultado COM');
 foreach(['down','mismatch'] as $mode){$p=payload(web('GET','/admin/soporte/cuenta'));check(!$p['props']['view']['membership']['available'],'COM '.$mode.' no concede beneficios');$fixtures[$mode]=$p;}
 check(snapshot()===$before,'consultas no cambian cuentas compras ledger origen eventos ni CRM');
 $mode='free';
 foreach([['PATCH','/perfil'],['POST','/activar/compra'],['POST','/activar/aviso'],['POST','/activar/comprobante'],['POST','/cuenta/beneficios'],['POST','/cuenta/jakawi/activar'],['POST','/afiliados/enlaces/example'],['POST','/admin/comisiones/1/revision'],['GET','/activar'],['GET','/cuenta/jakawi/estado'],['GET','/admin/landings/1/export'],['GET','/auth/jakawi/callback'],['GET','/r/ABCDEF123456'],['POST','/medicion']] as [$method,$path]){
  app('cache')->flush();check(web($method,$path)->getStatusCode()===403,'solo lectura bloquea '.$method.' '.$path);
 }
 check(snapshot()===$before,'todos los intentos bloqueados sin efectos secundarios');
 check(web('GET','/mi-cuenta')->headers->get('Location')==='https://jakawi.club/admin/soporte/cuenta','mi-cuenta dirige a proyección segura');
 check(web('GET','/afiliados')->headers->get('Location')==='https://jakawi.club/admin/soporte/afiliados','afiliados dirige a permisos del destino');
 $expired=app('session')->get('support_view');$expired['expires_at']=time()-1;app('session')->put('support_view',$expired);app('session')->save();
 check(web('GET','/admin/soporte/cuenta')->getStatusCode()===403,'expiración aplicada en servidor');
 $fixtures['expired']=payload(web('GET','/admin/soporte'));check($fixtures['expired']['props']['expired'],'pantalla de sesión vencida');
 check(web('POST','/activar/compra')->getStatusCode()===403,'expiración mantiene bloqueo hasta salida explícita');
 web('POST','/admin/soporte/salir');check(!app('session')->has('support_view')&&Auth::id()===$actor->id,'salir conserva sesión real y elimina soporte');
 $missing=(string)Illuminate\Support\Str::uuid();$count=User::count();$page=openSupport(null,$missing);$fixtures['missing']=$page;
 check($page['props']['view']['target']===null&&User::count()===$count,'sub COM sin cuenta CLUB no se crea ni se consulta como usuario inventado');
 // Exercise real CSRF middleware rather than its normal testing bypass.
 $app['env']='production';
 try {
  check(web('POST','/admin/soporte/com')->getStatusCode()===419&&app('session')->has('support_view'),'Ver en COM requiere CSRF y conserva soporte ante fallo');
  $r=web('POST','/admin/soporte/com',['_token'=>app('session')->token(),'subject'=>$actor->global_subject_id,'url'=>'https://example.test']);
  check($r->getStatusCode()===409&&$r->headers->get('X-Inertia-Location')==='https://jakawi.com/admin/soporte?subject='.$missing,'Ver en COM usa destino del servidor, nunca URL o subject del POST');
  check(!app('session')->has('support_view')&&Auth::id()===$actor->id,'retorno COM finaliza soporte y conserva actor real');
 } finally {$app['env']='testing';}
 $target->forceFill(['email_verified_at'=>now()])->save();
 $event=DB::table('commercial_events')->insertGetId(['source'=>'jakawi.com','event_id'=>'support-event','schema_version'=>1,'event_type'=>'order.confirmed','occurred_at'=>now(),'received_at'=>now(),
 'com_reference_type'=>'order','com_reference_id'=>'support-sale','partner_name'=>'Partner de prueba','club_referral_code'=>$target->referral_code,'referrer_club_uuid'=>$target->uuid,
 'conversion_status'=>'confirmed','key_id'=>'fixture','signed_at'=>time(),'payload_sha256'=>str_repeat('a',64),'raw_body_sha256'=>str_repeat('a',64),'raw_body'=>'{}']);
 $rule=DB::table('commission_rules')->insertGetId(['partner_id'=>'fixture','event_type'=>'order.confirmed','fixed_amount'=>15,'currency'=>'BOB','effective_at'=>now(),'status'=>'active','created_by'=>$actor->uuid,'created_at'=>now(),'updated_at'=>now()]);
 DB::table('commission_ledger')->insert(['source'=>'jakawi.com','com_reference_type'=>'order','com_reference_id'=>'support-sale','original_event_id'=>$event,'latest_event_id'=>$event,'commission_event_id'=>$event,
 'affiliate_uuid'=>$target->uuid,'affiliate_code'=>$target->referral_code,'rule_id'=>$rule,'rule_snapshot'=>'{}','amount'=>15,'currency'=>'BOB','status'=>'approved','eligible'=>true,'review_required'=>false,'approved_by'=>$actor->uuid,'approved_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
 openSupport($target);
 $r=web('POST','/admin/soporte/seleccionar',['subject'=>$missing]);
 check($r->headers->get('Location')==='https://jakawi.club/admin/soporte?subject='.$missing&&!app('session')->has('support_view'),'cambio explícito termina consulta y conserva nueva selección');
 $fixtures['selection']=payload(web('GET','/admin/soporte?subject='.$target->global_subject_id));
 reauth();$fixtures['authorization']=payload(web('GET','/admin/soporte?subject='.$target->global_subject_id));
 $before=snapshot();openSupport($target);$page=payload(web('GET','/admin/soporte/afiliados'));$fixtures['affiliate']=$page;
 check($page['props']['view']['affiliate_allowed']&&count($page['props']['view']['commissions']['data'])===1,'afiliado ve únicamente sus comisiones permitidas');
 check($page['props']['view']['economics']['currencies'][0]['approved']==='15.0000','importe aprobado BOB real');
 check($page['props']['view']['partners']===[],'sin permiso Partner COM no hay datos económicos Partner');
 check(snapshot()===$before,'afiliados conversiones y comisiones son consultas sin escrituras');
 $body=json_encode($page);foreach(['isolated-access-token','claim_secret','buyer_email_digest','raw_body','receipt_sha256'] as $s)check(!str_contains($body,$s),'proyección no revela '.$s);
 foreach($calls as $call) check($call['global_subject_id']===$target->global_subject_id,'consulta COM usa solo sub almacenado del destino');
 check(Artisan::call('support:superadmin',['subject'=>$actor->global_subject_id,'--revoke'=>true])===0,'comando revoca autorización explícita');check(web('GET','/admin/soporte/cuenta')->getStatusCode()===403,'revocación inmediata aplicada durante soporte');
 check(web('POST','/admin/soporte/salir')->isRedirect(),'salida disponible tras revocación');
 $audit=array_map(fn($line)=>json_decode($line,true),file('/evidence/audit-test.jsonl',FILE_IGNORE_NEW_LINES));
 check(collect($audit)->contains(fn($row)=>$row['action']==='started'&&$row['actor_uuid']===$actor->uuid&&$row['target_uuid']===$target->uuid&&strlen($row['reason'])>=10),'auditoría durable de actor destino motivo');
 check(collect($audit)->contains(fn($row)=>$row['action']==='stopped_for_com'&&$row['target_subject']===$missing),'salida a COM auditada con destino original');
 check(collect($audit)->contains(fn($row)=>$row['action']==='expired')&&collect($audit)->contains(fn($row)=>$row['action']==='blocked'),'auditoría de expiración e intentos bloqueados');
 file_put_contents('/evidence/grants-test.json',json_encode([$actor->global_subject_id=>$actor->uuid]));
 $actor->forceFill(['password'=>'FixturePassword123'])->save();
 web('POST','/logout');web('POST','/login',['email'=>$actor->email,'password'=>'FixturePassword123'],false);
 check(Auth::id()===$actor->id && web('GET','/admin/soporte')->getStatusCode()===403,'grant explícito con login local no sustituye OIDC real');
 file_put_contents('/evidence/fixtures.json',json_encode($fixtures,JSON_THROW_ON_ERROR));
 file_put_contents('/evidence/tests.json',json_encode(['isolated_schema'=>true,'no_live_fixtures'=>true,'passed'=>$passed],JSON_PRETTY_PRINT));
} finally {DB::disconnect('pgsql');config(['database.connections.pgsql.search_path'=>'public']);DB::purge('pgsql');DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');}
