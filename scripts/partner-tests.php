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
function web(string $method, string $path, array $data = [], bool $inertia = true): Symfony\Component\HttpFoundation\Response {
    global $cookies;
    Auth::forgetGuards();
    $server = ['HTTP_ACCEPT'=>'text/html', 'REMOTE_ADDR'=>'192.0.2.30'];
    if ($inertia) {
        $server['HTTP_X_INERTIA']='true';
        $server['HTTP_X_INERTIA_VERSION']=(new App\Http\Middleware\HandleInertiaRequests)->version(Request::create('/'));
    }
    $r = kernelRequest(Request::create('https://jakawi.club'.$path, $method, $data, $cookies, [], $server));
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

// Direct service checks simulate a verified SSO request; authorization comes only from COM.
function businessPanels(User $user): array {
 $user=clone $user;$old=app('request');$r=Request::create('https://jakawi.club/afiliados');
 if (!$user->global_subject_id) {$user->global_subject_id=(string)Illuminate\Support\Str::uuid();}
 $r->setLaravelSession(app('session')->driver());$r->session()->put('jakawi_sso_subject',$user->global_subject_id);$r->setUserResolver(fn()=>$user);
 app()->instance('request',$r);$r->setUserResolver(fn()=>$user);
 Http::swap(new Illuminate\Http\Client\Factory);
 Http::fake(['https://jakawi.com/internal/club/partners/access'=>Http::response(['global_subject_id'=>$user->global_subject_id,'partners'=>[['id'=>43,'name'=>'Dossis 43','status'=>'published','role'=>'owner','economic_access'=>DB::table('affiliate_partner_access')->where('user_id',$user->id)->exists()]]])]);
 config(['purchases.secret'=>str_repeat('h',64)]);
 try {return App\Http\Controllers\PartnerAffiliateController::panels($user);}finally{app()->instance('request',$old);}
}

function openLink(string $code, string $method = 'GET', array $headers = []): Symfony\Component\HttpFoundation\Response {
    Auth::forgetGuards();
    return kernelRequest(Request::create('https://jakawi.club/r/'.$code.'?club_ref=ATTACKER&email=visitor@example.test', $method, [], [], [], array_merge([
        'HTTP_ACCEPT'=>'text/html', 'REMOTE_ADDR'=>'192.0.2.71', 'HTTP_USER_AGENT'=>'Fixture browser',
    ], $headers)));
}
function clickTotal(): int { return (int)DB::table('affiliate_link_daily_clicks')->sum('clicks'); }
function event(string $id, ?User $owner, string $type = 'redemption', string $status = 'confirmed', string $time = '2026-10-29T14:00:00Z', string $partner = 'fixture-dossis'): array {
    return ['schema_version'=>1,'event_id'=>$id,'event_type'=>$type.'.'.$status,'occurred_at'=>$time,
        'com_reference'=>['type'=>$type,'id'=>str_replace(':update','',$id)],'conversion_status'=>$status,
        'partner'=>['id'=>$partner,'name'=>'Partner comprobado'], ...($owner?['referral'=>['club_referral_code'=>$owner->referral_code]]:[])];
}
if (($argv[1] ?? null)==='--click-worker') {
    if (!preg_match('/^results_test_[a-f0-9]{12}$/D',$argv[2]??'') || !preg_match('/^[A-Za-z0-9]{8}$/D',$argv[3]??'')) exit(2);
    config(['database.connections.pgsql.search_path'=>$argv[2]]);DB::purge('pgsql');
    if(DB::selectOne('select current_schema() as name')->name!==$argv[2] || User::resolveConnection()->selectOne('select current_schema() as name')->name!==$argv[2])exit(3);
    for($i=0;$i<8;$i++)if(openLink($argv[3])->getStatusCode()!==302)exit(4);
    echo '8';exit;
}
try {
 DB::statement('CREATE SCHEMA '.$schema);
 config(['database.connections.pgsql.search_path'=>$schema,'commercial.secret_file'=>'/tmp/commercial-test-secret','identity.enabled'=>false,'commissions.economic_cutoff'=>'2026-10-28T00:00:00Z']); DB::purge('pgsql');
 check(DB::selectOne('select current_schema() as name')->name===$schema,'isolated schema before fixtures');
 app(Illuminate\Contracts\Debug\ExceptionHandler::class)->reportable(function(Throwable $e): void { fwrite(STDERR,get_class($e).' '.explode(' (SQL:', $e->getMessage())[0].PHP_EOL); });
 Artisan::call('migrate',['--force'=>true]); app('router')->setCompiledRoutes(app('router')->getRoutes()->compile());
 $service=app(App\Services\PartnerAcquisition::class);
 $partner=DB::table('affiliate_partners')->insertGetId(['source'=>'jakawi.com','partner_id'=>'43','name'=>'Dossis 43','status'=>'enabled','com_url'=>'https://jakawi.com/dossis43','code'=>str_repeat('D',24),'created_at'=>now(),'updated_at'=>now()]);
 $owner=login(false); $staff=login(false); $individual=login(false);
 DB::table('affiliate_partner_access')->insert(['partner'=>$partner,'user_id'=>$owner->id,'authorized_by'=>$owner->uuid,'authorized_at'=>now()]);
 $origin=['source'=>'jakawi.com','kind'=>'partner','partner_id'=>43,'attribution_id'=>(string)Illuminate\Support\Str::uuid(),'fixed_at'=>now()->toIso8601String(),'basis'=>'lead'];
 $make=function(array $a) use($origin):array {return ['schema_version'=>1,'source'=>'jakawi.com','occurred_at'=>now()->toIso8601String(),'acquisition'=>$origin,...$a];};
 $subject=(string)Illuminate\Support\Str::uuid();
 $lead=$make(['event_id'=>'com:member:'.$subject.':registered','event_type'=>'member.registered','global_subject_id'=>$subject,'com_reference'=>['type'=>'user','id'=>'real-com-fixture']]);
 $n=User::count();check(send($lead)->getStatusCode()===201,'organic Dossis COM registration accepted');check(User::count()===$n,'external registration creates no CLUB account');
 check(send($lead)->getStatusCode()===200,'duplicate event id deduplicated');
 $changed=$lead;$changed['acquisition']['partner_id']=44;check(send($changed)->getStatusCode()===409,'changed event id rejected');
 $panel=businessPanels($owner);check($panel[0]['leads']===1,'authorized owner sees business lead');check(businessPanels($staff)===[],'staff without explicit economic authorization denied');
 $subject2=(string)Illuminate\Support\Str::uuid();$direct=$lead;$direct['event_id']='com:member:'.$subject2.':registered';$direct['global_subject_id']=$subject2;$direct['com_reference']['id']='explicit';$direct['acquisition']=['source'=>'jakawi.com','kind'=>'club_referral','club_referral_code'=>$individual->referral_code,'fallback_partner_id'=>43];check(send($direct)->getStatusCode()===201,'explicit referral to Dossis accepted');
 $person=DB::table('acquisition_aliases')->where('kind','subject')->where('reference',$subject2)->first();check(DB::table('acquisition_people')->find($person->person_id)->beneficiary_id===$individual->uuid,'explicit individual beats default Partner');
 $prior=new User(['name'=>'Prior','email'=>'prior@example.test']);$prior->referred_by_id=$individual->id;$prior->save();
 $person=DB::transaction(fn()=>$service->person(['user'=>$prior->uuid],$service->resolve($origin)));check($person->beneficiary_id===$individual->uuid,'previous CLUB referrer preserved');
 $activation=['schema_version'=>1,'event_id'=>'com:membership:test-activation','event_type'=>'membership.activated','occurred_at'=>now()->toIso8601String(),'com_reference'=>['type'=>'membership','id'=>'MEM-43'],'global_subject_id'=>$subject,'membership_reference'=>'MEM-43','payment_reference'=>(string)Illuminate\Support\Str::uuid()];
 check(send($activation)->getStatusCode()===201,'COM membership activation attributed to retained lead');
 check(DB::table('commission_ledger')->count()===0,'no rule means no membership ledger entry');
 $panel=businessPanels($owner);check($panel[0]['activated']===1 && (float)$panel[0]['rate']===100.0,'business activation and rate');
 $rule=function(string $type,string $amount) use($owner):int {return DB::table('commission_rules')->insertGetId(['partner_id'=>'*','beneficiary_type'=>$type,'event_type'=>'membership.activated','fixed_amount'=>$amount,'currency'=>'BOB','effective_at'=>now()->subDay(),'status'=>'active','created_by'=>$owner->uuid,'created_at'=>now(),'updated_at'=>now()]);};
 $oldActivation=$activation;$oldActivation['event_id']='com:membership:old-consumer';$oldActivation['global_subject_id']=(string)Illuminate\Support\Str::uuid();$oldActivation['payment_reference']=(string)Illuminate\Support\Str::uuid();$oldActivation['membership_reference']='MEM-OLD';$oldActivation['com_reference']['id']='MEM-OLD';
 $oldId=DB::table('commercial_events')->insertGetId(['source'=>'jakawi.com','event_id'=>$oldActivation['event_id'],'schema_version'=>1,'event_type'=>'membership.activated','occurred_at'=>now(),'received_at'=>now(),'com_reference_type'=>'membership','com_reference_id'=>'MEM-OLD','global_subject_id'=>$oldActivation['global_subject_id'],'conversion_status'=>'activated','raw_body'=>json_encode($oldActivation),'raw_body_sha256'=>hash('sha256',json_encode($oldActivation)),'payload_sha256'=>str_repeat('0',64),'key_id'=>'fixture-previous-consumer','signed_at'=>time()]);
 $oldLead=$lead;$oldLead['global_subject_id']=$oldActivation['global_subject_id'];$oldLead['event_id']='com:member:'.$oldLead['global_subject_id'].':registered';$oldLead['com_reference']['id']='old-consumer-user';check(send($oldLead)->getStatusCode()===201,'delayed registration joins activation acknowledged by preceding release');
 check(DB::table('acquisition_activations')->where('event_id',$oldId)->exists(),'previously authenticated activation recovered without new conversion');
 $rule('PARTNER','7.0000');$rule('INDIVIDUAL','3.0000');
 check(send($activation)->getStatusCode()===200,'activation retry reconciles against configured rule');
 $entry=DB::table('commission_ledger')->first();check($entry->beneficiary_type==='PARTNER' && $entry->beneficiary_id==='43' && $entry->affiliate_uuid===null && $entry->amount==='7.0000','Partner ledger has business beneficiary, separate scale, no owner');
 $renewal=$activation;$renewal['event_id']='com:membership:renewal';$renewal['membership_reference']='MEM-RENEW';$renewal['com_reference']['id']='MEM-RENEW';check(send($renewal)->getStatusCode()===201 && DB::table('commission_ledger')->count()===1,'renewal creates no second eligible acquisition');
 $other=$activation;$other['event_id']='com:membership:individual';$other['global_subject_id']=$subject2;$other['payment_reference']=(string)Illuminate\Support\Str::uuid();$other['membership_reference']='MEM-INDIV';$other['com_reference']['id']='MEM-INDIV';check(send($other)->getStatusCode()===201,'individual membership activation');check(DB::table('commission_ledger')->where('beneficiary_type','INDIVIDUAL')->value('amount')==='3.0000','individual economic scale selected');
 $payment=(string)Illuminate\Support\Str::uuid();$purchase=(string)Illuminate\Support\Str::uuid();$guest=(string)Illuminate\Support\Str::uuid();
 $ga=['schema_version'=>1,'event_id'=>'com:membership:'.$payment.':activated','event_type'=>'membership.activated','occurred_at'=>now()->toIso8601String(),'origin'=>'CLUB','payment_reference'=>$payment,'club_reference'=>$purchase,'guest_purchase_id'=>$guest,'club_user_uuid'=>null,'global_subject_id'=>null,'membership_reference'=>'MEM-GUEST','identity_status'=>'pending','com_reference'=>['type'=>'membership','id'=>'MEM-GUEST']];
 check(send($ga)->getStatusCode()===201,'guest activation accepted before attribution');
 $fixed=$make(['event_type'=>'membership.attribution_fixed','event_id'=>'com:membership:'.$payment.':attribution-fixed','payment_reference'=>$payment,'club_reference'=>$purchase,'guest_purchase_id'=>$guest,'club_user_uuid'=>null,'global_subject_id'=>null]);check(send($fixed)->getStatusCode()===201,'late purchase attribution completes guest activation');
 $count=DB::table('commission_ledger')->count();
 $gs=(string)Illuminate\Support\Str::uuid();$linked=$fixed;$linked['event_type']='membership.attribution_identity_linked';$linked['event_id']='com:membership:'.$payment.':attribution-identity-linked';$linked['global_subject_id']=$gs;$linked['purchase_acquisition']=$origin;check(send($linked)->getStatusCode()===201,'authorized guest identity link accepted');
 $identity=['schema_version'=>1,'event_id'=>'com:membership:'.$payment.':identity-linked','event_type'=>'membership.identity_linked','occurred_at'=>now()->toIso8601String(),'payment_reference'=>$payment,'club_reference'=>$purchase,'club_user_uuid'=>$staff->uuid,'global_subject_id'=>$gs,'membership_reference'=>'MEM-GUEST'];check(send($identity)->getStatusCode()===201,'legacy identity linked accepted without conversion');
 check(DB::table('commission_ledger')->count()===$count,'identity events do not create second commission');
 $priorSubject=(string)Illuminate\Support\Str::uuid();DB::table('users')->where('id',$prior->id)->update(['global_subject_id'=>$priorSubject]);
 $linkedPrior=$fixed;$linkedPrior['event_id']='com:membership:'.$payment.':attribution-identity-linked';
 // A separate late identity for a previously referred legacy CLUB account.
 $latePay=(string)Illuminate\Support\Str::uuid();$late=$fixed;$late['payment_reference']=$latePay;$late['club_reference']=(string)Illuminate\Support\Str::uuid();$late['event_id']='com:membership:'.$latePay.':attribution-fixed';check(send($late)->getStatusCode()===201,'separate guest provenance stored');
 $late['event_type']='membership.attribution_identity_linked';$late['event_id']='com:membership:'.$latePay.':attribution-identity-linked';$late['club_user_uuid']=$prior->uuid;$late['global_subject_id']=$priorSubject;$late['purchase_acquisition']=$origin;
 check(send($late)->getStatusCode()===201,'late guest link to previously referred CLUB user');
 $priorAlias=DB::table('acquisition_aliases')->where('kind','user')->where('reference',$prior->uuid)->first();check(DB::table('acquisition_people')->find($priorAlias->person_id)->beneficiary_id===$individual->uuid,'guest identity link preserves original personal referrer');
 $newUser=User::create(['name'=>'Linked','email'=>'linked@example.test']);DB::table('users')->where('id',$newUser->id)->update(['global_subject_id'=>$subject]);DB::transaction(fn()=>$service->person(['user'=>$newUser->uuid,'subject'=>$subject]));$panel=businessPanels($owner);check($panel[0]['leads']===3,'external lead not duplicated after CLUB identity link');
 $body=['contract'=>'jakawi-com-origin-v1','source'=>'jakawi.com','audience'=>'jakawi.club','issued_at'=>time(),'expires_at'=>time()+3600,'acquisition'=>$origin];$enc=rtrim(strtr(base64_encode(json_encode($body)),'+/','-_'),'=');$token=$enc.'.'.hash_hmac('sha256',"jakawi-com-origin-v1\n".$enc,$secret);
 check($service->token($token)===$origin && $service->token($token.'x')===null,'exact COM signed proof verified and tampering rejected');
 $request=Request::create('/membresia?com_origin='.urlencode($token));$request->setLaravelSession(app('session')->driver());$service->capture($request);$club=User::create(['name'=>'Club lead','email'=>'club@example.test']);DB::transaction(fn()=>$service->register($club,$request));check(DB::table('acquisition_aliases')->where('kind','user')->where('reference',$club->uuid)->exists(),'CLUB free registration retains signed Partner');
 $guestSession=new Illuminate\Session\Store('partnerguest',new Illuminate\Session\ArraySessionHandler(120));$guestSession->start();
 $guestRequest=Request::create('https://jakawi.club/activar');$guestRequest->setLaravelSession($guestSession);$guestRequest->setUserResolver(fn()=>null);$guestSession->put('com_origin',$token);
 $guestPurchase=app(App\Services\PublicMembershipCheckout::class)->create($guestRequest,['name'=>'Declared existing','email'=>$staff->email,'phone'=>'+59170000000']);
 check($guestPurchase->user_id===null && $guestPurchase->intent['com_origin']===$token,'guest checkout keeps signed origin without email-based account linkage');
 $guestAlias=DB::table('acquisition_aliases')->where('kind','purchase')->where('reference',$guestPurchase->id)->first();
 $retained=DB::transaction(fn()=>$service->person(['purchase'=>$guestPurchase->id]));check($retained->beneficiary_type==='PARTNER' && $retained->beneficiary_id==='43','purchase snapshot survives later visit without attribution token');
 check(web('GET','/a/'.str_repeat('D',24).'/carta')->headers->get('Location')==='https://jakawi.com/p/dossis43/club/carta','Partner carta link uses COM signed-origin route');
 $invalid=$direct;$invalid['global_subject_id']=(string)Illuminate\Support\Str::uuid();$invalid['event_id']='com:member:'.$invalid['global_subject_id'].':registered';$invalid['acquisition']['club_referral_code']='ZZZZZZZZZZZZ';$invalid['com_reference']['id']='invalid-explicit';check(send($invalid)->getStatusCode()===201,'invalid explicit candidate safely uses authorized Partner fallback');
 check(DB::table('commission_ledger')->where('status','approved')->count()===0,'no automatic approvals or payments');
 $admin=login(true);
 $entry=DB::table('commission_ledger')->where('beneficiary_type','PARTNER')->where('review_required',false)->first();
 $approved=app(App\Services\Commissions\CommissionEngine::class)->review($entry->id,['action'=>'approve','version'=>$entry->version,'reason'=>'Isolated authorized approval'], $admin);
 check($approved->status==='approved' && $approved->affiliate_uuid===null,'Partner approval reuses manual ledger with no personal beneficiary');
 check(web('GET','/admin/partners-afiliados')->getStatusCode()===200,'administrative partner configuration accessible');
 $cookies=[];Auth::forgetGuards();app('session')->flush();app('session')->regenerate();web('POST','/login',['email'=>$staff->email,'password'=>'FixturePassword123'],false);
 $staffPage=payload(web('GET','/afiliados?tab=results'));check($staffPage['props']['partner_panels']===[],'staff HTTP response contains no business data');
 check(web('GET','/admin/partners-afiliados')->getStatusCode()===403,'staff cannot administratively grant economic access');


 if (in_array($argv[1] ?? '', ['--acquisition-rates','--acquisition-evidence'], true)) require '/tests/acquisition-rate-tests.php';

 // Export actual protected props under owner session for intercepted browser verification.
 if (($argv[1] ?? '') === '--acquisition-evidence') require '/tests/acquisition-evidence-tests.php';
 $cookies=[]; Auth::forgetGuards(); app('session')->flush(); app('session')->regenerate(); web('POST','/login',['email'=>$owner->email,'password'=>'FixturePassword123'],false);$res=web('GET','/afiliados?tab=results'); if($res->getStatusCode()!==200) throw new RuntimeException('Panel HTTP '.$res->getStatusCode().' '.$res->headers->get('Location')); $page=payload($res);check(($page['component']??'')==='Affiliates','real owner panel HTTP rendered');file_put_contents('/evidence/partner-browser-fixture.json',json_encode($page,JSON_THROW_ON_ERROR));
 file_put_contents('/evidence/tests.json',json_encode(['passed'=>$passed,'live_fixtures'=>0],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
} finally {DB::statement('SET search_path TO public');DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');}
