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
$schema = 'affiliate_test_'.bin2hex(random_bytes(6));
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


try {
    DB::statement('CREATE SCHEMA '.$schema);
    config(['database.connections.pgsql.search_path'=>$schema,'commercial.secret_file'=>'/tmp/commercial-test-secret','identity.enabled'=>false]);
    DB::purge('pgsql');
    check(DB::selectOne('select current_schema() as name')->name===$schema && User::resolveConnection()->selectOne('select current_schema() as name')->name===$schema,'isolated connections verified before fixture writes');
    Artisan::call('migrate',['--force'=>true]);
    Notification::fake(); Http::preventStrayRequests(); Http::fake();
    check(web('GET','/afiliados')->isRedirect('https://jakawi.club/login'),'guest denied');
    $a=login(false);
    $identity=$a->getRawOriginal();
    $r=web('GET','/afiliados'); $empty=payload($r);
    check($r->getStatusCode()===200 && $empty['component']==='Affiliates','verified activated member eligible without COM identity provisioning');
    check(preg_match('/^[A-Z0-9]{12}$/D',$a->referral_code)===1,'existing account mechanism issues real permanent code');
    check(DB::table('affiliate_short_links')->count()===0,'catalog visit does not automatically generate personal links');
    check($empty['props']['conversions']['total']===0,'honest empty state');
    $dashboard=payload(web('GET','/dashboard'));
    check($dashboard['props']['can_access_affiliates']===true,'private dashboard exposes affiliate navigation');
    $base=['schema_version'=>1,'event_id'=>'affiliate:one','event_type'=>'order.confirmed','occurred_at'=>'2026-10-29T12:00:00Z',
        'com_reference'=>['type'=>'order','id'=>'fixture-order-one'],'conversion_status'=>'confirmed',
        'partner'=>['id'=>'fixture-dossis','name'=>'Dossis 43 — Café y antojo'],
        'origin'=>['landing'=>'https://jakawi.com/l/dossis-43-cafe-y-antojo'],
        'referral'=>['club_referral_code'=>$a->referral_code]];
    check(send($base)->getStatusCode()===201,'signed COM conversion accepted');
    $stored=DB::table('commercial_events')->where('event_id',$base['event_id'])->first();
    check($stored->referrer_club_uuid===$a->uuid && $stored->club_referral_code===$a->referral_code,'receiver verifies actual CLUB code and snapshots owner UUID');
    check(send($base)->getStatusCode()===200,'COM retry acknowledged without new conversion');
    check(send([...$base,'event_id'=>'affiliate:two','occurred_at'=>'2026-10-29T13:00:00Z','event_type'=>'order.updated'])->getStatusCode()===201,'distinct state event for same original operation accepted');
    $r=web('GET','/afiliados');$data=payload($r)['props']['conversions'];
    check($data['total']===1 && count($data['data'])===1 && $data['data'][0]['event_type']==='order.updated','multiple events count one operation using latest business time');
    check(send([...$base,'conversion_status'=>'cancelled'])->getStatusCode()===409,'event conflict cannot inflate total');
    foreach ([
        ['event_id'=>'affiliate:pending','com_reference'=>['type'=>'order','id'=>'pending'],'conversion_status'=>'pending'],
        ['event_id'=>'affiliate:cancel:old','com_reference'=>['type'=>'order','id'=>'cancelled']],
        ['event_id'=>'affiliate:cancel:new','com_reference'=>['type'=>'order','id'=>'cancelled'],'occurred_at'=>'2026-10-29T14:00:00Z','conversion_status'=>'cancelled'],
        ['event_id'=>'affiliate:cancel:late-delivery','com_reference'=>['type'=>'order','id'=>'cancelled'],'occurred_at'=>'2026-10-29T13:00:00Z'],
        ['event_id'=>'affiliate:no-code','com_reference'=>['type'=>'order','id'=>'no-code'],'referral'=>null,'global_subject_id'=>$a->uuid],
        ['event_id'=>'affiliate:com-only','com_reference'=>['type'=>'order','id'=>'com-only'],'referral'=>['com_referral_id'=>'fixture-com-referral']],
    ] as $change) check(send([...$base,...$change])->getStatusCode()===201,'isolated event '.$change['event_id']);
    check(payload(web('GET','/afiliados'))['props']['conversions']['total']===1,'pending cancelled out-of-order code-less and global identity events excluded');
    check(send([...$base,'event_id'=>'affiliate:other-type','com_reference'=>['type'=>'redemption','id'=>'fixture-order-one']])->getStatusCode()===201,'same ID under distinct operation type accepted');
    $aCookies=$cookies;
    $b=login(false);$bCookies=$cookies;
    check(payload(web('GET','/afiliados'))['props']['conversions']['total']===0,'second affiliate sees no first-affiliate conversions');
    $bEvent=[...$base,'event_id'=>'affiliate:b','com_reference'=>['type'=>'order','id'=>'private-order-b'],'referral'=>['club_referral_code'=>$b->referral_code]];
    check(send($bEvent)->getStatusCode()===201,'second affiliate signed conversion accepted');
    check(send([...$base,'event_id'=>'affiliate:shared:a','com_reference'=>['type'=>'order','id'=>'shared-operation']])->getStatusCode()===201,'historical attributed state accepted');
    check(send([...$bEvent,'event_id'=>'affiliate:shared:b','com_reference'=>['type'=>'order','id'=>'shared-operation'],'occurred_at'=>'2026-10-29T15:00:00Z'])->getStatusCode()===201,'latest verified attribution selected once across affiliates');
    check(payload(web('GET','/afiliados'))['props']['conversions']['total']===2,'second affiliate sees only its two unique operations');
    $cookies=$aCookies;
    $r=web('GET','/afiliados?user_id='.$b->id.'&club_ref='.$b->referral_code.'&referrer_club_uuid='.$b->uuid);
    $page=payload($r);$operations=$page['props']['conversions'];
    check($operations['total']===2 && !str_contains($r->getContent(),'private-order-b') && !str_contains(json_encode($operations),'shared-operation'),'scope cannot be overridden and one COM operation never counts for two affiliates');
    $r=web('GET','/afiliados');
    check(!str_contains($r->getContent(),$b->referral_code) && !str_contains($r->getContent(),'raw_body') && !str_contains($r->getContent(),'global_subject_id') && !str_contains($r->getContent(),'referrer_club_uuid'),'private response excludes other codes raw events and identity metadata');
    check(str_contains($r->headers->get('Cache-Control'),'no-store'),'affiliate response forbids caching');
    // Defense in depth for historical records: both verified fields must match.
    $clone=(array)$stored;unset($clone['id']);$clone['event_id']='fixture:mismatched-uuid';$clone['com_reference_id']='mismatched-uuid';$clone['referrer_club_uuid']=$b->uuid;
    DB::table('commercial_events')->insert($clone);
    $clone['event_id']='fixture:mismatched-code';$clone['com_reference_id']='mismatched-code';$clone['referrer_club_uuid']=$a->uuid;$clone['club_referral_code']=$b->referral_code;
    DB::table('commercial_events')->insert($clone);
    check(payload(web('GET','/afiliados'))['props']['conversions']['total']===2,'code alone or UUID alone is insufficient attribution');
    $populated=payload(web('GET','/afiliados'));
    check($populated['props']['conversions']['data'][0]['occurred_at']==='2026-10-29T13:00:00+00:00','source dates retain UTC for Bolivia presentation');
    check($a->fresh()->getRawOriginal()===$identity,'affiliate reads preserve permanent user identity');
    for($i=0;$i<26;$i++) check(send([...$base,'event_id'=>'affiliate:page:'.$i,'com_reference'=>['type'=>'order','id'=>'page-'.$i]])->getStatusCode()===201,'isolated pagination event '.$i);
    $paged=payload(web('GET','/afiliados'))['props']['conversions'];
    check($paged['total']===28 && count($paged['data'])===25 && $paged['last_page']===2,'unique conversions paginate at 25');
    $last=payload(web('GET','/afiliados?page=2'))['props']['conversions'];
    check(count($last['data'])===3 && $last['total']===28,'pagination retains total and scope');
    check(web('GET','/admin/conversiones-com')->getStatusCode()===403,'affiliate does not gain commercial admin permission');
    $a->forceFill(['activated_at'=>null])->save();
    check(web('GET','/afiliados')->getStatusCode()===403,'inactive authenticated affiliate denied');
    check(payload(web('GET','/dashboard'))['props']['can_access_affiliates']===false,'inactive account has no affiliate navigation');
    $a->forceFill(['activated_at'=>now(),'email_verified_at'=>null])->save();
    check(web('GET','/afiliados')->isRedirect(),'unverified affiliate denied');
    check(DB::table('jobs')->count()===0 && Http::recorded()->count()===0 && Notification::sentNotifications()===[],'affiliate flow has no CRM jobs external HTTP or mail');
    file_put_contents('/evidence/affiliate-browser-fixtures.json',json_encode(['populated'=>$populated,'empty'=>$empty,'dashboard'=>$dashboard],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    $result=['passed'=>count($passed),'postgresql'=>'isolated schema','live_events_created'=>0,'live_users_created'=>0,'outbound_http'=>0,'notifications'=>0];
    file_put_contents('/evidence/tests.json',json_encode($result,JSON_PRETTY_PRINT).PHP_EOL);echo json_encode($result).PHP_EOL;
} catch(Throwable $e) {
    fwrite(STDERR,get_class($e).' '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine().PHP_EOL);$failed=true;
} finally {
    DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');unlink('/tmp/commercial-test-secret');
}
exit(isset($failed)?1:0);
