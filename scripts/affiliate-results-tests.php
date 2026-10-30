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
    config(['database.connections.pgsql.search_path'=>$schema,'commercial.secret_file'=>'/tmp/commercial-test-secret','identity.enabled'=>false,'commissions.economic_cutoff'=>'2026-10-28T00:00:00Z']);
    DB::purge('pgsql');
    check(DB::selectOne('select current_schema() as name')->name===$schema && User::resolveConnection()->selectOne('select current_schema() as name')->name===$schema,'isolation verified before migration and fixtures');
    Artisan::call('migrate',['--force'=>true]);
    app('router')->setCompiledRoutes(app('router')->getRoutes()->compile());
    Notification::fake(); Http::preventStrayRequests(); Http::fake();
    $columns=DB::table('information_schema.columns')->where('table_schema',$schema)->where('table_name','affiliate_link_daily_clicks')->orderBy('ordinal_position')->pluck('column_name')->all();
    check($columns===['link_code','day','clicks'],'aggregates contain only link day and count; no IP email cookies or visitor identity');
    check(DB::table('affiliate_link_daily_clicks')->count()===0,'no invented historical clicks');
    DB::table('commercial_offers')->where('key','dossis-43')->update(['catalog_present'=>true,'availability_status'=>'available']);
    DB::table('commercial_offers')->insert(['key'=>'benefit:2','source'=>'com','source_id'=>'benefit:2','name'=>'Segunda oportunidad','original_url'=>'https://jakawi.com/l/segunda','destination_url'=>'https://jakawi.com/l/segunda','catalog_present'=>true,'availability_status'=>'available','created_at'=>now(),'updated_at'=>now()]);
    $a=login(false);$aCookies=$cookies;$links=app(App\Services\AffiliateShortLinks::class);
    $first=$links->forAffiliate($a,'dossis-43');$second=$links->forAffiliate($a,'benefit:2');
    $beforeSessions=DB::table('sessions')->get()->toJson();
    $beforeHistory=DB::table('affiliate_short_links')->orderBy('code')->get()->toJson();
    Illuminate\Support\Carbon::setTestNow('2026-10-30T03:59:00Z');
    $r=openLink($first->code);
    check($r->getStatusCode()===302 && $r->headers->get('Location')==='https://jakawi.com/l/dossis-43-cafe-y-antojo?club_ref='.$a->referral_code,'active GET redirects with exact real attribution');
    check(clickTotal()===1 && DB::table('affiliate_link_daily_clicks')->value('day')==='2026-10-29','one GET writes one aggregate on Bolivia day');
    openLink($first->code);
    check(clickTotal()===2 && DB::table('affiliate_link_daily_clicks')->count()===1,'repeat opens increment existing aggregate, not unique visitors');
    Illuminate\Support\Carbon::setTestNow('2026-10-30T04:01:00Z');openLink($first->code);
    check(clickTotal()===3 && DB::table('affiliate_link_daily_clicks')->count()===2,'Bolivia midnight creates correct next day');
    Illuminate\Support\Carbon::setTestNow();
    foreach([
        ['HEAD',[]], ['GET',['HTTP_PURPOSE'=>'prefetch']], ['GET',['HTTP_PURPOSE'=>'preview']], ['GET',['HTTP_SEC_PURPOSE'=>'prefetch;prerender']],
        ['GET',['HTTP_X_PURPOSE'=>'preview prefetch']], ['GET',['HTTP_X_MOZ'=>'prefetch']], ['GET',['HTTP_X_INERTIA_PREFETCH'=>'true']],
        ['GET',['HTTP_USER_AGENT'=>'facebookexternalhit/1.1']], ['GET',['HTTP_USER_AGENT'=>'WhatsApp/2.25']],
    ] as [$method,$headers]) {
        $response=openLink($first->code,$method,$headers);
        check($response->getStatusCode()===302 && clickTotal()===3,'HEAD/known prefetch does not count '.json_encode($headers));
        check(!$response->headers->get('Set-Cookie'),'stateless redirect introduces no cookies');
    }
    check(!$r->headers->get('Set-Cookie') && str_contains($r->headers->get('Cache-Control'),'no-store'),'real GET has no cookies and forbids redirect caching');
    foreach(['paused','unavailable','upcoming','expired','ended','archived'] as $state) {
        DB::table('commercial_offers')->where('key','dossis-43')->update(['availability_status'=>$state]);
        $r=openLink($first->code);
        check($r->getStatusCode()===200 && !$r->headers->get('Location') && clickTotal()===3,'no offer click for '.$state);
    }
    DB::table('commercial_offers')->where('key','dossis-43')->update(['availability_status'=>'available','local_paused'=>true]);
    check(!openLink($first->code)->headers->get('Location') && clickTotal()===3,'local pause does not count');
    DB::table('commercial_offers')->where('key','dossis-43')->update(['local_paused'=>false,'catalog_present'=>false]);
    check(!openLink($first->code)->headers->get('Location') && clickTotal()===3,'retired offer does not count');
    DB::table('commercial_offers')->where('key','dossis-43')->update(['catalog_present'=>true,'destination_url'=>'https://example.test/unsafe']);
    check(!openLink($first->code)->headers->get('Location') && clickTotal()===3,'invalid persisted destination does not count');
    DB::table('commercial_offers')->where('key','dossis-43')->update(['destination_url'=>'https://jakawi.com/l/dossis-43-cafe-y-antojo']);
    check(openLink('Unknown8')->getStatusCode()===404 && clickTotal()===3,'unknown link does not count');
    check(DB::table('sessions')->get()->toJson()===$beforeSessions,'click counting never creates or modifies browser sessions');
    $workers=[];
    for($i=0;$i<3;$i++) {
        $proc=proc_open([PHP_BINARY,__FILE__,'--click-worker',$schema,$first->code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]);$workers[]=[$proc,$pipes];
    }
    foreach($workers as [$proc,$pipes]) {
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        check(proc_close($proc)===0 && $out==='8','isolated concurrent worker completes '.$err);
    }
    check(clickTotal()===27,'atomic upsert loses zero increments across concurrent GET requests');
    openLink($second->code);
    check(clickTotal()===28 && DB::table('affiliate_short_links')->orderBy('code')->get()->toJson()===$beforeHistory,'distinct opportunity count leaves link identity and history intact');
    // Boundary evidence is synthetic only in the verified isolated schema.
    DB::table('affiliate_link_daily_clicks')->insert([
        ['link_code'=>$first->code,'day'=>now('America/La_Paz')->subDays(6)->toDateString(),'clicks'=>5],
        ['link_code'=>$first->code,'day'=>now('America/La_Paz')->subDays(7)->toDateString(),'clicks'=>7],
    ]);
    $totals=app(App\Services\AffiliateLinkClicks::class)->summary($a);
    check($totals===['total'=>40,'last_7_days'=>33],'seven days includes today and six prior Bolivia days only');
    $invitee=new User(['name'=>'Registro CLUB comprobado','email'=>'invitee@example.test','password'=>'FixturePassword123']);
    $invitee->forceFill(['referred_by_id'=>$a->id])->save();
    $b=login(false);$bCookies=$cookies;$other=$links->forAffiliate($b,'dossis-43');openLink($other->code);
    check(app(App\Services\AffiliateLinkClicks::class)->summary($b)===['total'=>1,'last_7_days'=>1],'clicks isolated by real affiliate ownership');
    $redemption=event('results:redeem',$a);
    check(send($redemption)->getStatusCode()===201 && send($redemption)->getStatusCode()===200,'commercial retry preserves single original operation');
    check(send([...$redemption,'event_id'=>'results:redeem:update','occurred_at'=>'2026-10-29T15:00:00Z'])->getStatusCode()===201,'second signed state for same reference accepted');
    check(send(event('results:order',$a,'order'))->getStatusCode()===201,'other confirmed operation remains a conversion, not a redemption');
    check(send(event('results:unattributed',null))->getStatusCode()===201,'COM operation without affiliate remains received');
    check(send(event('results:other-owner',$b))->getStatusCode()===201,'other affiliate signed conversion received');
    check(send(event('results:cancelled',$a))->getStatusCode()===201,'initial confirmed state accepted');
    check(send([...event('results:cancelled:update',$a,'redemption','cancelled','2026-10-29T16:00:00Z')])->getStatusCode()===201,'later cancellation supersedes previous conversion');
    $summary=app(App\Services\AffiliateResults::class)->summary($a);
    check($summary['clicks']===$totals && $summary['club_registrations']===1 && $summary['confirmed_redemptions']===1,'results separate clicks verified CLUB registrations and verified current redemptions');
    $cookies=$aCookies;$page=payload(web('GET','/afiliados?tab=results'));
    check($page['props']['conversions']['total']===2,'conversion list deduplicates while excluding unattributed other-owner and cancelled operations');
    check(array_column($page['props']['offers']['data'],'clicks_total','key')===['dossis-43'=>39,'benefit:2'=>1],'per-opportunity totals match only own links');
    check(!str_contains(json_encode($page['props']['results']),$b->email) && !str_contains(json_encode($page['props']['offers']),$other->code),'results contain no other affiliate identities or links');
    $admin=login(true);
    $adminPage=payload(web('GET','/admin/conversiones-com'));
    check(in_array('results:unattributed',array_column($adminPage['props']['events']['data'],'event_id'),true),'admin retains unattributed COM operations');
    check(web('GET','/afiliados?tab=results')->getStatusCode()===200,'results tab is a server-recognized canonical URL');
    // Real ledger flow, isolated rules and approvals; no financial engine changes.
    foreach([['fixture-bob','BOB','10.2500'],['fixture-usd','USD','7.1250']] as [$partner,$currency,$amount]) {
        DB::table('commission_rules')->insert(['partner_id'=>$partner,'event_type'=>'redemption.confirmed','fixed_amount'=>$amount,'currency'=>$currency,'effective_at'=>'2026-10-28T00:00:00+00','status'=>'active','created_by'=>$admin->uuid,'created_at'=>now(),'updated_at'=>now()]);
        $id='results:approved:'.$currency;
        check(send(event($id,$a,partner:$partner))->getStatusCode()===201,'isolated remunerable '.$currency.' event');
        $entry=DB::table('commission_ledger')->where('com_reference_id',$id)->first();
        app(App\Services\Commissions\CommissionEngine::class)->review($entry->id,['version'=>$entry->version,'action'=>'approve','reason'=>'Isolated result projection test'],$admin);
    }
    send(event('results:pending',$a,partner:'fixture-bob'));
    $summary=app(App\Services\AffiliateResults::class)->summary($a);
    check(array_map(fn($row)=>(array)$row,$summary['approved_commissions'])===[['currency'=>'BOB','amount'=>'10.2500'],['currency'=>'USD','amount'=>'7.1250']],'approved exact decimal sums grouped by currency, pending excluded');
    $cookies=$bCookies;$bPage=payload(web('GET','/afiliados?tab=results&user_id='.$a->id.'&club_ref='.$a->referral_code));
    check($bPage['props']['results']['clicks']['total']===1 && $bPage['props']['results']['club_registrations']===0 && $bPage['props']['results']['confirmed_redemptions']===1 && $bPage['props']['results']['approved_commissions']===[],'summary cannot be overridden by another user ID code or query');
    // Failure remains isolated and does not block a valid redirect.
    DB::statement('ALTER TABLE affiliate_link_daily_clicks RENAME TO hidden_clicks');
    check(openLink($first->code)->getStatusCode()===302,'counter write failure preserves fast attributed redirect');
    DB::statement('ALTER TABLE hidden_clicks RENAME TO affiliate_link_daily_clicks');
    $cookies=$aCookies;
    $fixtures=[];
    foreach(['explore','links','history','results'] as $tab)$fixtures[$tab]=payload(web('GET','/afiliados?tab='.$tab));
    $fixtures['filter']=payload(web('GET','/afiliados?q=Segunda'));
    $fixtures['dashboard']=payload(web('GET','/dashboard'));
    $filesBefore=DB::table('affiliate_link_daily_clicks')->orderBy('link_code')->orderBy('day')->get()->toJson();
    web('GET','/afiliados?tab=results');
    check(DB::table('affiliate_link_daily_clicks')->orderBy('link_code')->orderBy('day')->get()->toJson()===$filesBefore,'results reads do not fabricate clicks');
    check(Http::recorded()->count()===0 && Notification::sentNotifications()===[],'no outgoing COM requests or mail');
    file_put_contents('/evidence/results-browser-fixtures.json',json_encode($fixtures,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    $result=['passed'=>count($passed),'postgresql'=>'schema verified before fixture writes','concurrent_gets'=>24,'atomic_counts'=>true,'personal_data_columns'=>0,'live_fixtures'=>0,'affiliate_privacy'=>true,'conversions_deduplicated'=>true,'admin_unattributed_preserved'=>true];
    file_put_contents('/evidence/tests.json',json_encode($result,JSON_PRETTY_PRINT).PHP_EOL);echo json_encode($result).PHP_EOL;
} catch(Throwable $e) {
    fwrite(STDERR,get_class($e).' '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine().PHP_EOL);$failed=true;
} finally {
    Illuminate\Support\Carbon::setTestNow();DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');unlink('/tmp/commercial-test-secret');
}
exit(isset($failed)?1:0);
