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

function snapshot(): string {
    $data=[];
    foreach(['users','membership_purchases','commission_ledger','commercial_events','acquisition_people','acquisition_aliases','acquisition_activations','commission_rules','commission_audit','acquisition_evidence','jobs'] as $table) $data[$table]=DB::table($table)->orderBy('id')->get();
    return hash('sha256', json_encode($data));
}
try {
 DB::statement('CREATE SCHEMA '.$schema);
 config(['database.connections.pgsql.search_path'=>$schema, 'support.grants_path'=>'/tmp/admin-test-grants.json', 'ads.enabled'=>false]);
 DB::purge('pgsql'); Artisan::call('migrate',['--force'=>true]); Notification::fake();
 app('router')->setCompiledRoutes(app('router')->getRoutes()->compile());
 Http::preventStrayRequests();
 check(web('GET','/admin')->isRedirect(),'guest redirected to login');
 login(false);check(web('GET','/admin')->getStatusCode()===403,'ordinary account blocked');
 $ordinary=payload(web('GET','/mi-cuenta'));check(!in_array('/admin',array_column($ordinary['props']['account_navigation'],'href')),'ordinary account menu hides admin');
 $actor=login(true);
 check(web('GET','/admin')->getStatusCode()===403,'marketing does not grant global administration');
 check(web('GET','/admin/soporte')->getStatusCode()===403,'marketing does not grant support');
 $r=payload(web('GET','/admin/comisiones'));
 check(!in_array('/admin',array_column($r['props']['admin_navigation'],'href'))&&!in_array('/admin/soporte',array_column($r['props']['admin_navigation'],'href')),'marketing menu hides overview and support');
 $actor->forceFill(['global_subject_id'=>(string)Illuminate\Support\Str::uuid()])->save();
 file_put_contents('/tmp/admin-test-grants.json',json_encode([$actor->global_subject_id=>$actor->uuid]));
 check(web('GET','/admin')->getStatusCode()===403,'grant with local session cannot replace OIDC actor');
 app('session')->put('jakawi_sso_subject',$actor->global_subject_id);app('session')->save();
 $before=snapshot();$page=payload(web('GET','/admin'));
 check(web('GET','/admin/')->getStatusCode()===200,'original trailing slash admin URL HTTP 200');
 check($page['component']==='admin/Dashboard','authorized administrator receives dashboard HTTP 200');
 check($page['props']['summary']['approved']===0&&$page['props']['summary']['amounts']===[],'empty ledger does not invent monetary balances');
 check(in_array('/admin',array_column($page['props']['account_navigation'],'href')),'account menu exposes admin for actual OIDC superadmin');
 $fixtures=['dashboard'=>$page, 'account'=>payload(web('GET','/mi-cuenta'))];
 $event=function(string $ref): int {
  return DB::table('commercial_events')->insertGetId(['source'=>'jakawi.com','event_id'=>$ref,'schema_version'=>1,'event_type'=>'order.confirmed','occurred_at'=>now(),'received_at'=>now(),
  'com_reference_type'=>'order','com_reference_id'=>$ref,'conversion_status'=>'confirmed','key_id'=>'fixture','signed_at'=>time(),'payload_sha256'=>str_repeat('a',64),'raw_body_sha256'=>str_repeat('a',64),'raw_body'=>json_encode(['occurred_at'=>now()->toIso8601String()])]);
 };
 $e=$event('approved-fixture');
 $rule=DB::table('commission_rules')->insertGetId(['partner_id'=>'fixture','event_type'=>'order.confirmed','fixed_amount'=>15,'currency'=>'BOB','effective_at'=>now(),'status'=>'active','created_by'=>$actor->uuid,'created_at'=>now(),'updated_at'=>now()]);
 DB::table('commission_ledger')->insert(['source'=>'jakawi.com','com_reference_type'=>'order','com_reference_id'=>'approved-fixture','original_event_id'=>$e,'latest_event_id'=>$e,'commission_event_id'=>$e,
 'affiliate_uuid'=>$actor->uuid,'affiliate_code'=>$actor->referral_code,'rule_id'=>$rule,'rule_snapshot'=>'{}','amount'=>15,'currency'=>'BOB','status'=>'approved','eligible'=>true,'review_required'=>false,'approved_by'=>$actor->uuid,'approved_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
 $person=(string)Illuminate\Support\Str::uuid();
 DB::table('acquisition_people')->insert(['id'=>$person,'beneficiary_type'=>'INDIVIDUAL','beneficiary_id'=>$actor->uuid,'created_at'=>now()]);
 DB::table('acquisition_activations')->insert(['person_id'=>$person,'event_id'=>$e,'membership_reference'=>'fixture-activation','activated_at'=>now()]);
 foreach([[$e,'pending',null],[$event('pending-fixture'),'pending',null],[$event('review-fixture'),'invalid',null],[$event('unknown-fixture'),'pending',json_encode(['first_acquisition'=>'unknown'])]] as [$id,$status,$result]) {
  DB::table('acquisition_evidence')->insert(['person_id'=>$person,'event_id'=>$id,'identity_context'=>str_repeat('a',64),'status'=>$status,'reason'=>'Isolated fixture','result'=>$result,'created_at'=>now()]);
 }
 // Superseded evidence must not be counted twice.
 $latest=DB::table('acquisition_evidence')->orderByDesc('id')->first();
 DB::table('acquisition_evidence')->insert(['person_id'=>$person,'event_id'=>$latest->event_id,'identity_context'=>str_repeat('a',64),'status'=>'pending','reason'=>'Isolated newer evidence','result'=>$latest->result,'created_at'=>now()]);
 $before=snapshot();$fixtures['dashboard']=payload(web('GET','/admin'));
 $summary=$fixtures['dashboard']['props']['summary'];
 check($summary['approved']===1&&$summary['amounts'][0]['amount']==='15.0000','approved amounts reuse real ledger');
 check($summary['evidence']===1&&$summary['review']===2,'latest unissued evidence only, no ledger duplicates');
 check($summary['acquisitions']===1&&$summary['individuals']===1,'acquisition and affiliation use existing records');
 foreach($fixtures['dashboard']['props']['admin_navigation'] as $link) {
  $r=web('GET',$link['href']);check($r->getStatusCode()===200,'existing menu GET '.$link['href']);
  $fixtures[basename($link['href'])]=payload($r);
 }
 foreach(['approved','evidence','review','pending_payment'] as $status) check(web('GET','/admin/comisiones?status='.$status)->getStatusCode()===200,'ledger filter '.$status);
 check(snapshot()===$before,'overview and navigation do not mutate accounts economics acquisitions or jobs');
 check(count(Http::recorded())===0,'overview makes no remote COM CRM requests');
 $actor->forceFill(['is_marketing_admin'=>false])->save();
 check(web('GET','/admin')->getStatusCode()===200,'explicit superadmin can read overview independently of marketing');
 $fixtures['restricted']=payload(web('GET','/admin'));
 foreach(['/admin/comisiones','/admin/reglas-comisiones','/admin/partners-afiliados','/admin/ofertas-comerciales','/admin/conversiones-com','/admin/landings'] as $url) check(web('GET',$url)->getStatusCode()===403,'superadmin does not bypass existing page permission '.$url);
 check(array_column($fixtures['restricted']['props']['admin_navigation'],'href')===['/admin','/admin/soporte'],'restricted navigation exposes only usable URLs');
 app('session')->put('support_view',['actor'=>$actor->uuid,'subject'=>$actor->global_subject_id,'expires_at'=>time()-1,'expired'=>true]);app('session')->save();
 check(web('GET','/admin')->getStatusCode()===403,'support mode keeps general overview blocked');
 $fixtures['support-expired']=payload(web('GET','/admin/soporte'));
 check(array_column($fixtures['support-expired']['props']['admin_navigation'],'href')===['/admin/soporte'],'support mode hides links unavailable until explicit exit');
 app('session')->forget('support_view');app('session')->save();
 $actor->forceFill(['email_verified_at'=>null])->save();check(web('GET','/admin')->isRedirect(),'unverified administrator blocked');
 $actor->forceFill(['email_verified_at'=>now(),'activated_at'=>null])->save();check(web('GET','/admin')->getStatusCode()===403,'deactivated administrator blocked');
 $actor->forceFill(['activated_at'=>now()])->save();file_put_contents('/tmp/admin-test-grants.json','{}');check(web('GET','/admin')->getStatusCode()===403,'revocation applied immediately');
 check(web('GET','/up',[],false)->getStatusCode()===200,'health HTTP 200');
 file_put_contents('/evidence/fixtures.json',json_encode($fixtures,JSON_THROW_ON_ERROR));
 file_put_contents('/evidence/tests.json',json_encode(['isolated_schema'=>true,'no_live_fixtures'=>true,'passed'=>$passed],JSON_PRETTY_PRINT));
} finally {DB::disconnect('pgsql');config(['database.connections.pgsql.search_path'=>'public']);DB::purge('pgsql');DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');}
