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
    $server = ['HTTP_ACCEPT'=>$path === '/cuenta/beneficios' ? 'application/json' : 'text/html', 'REMOTE_ADDR'=>'192.0.2.30'];
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

try {
    DB::statement('CREATE SCHEMA '.$schema);
    config(['database.connections.pgsql.search_path'=>$schema, 'purchases.secret'=>str_repeat('p',64),
        'identity.enabled'=>true,'identity.base_url'=>'https://jakawi.com/internal/identity','identity.key_id'=>'club-test',
        'identity.secret'=>str_repeat('i',64),'identity.proof_secret'=>str_repeat('e',64)]);
    DB::purge('pgsql');
    check(DB::selectOne('select current_schema() as name')->name===$schema,'private schema before writes');
    Artisan::call('migrate',['--force'=>true]);
    foreach (app('router')->getRoutes() as $route) if ($route->uri() === 'cuenta/beneficios') $route->withoutMiddleware('throttle:6,1');
    app('router')->setCompiledRoutes(app('router')->getRoutes()->compile());
    Notification::fake(); Http::preventStrayRequests();
    $user=login(false); app(App\Services\Identity\ActivationEvidence::class)->record($user);
    $id=(string)Illuminate\Support\Str::uuid(); $subject=(string)Illuminate\Support\Str::uuid(); $claim=str_repeat('c',64);
    $purchase=App\Models\MembershipPurchase::create(['id'=>$id,'user_id'=>$user->id,'buyer_email_digest'=>hash('sha256','fixture'),
        'intent'=>['club_reference'=>$id,'club_user_uuid'=>$user->uuid,'claim_secret'=>$claim,'guest_purchase_id'=>null]]);
    $state=['contract'=>'jakawi-club-purchases-v1','club_reference'=>$id,'payment_reference'=>(string)Illuminate\Support\Str::uuid(),
        'amount'=>'100.00','currency'=>'BOB','status'=>'approved','beneficiary_name'=>'Fixture','payment_confirmed'=>true,
        'receipt_pending'=>false,'receipt_received'=>false,'reported_at'=>null,'payment_confirmed_at'=>null,'activated_at'=>now()->toISOString(),
        'membership_reference'=>'200','identity_status'=>'pending','global_subject_id'=>null];
    $mode='new'; $linked=false; $calls=[];$nonces=[]; $issued=0;
    Http::fake(function($r)use(&$mode,&$linked,&$calls,&$nonces,&$issued,$user,$id,$subject,$claim,&$state){
        $path=parse_url($r->url(),PHP_URL_PATH);$calls[]=$r->method().' '.$path;
        $h=fn($k)=>$r->header($k)[0]??'';
        $identity=str_starts_with($path,'/internal/identity');
        $canonical=implode("\n",[$identity?'jakawi-identity-v1':'jakawi-club-purchases-v1',$identity?'club-test':'club-purchases-v1',$h('X-Jakawi-Timestamp'),$h('X-Jakawi-Nonce'),$r->method(),$path,hash('sha256',$r->body())]);
        check(hash_equals(hash_hmac('sha256',$canonical,str_repeat($identity?'i':'p',64)),$h('X-Jakawi-Signature')),'exact outbound HMAC');
        check(!isset($nonces[$h('X-Jakawi-Nonce')]),'fresh outbound nonce');$nonces[$h('X-Jakawi-Nonce')]=true;
        $link=['issuer'=>'https://jakawi.com','source_app'=>'club','club_user_id'=>$user->uuid,'global_subject_id'=>$subject,'status'=>'LINKED',
            'method'=>$mode==='existing'?'COM_AUTHENTICATED_CONSENT':'CLUB_EMAIL_ACTIVATION','authorized_at'=>now()->subSecond()->toISOString(),'revoked_at'=>null];
        if(str_contains($path,'/links/'))return $linked?Http::response($link):Http::response([],404);
        if($path==='/internal/identity/handoffs'){
            $d=json_decode($r->body(),true);check($d['club_user_id']===$user->uuid && $d['consent']['purpose']==='identity_link','handoff authorized owner and consent');
            $base=['contract'=>'jakawi-identity-handoff-v1','issuer'=>'https://jakawi.com','club_user_id'=>$user->uuid,'handoff_id'=>$d['handoff_id']];
            if($mode==='existing')return Http::response($base+['status'=>'AUTH_REQUIRED','authorization_url'=>'https://jakawi.com/identity/club/link/'.$d['handoff_id'],'expires_at'=>now()->addMinutes(10)->toISOString()]);
            $linked=true;return Http::response($base+['status'=>'PROVISIONED','link'=>$link]);
        }
        if($path==='/internal/club/purchases/'.$id && $r->method()==='GET')return Http::response($state);
        if($path==='/internal/club/purchases/'.$id.'/access-ticket'){
            check($r->body()===json_encode(['club_user_uuid'=>$user->uuid,'claim_secret'=>$claim],JSON_UNESCAPED_SLASHES),'ticket uses only frozen original claim and authenticated UUID');
            if($mode==='bad_claim')return Http::response(['message'=>'INVALID_PURCHASE_CLAIM'],403);
            if($mode==='conflict')return Http::response(['message'=>'IDENTITY_CONFLICT'],409);
            if($mode==='required')return Http::response(['message'=>'IDENTITY_LINK_REQUIRED'],409);
            $issued++; return Http::response(['contract'=>'jakawi-club-member-access-v1','entry_url'=>$mode==='evil'?'https://evil.test':'https://jakawi.com/club/access','destination'=>'/beneficios','ticket'=>str_repeat(dechex($issued%15),64),'expires_at'=>now()->addSeconds(90)->toISOString()]);
        }
        throw new RuntimeException('Unexpected isolated request');
    });
    $frozenIntent=$purchase->intent;$withoutClaim=$frozenIntent;unset($withoutClaim['claim_secret']);
    $purchase->forceFill(['intent'=>$withoutClaim])->save();
    check(web('POST','/cuenta/beneficios',['consent'=>true])->getStatusCode()===409 && count($calls)===0,'missing original claim blocks before provisioning or ticket request');
    $purchase->forceFill(['intent'=>$frozenIntent])->save();
    // Simulate original guest checkout owner now authenticated; ownership is immutable.
    check(web('POST','/cuenta/beneficios')->getStatusCode()===422 && !$linked,'explicit consent required before new identity');
    $r=web('POST','/cuenta/beneficios',['consent'=>true,'club_user_uuid'=>(string)Illuminate\Support\Str::uuid(),'claim_secret'=>'attacker','club_reference'=>(string)Illuminate\Support\Str::uuid()]);
    check($r->getStatusCode()===200 && isset(payload($r)['ticket']) && $linked,'verified guest owner provisions and receives benefits ticket without another purchase');
    check(str_contains($r->headers->get('Cache-Control'),'no-store'),'ephemeral response is not cacheable');
    $fixtures=['pending'=>payload(web('GET','/mi-cuenta'))];
    check(!str_contains(json_encode($fixtures),$claim) && !str_contains(json_encode($fixtures),'"ticket"'),'claim and ticket absent from rendered page');
    $first=payload($r)['ticket'];$next=payload(web('POST','/cuenta/beneficios'));
    check($next['ticket']!==$first,'expired or used ticket can be replaced without purchase');
    $mode='bad_claim';$r=web('POST','/cuenta/beneficios');check($r->getStatusCode()===409 && !str_contains($r->getContent(),'INVALID_PURCHASE_CLAIM'),'incorrect claim denied with friendly message');
    $mode='conflict';check(str_contains(payload(web('POST','/cuenta/beneficios'))['message'],'otra identidad'),'identity conflict explains safe resolution');
    $mode='required';check(str_contains(payload(web('POST','/cuenta/beneficios'))['message'],'autorización'),'missing link explains authorization');
    $mode='evil';check(web('POST','/cuenta/beneficios')->getStatusCode()===409,'untrusted ticket destination rejected');
    // New browser login restores ownership from DB, not a browser purchase reference.
    $mode='new';web('POST','/logout');web('POST','/login',['email'=>$user->email,'password'=>'FixturePassword123']);
    check(isset(payload(web('POST','/cuenta/beneficios'))['ticket']),'browser closure and new login restore original purchase');
    $fixtures['linked']=payload(web('GET','/mi-cuenta'));
    $purchase->refresh();$originalIntent=$purchase->intent;$guestIntent=$originalIntent;$guestIntent['club_user_uuid']=null;
    $purchase->forceFill(['user_id'=>null,'intent'=>$guestIntent])->save();
    $guestRequest=Request::create('https://jakawi.club/cuenta/beneficios','POST',['consent'=>true]);
    $guestRequest->setLaravelSession(app('session')->driver());$guestRequest->session()->put('membership_purchase',$id);
    $guestRequest->setUserResolver(fn()=>$user->fresh());
    $guestResult=app(App\Http\Controllers\MemberBenefitsController::class)->store($guestRequest,app(App\Services\PublicMembershipCheckout::class),app(App\Services\Identity\IdentityLink::class));
    check($guestResult->getStatusCode()===200 && isset(payload($guestResult)['ticket']),'unassigned guest purchase requires original session capability and explicit authenticated consent');
    $guestRequest->session()->forget('membership_purchase');
    check(app(App\Services\PublicMembershipCheckout::class)->forBenefits($guestRequest)===null,'unassigned guest purchase cannot be recovered by matching personal data');
    $purchase->forceFill(['user_id'=>$user->id,'intent'=>$originalIntent])->save();

    DB::table('club_identity_links')->delete();DB::table('club_identity_handoffs')->delete();$user->forceFill(['global_subject_id'=>null])->save();$linked=false;$mode='existing';
    $r=web('POST','/cuenta/beneficios',['consent'=>true]);
    check(isset(payload($r)['authorization_url']) && !isset(payload($r)['ticket']) && !$linked,'existing COM identity requires authenticated authorization and no ticket');
    $linked=true;
    check(isset(payload(web('POST','/cuenta/beneficios',['consent'=>true]))['ticket']),'existing COM authenticated consent permits ticket after authoritative mapping refresh');
    $fixtures['pending']['props']['identity']['connected']=false;
    check(App\Models\MembershipPurchase::count()===1,'no new purchase or membership created');
    check(!in_array('POST /internal/club/purchases',$calls,true),'purchase creation never called');
    // Enforce CSRF with the real middleware outside the testing bypass.
    $app->detectEnvironment(fn()=>'production');
    check(web('POST','/cuenta/beneficios',['consent'=>true])->getStatusCode()===419,'session without CSRF cannot request a ticket');
    $app->detectEnvironment(fn()=>'testing');
    web('POST','/logout');check(web('POST','/cuenta/beneficios')->getStatusCode()===401,'session required');
    file_put_contents('/evidence/fixtures.json',json_encode($fixtures,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    file_put_contents('/evidence/tests.json',json_encode(['checks'=>$passed,'passed'=>count($passed),'live_fixtures'=>0,'com_consumption'=>'Delegated to COM 11G; no LIVE ticket issued'],JSON_PRETTY_PRINT));
} catch(Throwable $e) { fwrite(STDERR,get_class($e).' '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine().PHP_EOL);$failed=true;
} finally { DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE'); @unlink('/tmp/commercial-test-secret'); }
exit(isset($failed)?1:0);
