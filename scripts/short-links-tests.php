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
$schema = 'short_link_test_'.bin2hex(random_bytes(6));
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
    if (!str_starts_with($r->getContent(), '{')) throw new RuntimeException('Non-JSON response: '.$r->getStatusCode().' Location: '.$r->headers->get('Location').' body: '.substr($r->getContent(),0,150));
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


if (($argv[1] ?? '') === '--race') {
    config(['database.connections.pgsql.search_path'=>$argv[2]]); DB::purge('pgsql');
    echo app(App\Services\AffiliateShortLinks::class)->forAffiliate(User::findOrFail($argv[3]), 'dossis-43')->code;
    exit(0);
}
function snapshot(): array {
    $result=[];
    foreach (['commercial_events','commission_ledger','commission_rules','commission_audit','users','jobs','marketing_event_totals'] as $table) {
        if (!Illuminate\Support\Facades\Schema::hasTable($table)) continue;
        $rows=DB::table($table)->get()->map(fn($row)=>(array)$row)->all();
        usort($rows,fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));
        $result[$table]=hash('sha256',json_encode($rows));
    }
    return $result;
}
function publicLink(string $path): Symfony\Component\HttpFoundation\Response {
    Auth::forgetGuards();
    return kernelRequest(Request::create('https://jakawi.club'.$path,'GET',[],[],[],['HTTP_ACCEPT'=>'text/html','REMOTE_ADDR'=>'192.0.2.71','HTTP_USER_AGENT'=>'Isolated fixture']));
}
try {
    DB::statement('CREATE SCHEMA '.$schema);
    config(['database.connections.pgsql.search_path'=>$schema,'commercial.secret_file'=>'/tmp/commercial-test-secret','identity.enabled'=>false]);
    DB::purge('pgsql'); Artisan::call('migrate',['--force'=>true]);
    // Compile the existing router directly. Artisan route:cache boots a second
    // application and would replace Eloquent's resolver with a default connection.
    $compiled = app('router')->getRoutes()->compile();
    app('router')->setCompiledRoutes($compiled);
    check(DB::selectOne('select current_schema() as name')->name===$schema
        && User::resolveConnection()->selectOne('select current_schema() as name')->name===$schema,
        'compiled route tests retain isolated query and Eloquent connections');
    Notification::fake(); Http::preventStrayRequests(); Http::fake();
    $links=app(App\Services\AffiliateShortLinks::class);
    check(DB::table('commercial_offers')->count()===1,'only real Dossis pilot configured');
    check(web('GET','/admin/ofertas-comerciales')->isRedirect('https://jakawi.club/login'),'guest cannot administer offers');
    $a=login(false); $aCookies=$cookies;
    $aPage=payload(web('GET','/afiliados')); $aCampaign=$aPage['props']['campaign'];
    $aLink=DB::table('affiliate_short_links')->where('affiliate_id',$a->id)->first();
    check(preg_match('~^https://jakawi.club/r/[A-Za-z0-9]{8}$~D',$aCampaign['personal_url'])===1,'panel exposes random eight-character short link without sequential IDs');
    check($aCampaign['long_url']==='https://jakawi.com/l/dossis-43-cafe-y-antojo?club_ref='.$a->referral_code,'panel secondary destination contains real CLUB code');
    check($aLink->referral_code===$a->referral_code && $aLink->offer_key==='dossis-43','permanent affiliate offer and referral snapshot stored');
    check(payload(web('GET','/afiliados'))['props']['campaign']['personal_url']===$aCampaign['personal_url'] && DB::table('affiliate_short_links')->count()===1,'reopening panel reuses affiliate offer pair');
    check(web('GET','/admin/ofertas-comerciales')->getStatusCode()===403,'ordinary affiliate cannot see administration');
    check(web('PUT','/admin/ofertas-comerciales/dossis-43',['destination_url'=>'https://jakawi.com/other','status'=>'paused'])->getStatusCode()===403,'ordinary affiliate cannot change destination or status');
    $b=login(false);
    $bPage=payload(web('GET','/afiliados'));$bLink=DB::table('affiliate_short_links')->where('affiliate_id',$b->id)->first();
    check($aLink->code!==$bLink->code,'two affiliates have distinct links');
    $base=['schema_version'=>1,'event_id'=>'short-link:historic','event_type'=>'order.confirmed','occurred_at'=>'2026-10-29T12:00:00Z',
        'com_reference'=>['type'=>'order','id'=>'historic-fixture'],'conversion_status'=>'confirmed','partner'=>['id'=>'fixture-dossis','name'=>'Dossis 43'],
        'referral'=>['club_referral_code'=>$a->referral_code]];
    check(send($base)->getStatusCode()===201,'historical conversion fixture accepted only in isolated schema');
    $before=snapshot();
    $sessionBefore=DB::table('sessions')->get()->toJson();
    foreach ([$aLink,$bLink] as $link) {
        $response=publicLink('/r/'.$link->code.'?club_ref=ATTACKER&url=https://evil.example/&offer=other');
        check($response->getStatusCode()===302,'active short link returns temporary 302');
        check($response->headers->get('Location')==='https://jakawi.com/l/dossis-43-cafe-y-antojo?club_ref='.$link->referral_code,'public parameters cannot override exact affiliate attribution or destination');
        check(str_contains($response->headers->get('Cache-Control'),'no-store') && !$response->headers->get('Set-Cookie'),'redirect forbids caching and sets no cookies');
    }
    check(DB::table('sessions')->get()->toJson()===$sessionBefore,'public redirects do not create or touch session rows');
    check(snapshot()===$before,'redirects leave historical conversions economics identities and metrics byte-identical');
    $new='https://jakawi.com/l/dossis-nuevo?utm_source=club&club_ref=OLD&x=one%20two&x=three+four&club%5Fref=OLD2&club_ref%5B%5D=OLD3&club.ref=OLD4#menu';
    DB::table('commercial_offers')->where('key','dossis-43')->update(['destination_url'=>$new]);
    $effective='https://jakawi.com/l/dossis-nuevo?utm_source=club&x=one%20two&x=three+four&club_ref='.$a->referral_code.'#menu';
    check(publicLink('/r/'.$aLink->code)->headers->get('Location')===$effective,'slug changes preserve same link and exact necessary query bytes without duplicate club_ref');
    check(DB::table('commercial_offers')->value('original_url')==='https://jakawi.com/l/dossis-43-cafe-y-antojo','original offer URL persists after destination update');
    $fixtures=['active'=>$aPage];
    foreach (['paused'=>'Esta oferta no está disponible por ahora','finished'=>'Esta oferta ha finalizado'] as $state=>$title) {
        DB::table('commercial_offers')->where('key','dossis-43')->update(['status'=>$state]);
        $response=publicLink('/r/'.$aLink->code);
        check($response->getStatusCode()===200 && str_contains($response->getContent(),$title) && !$response->headers->get('Location'),'state '.$state.' displays correct public message without silent redirect');
        check(str_contains($response->getContent(),'href="https://jakawi.com/"') && !$response->headers->get('Set-Cookie'),'state '.$state.' offers explicit COM exploration with no visitor cookie');
        file_put_contents('/evidence/'.$state.'-fixture.html',$response->getContent());
        $cookies=$aCookies; $page=payload(web('GET','/afiliados'));$fixtures[$state]=$page;
        check($page['props']['campaign']['status']===$state && $page['props']['campaign']['personal_url']===$aCampaign['personal_url'],'state '.$state.' retains permanent link in affiliate history');
        check($page['props']['conversions']['total']===1,'state '.$state.' preserves existing conversion totals');
    }
    check(snapshot()===$before,'offer state and slug changes never modify historical financial or conversion data');
    $response=publicLink('/r/Unknown8');
    check($response->getStatusCode()===404 && !$response->headers->get('Location') && !$response->headers->get('Set-Cookie'),'unknown commercial code returns stateless 404');
    file_put_contents('/evidence/missing-fixture.html',$response->getContent());
    DB::table('commercial_offers')->where('key','dossis-43')->update(['status'=>'active','destination_url'=>'https://evil.example/']);
    check(!publicLink('/r/'.$aLink->code)->headers->get('Location'),'runtime validation blocks unapproved persisted destinations');
    $admin=login(true);$adminCookies=$cookies;
    $adminPage=payload(web('GET','/admin/ofertas-comerciales'));$fixtures['admin']=$adminPage;
    check($adminPage['component']==='admin/CommercialOffers' && $adminPage['props']['links']['total']===2,'existing CLUB administrator sees configured offers and associated links');
    check(!str_contains(json_encode($adminPage['props']['links']),$a->email),'associated links disclose no affiliate email');
    $valid='https://jakawi.com/l/dossis-43-cafe-y-antojo?utm_campaign=pilot&club_ref=stale';
    foreach (['active','paused','finished'] as $status) {
        $response=web('PUT','/admin/ofertas-comerciales/dossis-43',['destination_url'=>$valid,'status'=>$status]);
        check($response->isRedirect() && DB::table('commercial_offers')->value('status')===$status,'admin can manually set '.$status.' with updated destination');
    }
    foreach (['http://jakawi.com/l/x','https://evil.example/x','https://jakawi.com.evil.example/x','https://jakawi.com@evil.example/x','https://user@jakawi.com/x','https://jakawi.com:444/x','https://jakawi.club/r/loop','//jakawi.com/x',"https://jakawi.com/\r\nLocation: evil",'https://jakawi.com\\@evil.example/x','javascript:alert(1)'] as $url) {
        check(!$links->validDestination($url),'reject unsafe destination '.json_encode($url));
        $r=kernelRequest(Request::create('https://jakawi.club/admin/ofertas-comerciales/dossis-43','PUT',['destination_url'=>$url,'status'=>'active'],$cookies,[],['HTTP_ACCEPT'=>'application/json']));
        check($r->getStatusCode()===422 && DB::table('commercial_offers')->value('destination_url')===$valid,'unsafe admin update cannot mutate stored destination');
    }
    check($links->validDestination('https://www.jakawi.com/l/x?keep=yes'),'approved www commercial domain accepted');
    $r=kernelRequest(Request::create('https://jakawi.club/admin/ofertas-comerciales/dossis-43','PUT',['destination_url'=>$valid,'status'=>'deleted'],$cookies,[],['HTTP_ACCEPT'=>'application/json']));
    check($r->getStatusCode()===422,'admin accepts only three defined states');
    check(web('PUT','/admin/ofertas-comerciales/unknown',['destination_url'=>$valid,'status'=>'active'])->getStatusCode()===404,'admin cannot manufacture a new offer through update endpoint');
    $fixtures['admin']=payload(web('GET','/admin/ofertas-comerciales'));
    $c=login(false);
    Illuminate\Support\Str::createRandomStringsUsingSequence([$aLink->code,'UniqueC8']);
    check($links->forAffiliate($c,'dossis-43')->code==='UniqueC8','random collision retries without replacing an existing link');
    Illuminate\Support\Str::createRandomStringsNormally();
    $d=login(false);$workers=[];
    for($i=0;$i<2;$i++) {
        $process=proc_open([PHP_BINARY,__FILE__,'--race',$schema,(string)$d->id],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]);$workers[]=[$process,$pipes];
    }
    $codes=[];
    foreach($workers as [$process,$pipes]) {
        $codes[]=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        check(proc_close($process)===0,'concurrent link worker completed '.$err);
    }
    check($codes[0]===$codes[1] && DB::table('affiliate_short_links')->where('affiliate_id',$d->id)->count()===1,'simultaneous requests persist one affiliate offer link');
    $beforeCommand=snapshot();
    Artisan::call('short-links:prepare-pilot');
    $issued=DB::table('affiliate_short_links')->orderBy('code')->get()->toJson();
    Artisan::call('short-links:prepare-pilot');
    check(DB::table('affiliate_short_links')->orderBy('code')->get()->toJson()===$issued,'pilot preparation is idempotent for all real eligible fixture accounts');
    check(snapshot()===$beforeCommand,'pilot preparation creates no users conversions commissions jobs or metrics');
    // Existing registration invitations continue through their original controller.
    $cookies=[];
    check(web('GET','/r/'.$a->referral_code,[],false)->isRedirect('https://jakawi.club/#registro'),'original 12-character registration invitation preserved');
    $route=app('router')->getRoutes()->getByName('short-link');
    check($route->gatherMiddleware()===[],'commercial redirect has no session CSRF capture or user middleware');
    $adminRoute=app('router')->getRoutes()->getByName('commercial-offers.index');
    check(in_array(App\Http\Middleware\MarketingAdmin::class,$adminRoute->gatherMiddleware(),true),'offer management uses existing CLUB admin authorization');
    check(Http::recorded()->count()===0 && Notification::sentNotifications()===[],'no COM API calls emails or external side effects');
    file_put_contents('/evidence/short-links-browser-fixtures.json',json_encode($fixtures,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    $result=['passed'=>count($passed),'postgresql'=>'isolated schema','live_users_created'=>0,'live_events_created'=>0,'concurrency'=>true,'historical_data_unchanged'=>true,'public_sessions'=>0,'outbound_http'=>0];
    file_put_contents('/evidence/tests.json',json_encode($result,JSON_PRETTY_PRINT).PHP_EOL);echo json_encode($result).PHP_EOL;
} catch(Throwable $e) {
    fwrite(STDERR,get_class($e).' '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine().PHP_EOL);$failed=true;
} finally {
    DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');unlink('/tmp/commercial-test-secret');
}
exit(isset($failed)?1:0);
