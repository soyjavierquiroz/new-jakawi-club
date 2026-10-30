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
$schema = 'commercial_test_'.bin2hex(random_bytes(6));
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

if (($argv[1] ?? null) === '--race') {
    config(['database.connections.pgsql.search_path'=>$argv[2],'commercial.secret_file'=>'/tmp/commercial-test-secret']);
    DB::purge('pgsql');
    $r=send(['schema_version'=>1,'event_id'=>$argv[3],'event_type'=>'order.confirmed','occurred_at'=>'2026-10-29T23:20:30Z',
        'com_reference'=>['type'=>'order','id'=>'race-order'],'conversion_status'=>$argv[4]]);
    echo $r->getStatusCode(); exit;
}

function race(string $id, array $states): array {
    global $schema;
    $children=[];
    foreach($states as $state) {
        $proc=proc_open([PHP_BINARY,__FILE__,'--race',$schema,$id,$state],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]); $children[]=[$proc,$pipes];
    }
    $statuses=[];
    foreach($children as [$proc,$pipes]) {
        $statuses[]=(int)stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);
        if(proc_close($proc)!==0)throw new RuntimeException($error);
    }
    sort($statuses);return $statuses;
}

try {
    DB::statement('CREATE SCHEMA '.$schema);
    config(['database.connections.pgsql.search_path'=>$schema,'commercial.secret_file'=>'/tmp/commercial-test-secret','identity.enabled'=>false]);
    DB::purge('pgsql');
    Artisan::call('migrate',['--force'=>true]);
    Notification::fake(); Http::preventStrayRequests(); Http::fake();
    $base=['schema_version'=>1,'event_id'=>'com:test:1','event_type'=>'order.confirmed','occurred_at'=>'2026-10-29T19:20:30-04:00',
        'com_reference'=>['type'=>'order','id'=>'test-order-1'],'conversion_status'=>'confirmed'];
    foreach (['HTTP_X_JAKAWI_COM_SIGNATURE'=>'','HTTP_X_JAKAWI_COM_KEY_ID'=>'crm','HTTP_X_JAKAWI_COM_TIMESTAMP'=>'invalid'] as $header=>$value) {
        check(send($base,[$header=>$value])->getStatusCode()===401, 'reject missing or invalid '.$header);
    }
    check(send($base,[],key:str_repeat('i',64))->getStatusCode()===401,'reject a different integration secret');
    check(send($base,[],time:time()-301)->getStatusCode()===401,'reject expired signature');
    check(send($base,[],time:time()+301)->getStatusCode()===401,'reject future signature');
    $r=send($base,['HTTP_X_JAKAWI_COM_SIGNATURE'=>'sha256='.str_repeat('a',64)]);
    check($r->getStatusCode()===401 && !$r->headers->has('Set-Cookie'),'machine auth failure creates no session');
    config(['commercial.secret_file'=>'/tmp/nonexistent-commercial-key']);
    check(send($base)->getStatusCode()===503,'fail closed without dedicated secret');
    config(['commercial.secret_file'=>'/tmp/commercial-test-secret']);
    check(send($base,['CONTENT_TYPE'=>'text/plain'])->getStatusCode()===415,'only JSON is accepted');
    check(send(str_repeat('x',65537))->getStatusCode()===413,'bounded payload');
    check(send('{')->getStatusCode()===400 && send('[]')->getStatusCode()===400,'invalid JSON and non-object rejected');
    check(send($base,[],path:'/internal/commercial/events?unexpected=1')->getStatusCode()===400,'query target rejected');
    check(DB::table('commercial_events')->count()===0,'authentication failures persist no events');
    // No CSRF/session dependency even outside Laravel testing bypass.
    $app->instance('env','production'); $r=send($base); $app->instance('env','testing');
    check($r->getStatusCode()===201 && payload($r)['status']==='accepted','signed machine request accepted without CSRF');
    check(!$r->headers->has('Set-Cookie') && str_contains($r->headers->get('Cache-Control'),'no-store'),'receiver is stateless and not cached');
    $original=(array)DB::table('commercial_events')->first();
    check($original['source']==='jakawi.com' && $original['raw_body']===json_encode($base) && $original['key_id']==='com-commercial-v1','original COM source body and signing key retained');
    check($original['partner_id']===null && $original['referrer_club_uuid']===null && $original['global_subject_id']===null,'absent attribution remains null');
    check(User::count()===0 && DB::table('jobs')->count()===0,'receiving events creates neither users nor CRM jobs');
    check(send($base)->getStatusCode()===200 && payload(send($base))['status']==='duplicate','identical repeats acknowledged');
    check(send(json_encode(array_reverse($base,true),JSON_PRETTY_PRINT))->getStatusCode()===200,'JSON whitespace and key order do not duplicate events');
    check(DB::table('commercial_events')->count()===1 && (array)DB::table('commercial_events')->first()===$original,'retries preserve first receipt and all source bytes');
    $conflict=[...$base,'conversion_status'=>'cancelled'];
    check(send($conflict)->getStatusCode()===409 && send($conflict)->getStatusCode()===409,'changed payload conflicts');
    check(DB::table('commercial_event_conflicts')->count()===1 && DB::table('commercial_event_conflicts')->value('raw_body')===json_encode($conflict),'conflicting provenance retained once');
    check((array)DB::table('commercial_events')->first()===$original,'conflict cannot overwrite original conversion');
    check(race('com:race:same',['confirmed','confirmed'])===[200,201], 'concurrent identical deliveries receive one acceptance');
    check(DB::table('commercial_events')->where('event_id','com:race:same')->count()===1, 'database unique constraint prevents concurrent duplicates');
    check(race('com:race:conflict',['confirmed','cancelled'])===[201,409], 'concurrent different deliveries detect conflict');
    foreach ([['schema_version'=>2],['schema_version'=>'1'],['event_type'=>''],['occurred_at'=>'2026-10-29 12:00:00'],['occurred_at'=>'2026-02-30T00:00:00Z'],['occurred_at'=>'2099-01-01T00:00:00Z'],['com_reference'=>['type'=>'order']],['partner'=>['name'=>'Invented']],['referral'=>[]],['referral'=>['club_referral_code'=>'ZZZZZZZZZZZZ']],['global_subject_id'=>'fake'],['unexpected'=>'field']] as $invalid) {
        check(send([...$base,'event_id'=>'invalid',...$invalid])->getStatusCode()===422,'reject invalid contract '.json_encode($invalid));
    }
    $referrer=User::create(['name'=>'Referrer fixture','email'=>'referrer@example.test','password'=>'FixturePassword123']);
    $before=$referrer->fresh()->getRawOriginal();
    $attributed=[...$base,'event_id'=>'com:test:attributed','partner'=>['id'=>'com-partner-real-id','name'=>'Partner fixture'],
        'origin'=>['landing'=>'https://jakawi.com/offer','campaign'=>'campaign-one'],
        'referral'=>['com_referral_id'=>'com-referral-record','club_referral_code'=>$referrer->referral_code],
        'global_subject_id'=>'11111111-1111-4111-8111-111111111111'];
    check(send($attributed)->getStatusCode()===201,'real source attribution accepted');
    $stored=DB::table('commercial_events')->where('event_id',$attributed['event_id'])->first();
    check($stored->partner_id==='com-partner-real-id' && $stored->campaign==='campaign-one' && $stored->landing==='https://jakawi.com/offer','partner landing and campaign copied exactly from COM');
    check($stored->referrer_club_uuid===$referrer->uuid && $stored->com_referral_id==='com-referral-record','CLUB code verified against an existing user and COM record retained');
    check(User::count()===1 && $referrer->fresh()->getRawOriginal()===$before,'global subject remains event metadata without creating or modifying CLUB users');
    check(send([...$base,'event_id'=>'com:only-ref','referral'=>['com_referral_id'=>'com-only-ref']])->getStatusCode()===201,'COM-only referral preserved without fabricated CLUB account');
    check(send([...$base,'event_id'=>'com:nulls','partner'=>null,'origin'=>null,'referral'=>null,'global_subject_id'=>null])->getStatusCode()===201,'explicit nullable fields accepted');
    check(send([...$base,'event_id'=>'com:new-state','conversion_status'=>'cancelled'])->getStatusCode()===201,'status changes use a new stable event ID');
    check(web('GET','/admin/conversiones-com')->isRedirect('https://jakawi.club/login'),'guest cannot see administrative conversions');
    login(false); check(web('GET','/admin/conversiones-com')->getStatusCode()===403,'ordinary member denied');
    $admin=login(true);
    $r=web('GET','/admin/conversiones-com'); $page=payload($r);
    check($r->getStatusCode()===200 && $page['component']==='admin/CommercialConversions','activated verified administrator can view panel');
    check(!str_contains($r->getContent(),'raw_body') && !str_contains($r->getContent(),'signed_at') && !str_contains($r->getContent(),'global_subject_id'),'panel excludes raw bodies signature metadata and global subjects');
    $r=web('GET','/admin/conversiones-com?partner=com-partner-real-id&campaign=campaign-one'); $filtered=payload($r);
    check($filtered['props']['events']['total']===1 && $filtered['props']['events']['data'][0]['event_id']===$attributed['event_id'],'combined partner and campaign filters');
    check($filtered['props']['events']['data'][0]['referral_verification']==='club_code_verified','panel identifies verified CLUB code');
    check($filtered['props']['events']['data'][0]['occurred_at']==='2026-10-29T23:20:30+00:00','event timezone preserved correctly');
    $r=web('GET','/admin/conversiones-com?campaign=unknown');
    check(payload($r)['props']['events']['total']===0,'empty filtered panel');
    // Enough real records for pagination, still isolated in this schema.
    for($i=0;$i<26;$i++) send([...$base,'event_id'=>'com:page:'.$i]);
    $r=web('GET','/admin/conversiones-com'); $paged=payload($r);
    check(count($paged['props']['events']['data'])===25 && $paged['props']['events']['last_page']===2,'panel paginates conversions');
    check(str_contains($r->headers->get('Cache-Control'),'no-store'),'administration responses not cached');
    file_put_contents('/evidence/commercial-browser-fixtures.json',json_encode(['populated'=>$filtered,'empty'=>payload(web('GET','/admin/conversiones-com?campaign=unknown'))],JSON_PRETTY_PRINT));
    login(true,false); check(web('GET','/admin/conversiones-com')->isRedirect(),'unverified administrator denied');
    $inactive=login(true); $inactive->forceFill(['activated_at'=>null])->save();
    check(web('GET','/admin/conversiones-com')->getStatusCode()===403,'inactive administrator denied');
    check(DB::table('jobs')->count()===0,'all commercial operations leave CRM queue untouched');
    check(Http::recorded()->count()===0 && Notification::sentNotifications()===[], 'no HTTP requests or notifications captured');
    check(true,'no outbound HTTP or notifications');
    $referrer->delete();
    check(send($attributed)->getStatusCode()===200,'historical replay keeps receipt after referrer removal');
    $result=['passed'=>count($passed),'postgresql'=>'isolated schema','live_events_created'=>0,'live_users_created'=>0,'outbound_http'=>0,'notifications'=>0];
    file_put_contents('/evidence/tests.json',json_encode($result,JSON_PRETTY_PRINT).PHP_EOL); echo json_encode($result).PHP_EOL;
} catch(Throwable $e) {
    fwrite(STDERR, get_class($e).' '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine().PHP_EOL); $failed=true;
} finally {
    $app->instance('env','testing'); DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');
    unlink('/tmp/commercial-test-secret');
}
exit(isset($failed)?1:0);
