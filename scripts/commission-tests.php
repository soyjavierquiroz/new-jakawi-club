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
$schema = 'commission_test_'.bin2hex(random_bytes(6));
$passed = [];
$cookies = [];
$secret = str_repeat('c', 64);
if (($argv[1] ?? '') !== '--race') file_put_contents('/tmp/commercial-test-secret', $secret);

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


function ledger(string $reference): object {
    return DB::table('commission_ledger')->where('com_reference_id',$reference)->firstOrFail();
}
function postAdmin(string $path, array $data): Symfony\Component\HttpFoundation\Response {
    app('cache')->flush();
    return web('POST',$path,$data);
}
function reviewEntry(object $entry, string $action, array $extra=[]): Symfony\Component\HttpFoundation\Response {
    return postAdmin('/admin/comisiones/'.$entry->id.'/revision', ['version'=>$entry->version,'action'=>$action,'reason'=>'Evidencia de prueba aislada revisada.',...$extra]);
}
function createRule(array $change=[]): object {
    $response=postAdmin('/admin/reglas-comisiones', ['partner_id'=>'fixture-partner','event_type'=>'order.confirmed','fixed_amount'=>'0.1001','currency'=>'BOB','effective_at'=>'2026-10-01T00:00:00Z',...$change]);
    if(!$response->isRedirect()) throw new RuntimeException('Cannot create fixture rule: '.$response->getStatusCode().' '.$response->getContent());
    return DB::table('commission_rules')->orderByDesc('id')->firstOrFail();
}
function activateRule(object $rule, string $status='active'): void {
    $response=postAdmin('/admin/reglas-comisiones/'.$rule->id.'/estado',['status'=>$status,'reason'=>'Regla de prueba aislada autorizada.']);
    if(!$response->isRedirect()) throw new RuntimeException('Cannot change fixture rule state.');
}
function expectDatabaseBlock(callable $action, string $name): void {
    try { DB::transaction($action); $blocked=false; } catch (Illuminate\Database\QueryException) { $blocked=true; }
    check($blocked,$name);
}

if (($argv[1] ?? null)==='--race') {
    config(['database.connections.pgsql.search_path'=>$argv[2],'commercial.secret_file'=>'/tmp/commercial-test-secret']);DB::purge('pgsql');
    if($argv[3]==='approve') {
        try {
            app(App\Services\Commissions\CommissionEngine::class)->review((int)$argv[4],['version'=>(int)$argv[5],'action'=>'approve','reason'=>'Concurrent isolated approval fixture'],User::findOrFail($argv[6]));
            echo 'approved';
        } catch (Illuminate\Validation\ValidationException) { echo 'blocked'; }
    } else {
        $event=json_decode(base64_decode($argv[4]),true,512,JSON_THROW_ON_ERROR);
        echo send([...$event,'event_id'=>$argv[5]])->getStatusCode();
    }
    exit;
}
function concurrent(array $arguments): array {
    global $schema;
    $children=[];
    foreach($arguments as $args) {
        $proc=proc_open([PHP_BINARY,__FILE__,'--race',$schema,...$args],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]);$children[]=[$proc,$pipes];
    }
    $results=[];
    foreach($children as [$proc,$pipes]) {
        $results[]=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        if(proc_close($proc)!==0) throw new RuntimeException($error);
    }
    sort($results);return $results;
}

try {
    DB::statement('CREATE SCHEMA '.$schema);
    config(['database.connections.pgsql.search_path'=>$schema,'commercial.secret_file'=>'/tmp/commercial-test-secret','identity.enabled'=>false]);DB::purge('pgsql');
    Artisan::call('migrate',['--force'=>true]);Notification::fake();Http::preventStrayRequests();Http::fake();
    check(DB::table('commission_rules')->count()===0 && DB::table('commission_ledger')->count()===0,'migration creates no rules amounts or obligations');
    check(web('GET','/admin/reglas-comisiones')->isRedirect('https://jakawi.club/login') && web('GET','/admin/comisiones')->isRedirect(),'guest economic administration denied');
    $a=login(false);$aCookies=$cookies;
    check(web('GET','/admin/reglas-comisiones')->getStatusCode()===403 && postAdmin('/admin/reglas-comisiones',[])->getStatusCode()===403,'ordinary affiliate cannot configure rules');
    $base=['schema_version'=>1,'event_id'=>'economic:a','event_type'=>'order.confirmed','occurred_at'=>'2026-10-29T12:00:00Z',
        'com_reference'=>['type'=>'order','id'=>'economic-order-a'],'conversion_status'=>'confirmed','partner'=>['id'=>'fixture-partner','name'=>'Partner aislado'],
        'referral'=>['club_referral_code'=>$a->referral_code]];
    check(send($base)->getStatusCode()===201,'verified COM conversion accepted through original HMAC receiver');
    $entry=ledger('economic-order-a');
    check($entry->status==='candidate' && $entry->affiliate_uuid===$a->uuid && $entry->affiliate_code===$a->referral_code,'candidate linked to verified affiliate and original operation');
    check($entry->amount===null && $entry->currency===null && $entry->rule_id===null && $entry->reconciliation==='pending','without a rule candidates have no amount or obligation');
    check(send($base)->getStatusCode()===200 && DB::table('commission_ledger')->count()===1,'receipt retries never duplicate ledger');
    check(send([...$base,'event_id'=>'economic:a:state','occurred_at'=>'2026-10-29T13:00:00Z','event_type'=>'order.updated'])->getStatusCode()===201 && DB::table('commission_ledger')->count()===1,'multiple state events share a unique economic operation');
    check(send([...$base,'event_id'=>'economic:no-ref','com_reference'=>['type'=>'order','id'=>'economic-no-ref'],'referral'=>null,'global_subject_id'=>$a->uuid])->getStatusCode()===201,'code-less event accepted without identity-based attribution');
    check(ledger('economic-no-ref')->status==='registered' && ledger('economic-no-ref')->affiliate_uuid===null,'conversion without referred affiliate stays registered and unvalued');
    $b=login(false);$bCookies=$cookies;
    $bEvent=[...$base,'event_id'=>'economic:b','com_reference'=>['type'=>'order','id'=>'economic-order-b'],'referral'=>['club_referral_code'=>$b->referral_code]];
    check(send($bEvent)->getStatusCode()===201,'second affiliate conversion recorded');
    check(payload(web('GET','/afiliados'))['props']['economics']['pending']===1,'second affiliate economic summary scoped to own code and UUID');
    $admin=login(true);$adminCookies=$cookies;
    check(web('GET','/admin/reglas-comisiones')->getStatusCode()===200 && web('GET','/admin/comisiones')->getStatusCode()===200,'activated verified admin can view rules and ledger');
    $emptyRules=payload(web('GET','/admin/reglas-comisiones'));
    check(reviewEntry($entry,'approve')->isRedirect() && ledger('economic-order-a')->status==='candidate','approval without rule and reconciliation blocked');
    check(DB::table('commission_audit')->where('action','approve')->count()===0,'rejected approval produces no financial approval');
    foreach (['0','0.0000','-1','1.00001','1e2',0.1,'1000000000000.00'] as $amount) {
        postAdmin('/admin/reglas-comisiones',['partner_id'=>'fixture-partner','event_type'=>'order.confirmed','fixed_amount'=>$amount,'currency'=>'BOB','effective_at'=>'2026-10-01T00:00:00Z']);
    }
    check(DB::table('commission_rules')->count()===0,'zero negatives excess precision exponent numeric floats and oversized amounts rejected');
    $rule=createRule();
    check($rule->status==='draft' && $rule->fixed_amount==='0.1001','rule creation preserves exact decimal as draft');
    reviewEntry(ledger('economic-order-a'),'evaluate');
    check(ledger('economic-order-a')->rule_id===null,'draft rule cannot value candidates');
    activateRule($rule);
    reviewEntry(ledger('economic-order-a'),'evaluate');$entry=ledger('economic-order-a');
    check($entry->amount==='0.1001' && $entry->currency==='BOB' && $entry->rule_id===$rule->id,'explicit evaluation applies active fixed rule without float');
    check($entry->commission_event_id===$entry->original_event_id && $entry->latest_event_id!==$entry->original_event_id,'remunerable original event retained despite later non-remunerable state event');
    check($entry->status==='candidate' && $entry->reconciliation==='pending' && $entry->approved_at===null,'active rule never auto-approves an unreconciled commission');
    $beforeAudit=DB::table('commission_audit')->count();
    reviewEntry($entry,'approve');
    check(ledger('economic-order-a')->status==='candidate' && DB::table('commission_audit')->count()===$beforeAudit,'valued commission without exclusive evidence cannot be approved');
    $rule2=createRule(['fixed_amount'=>'0.2002','effective_at'=>'2026-10-20T00:00:00Z']);activateRule($rule2);
    reviewEntry(ledger('economic-order-a'),'evaluate');
    check(ledger('economic-order-a')->amount==='0.1001' && ledger('economic-order-a')->rule_id===$rule->id,'new economic versions never rewrite existing valuation snapshots');
    activateRule($rule,'inactive');
    reviewEntry(ledger('economic-order-a'),'reconcile',['reconciliation'=>'club_exclusive','external_reference'=>'fixture-COM-exclusive-a']);
    reviewEntry(ledger('economic-order-a'),'approve');
    check(ledger('economic-order-a')->status==='candidate','inactive snapshotted rule blocks approval without repricing');
    activateRule($rule);
    $entry=ledger('economic-order-a');
    $race=concurrent([['approve',(string)$entry->id,(string)$entry->version,(string)$admin->id],['approve',(string)$entry->id,(string)$entry->version,(string)$admin->id]]);
    check($race===['approved','blocked'],'concurrent manual approvals produce exactly one financial decision');
    $entry=ledger('economic-order-a');
    check($entry->status==='approved' && $entry->approved_by===$admin->uuid && $entry->external_reference==='fixture-COM-exclusive-a','approved commission records actor exclusive evidence and reference');
    check(DB::table('commission_audit')->where('ledger_id',$entry->id)->where('action','approve')->count()===1,'single approval is durably audited');
    reviewEntry(ledger('economic-order-b'),'evaluate');$be=ledger('economic-order-b');
    check($be->amount==='0.2002' && $be->rule_id===$rule2->id,'new unvalued candidate uses latest effective active rule');
    reviewEntry($be,'reconcile',['reconciliation'=>'remunerated_by_com','external_reference'=>'fixture-COM-legacy-commission']);
    $be=ledger('economic-order-b');
    check($be->status==='discarded' && $be->reconciliation==='remunerated_by_com','historical COM remuneration permanently blocks CLUB obligation');
    reviewEntry($be,'approve');
    check(ledger('economic-order-b')->status==='discarded','COM-paid commission cannot be reopened or approved');
    $concurrentEvent=[...$base,'com_reference'=>['type'=>'order','id'=>'economic-concurrent']];$encoded=base64_encode(json_encode($concurrentEvent));
    check(concurrent([['send',$encoded,'economic:race:1'],['send',$encoded,'economic:race:2']])===['201','201'],'concurrent distinct event IDs for one operation are accepted');
    check(DB::table('commission_ledger')->where('com_reference_id','economic-concurrent')->count()===1,'database uniqueness and operation lock prevent duplicate commissions under concurrency');
    $ce=ledger('economic-concurrent');
    check($ce->amount==='0.2002' && $ce->status==='candidate' && $ce->reconciliation==='pending','concurrent candidate still requires manual evaluation and reconciliation');
    // Currency totals are PostgreSQL numeric strings, never JS/PHP floating point.
    $usdRule=createRule(['event_type'=>'redemption.confirmed','fixed_amount'=>'123456789012.3456','currency'=>'USD']);activateRule($usdRule);
    check(send([...$base,'event_id'=>'economic:usd','event_type'=>'redemption.confirmed','com_reference'=>['type'=>'redemption','id'=>'economic-usd']])->getStatusCode()===201,'second currency with a large exact amount accepted');
    check(ledger('economic-usd')->amount==='123456789012.3456','large decimal amount survives database and JSON without rounding');
    $future=createRule(['event_type'=>'future.confirmed','effective_at'=>'2026-11-01T00:00:00Z']);activateRule($future);
    send([...$base,'event_id'=>'economic:future','event_type'=>'future.confirmed','com_reference'=>['type'=>'order','id'=>'economic-future']]);
    check(ledger('economic-future')->rule_id===null,'rule entry date is evaluated against original COM business time');
    send([...$base,'event_id'=>'economic:sum','com_reference'=>['type'=>'order','id'=>'economic-sum']]);
    check(ledger('economic-sum')->amount==='0.2002','additional valued candidate retains fractional precision');
    $cookies=$aCookies;
    $affiliate=payload(web('GET','/afiliados'));$economics=$affiliate['props']['economics'];
    check($economics['pending']===4 && $economics['approved']===1,'affiliate sees own pending and approved counts only');
    $byCurrency=collect($economics['currencies'])->keyBy('currency');
    check($byCurrency['BOB']['approved']==='0.1001' && $byCurrency['BOB']['pending']==='0.4004' && $byCurrency['USD']['pending']==='123456789012.3456','totals are exact and separated by currency');
    check(!str_contains(json_encode($affiliate),'fixture-COM-legacy-commission') && !str_contains(json_encode($affiliate),$b->referral_code),'affiliate response exposes no other affiliate or reconciliation evidence');
    check(count($affiliate['props']['commission_entries']['data'])===5 && !str_contains(json_encode($affiliate['props']['commission_entries']),'economic-order-b'),'affiliate commission detail contains only own operation references');
    $cookies=$bCookies;
    check(payload(web('GET','/afiliados'))['props']['economics']['approved']===0 && payload(web('GET','/afiliados'))['props']['economics']['pending']===0,'discarded other-affiliate operation contributes no pending or approved amount');
    $cookies=$adminCookies;
    $stale=ledger('economic-concurrent');
    reviewEntry($stale,'reconcile',['reconciliation'=>'club_exclusive','external_reference'=>'fixture-exclusive-race']);
    reviewEntry($stale,'discard');
    check(ledger('economic-concurrent')->status==='candidate','stale admin review cannot overwrite a newer reconciliation');
    $rulesFixture=payload(web('GET','/admin/reglas-comisiones'));
    $ledgerFixture=payload(web('GET','/admin/comisiones'));
    check(count($ledgerFixture['props']['entries']['data'][0]['history'])>=2,'administration exposes immutable history and original evidence references');
    check(web('GET','/admin/comisiones?reconciliation=pending')->getStatusCode()===200,'administrative pending COM reconciliation filter works');
    check(send([...$base,'event_id'=>'economic:a:cancel','occurred_at'=>'2026-10-29T15:00:00Z','conversion_status'=>'cancelled'])->getStatusCode()===201,'subsequent COM cancellation recorded');
    $entry=ledger('economic-order-a');
    check($entry->status==='approved' && $entry->review_required && !$entry->eligible && $entry->amount==='0.1001','cancellation flags approved commission for review without rewriting history');
    $cookies=$aCookies;$summary=payload(web('GET','/afiliados'))['props']['economics'];$currencies=collect($summary['currencies'])->keyBy('currency');
    check($currencies['BOB']['approved']==='0' && $currencies['BOB']['review']==='0.1001','questioned approvals excluded from reconciled approved totals');
    $cookies=$adminCookies;
    $auditBefore=DB::table('commission_audit')->count();
    Artisan::call('commissions:sync');Artisan::call('commissions:sync');
    check(DB::table('commission_audit')->count()===$auditBefore,'repeated recovery sync is idempotent and creates no duplicate audit decisions');
    expectDatabaseBlock(fn()=>DB::table('commission_rules')->where('id',$rule->id)->update(['fixed_amount'=>'9.9999']),'database prohibits rewriting rule terms');
    expectDatabaseBlock(fn()=>DB::table('commission_ledger')->where('id',$entry->id)->update(['amount'=>'9.9999']),'database prohibits rewriting economic snapshot amounts');
    expectDatabaseBlock(fn()=>DB::table('commission_ledger')->where('id',$entry->id)->update(['affiliate_uuid'=>$b->uuid]),'database prohibits transferring economic ownership');
    expectDatabaseBlock(fn()=>DB::table('commission_audit')->delete(),'audit cannot be deleted');
    expectDatabaseBlock(fn()=>DB::table('commission_audit')->update(['reason'=>'changed']),'audit cannot be rewritten');
    expectDatabaseBlock(fn()=>DB::table('commission_ledger')->where('id',$entry->id)->delete(),'economic ledger cannot be deleted');
    $app->instance('env','production');
    check(postAdmin('/admin/reglas-comisiones',[])->getStatusCode()===419 && postAdmin('/admin/comisiones/'.$entry->id.'/revision',[])->getStatusCode()===419,'economic writes require real CSRF outside testing bypass');
    $app->instance('env','testing');
    $admin->forceFill(['activated_at'=>null])->save();
    check(web('GET','/admin/comisiones')->getStatusCode()===403 && postAdmin('/admin/reglas-comisiones',[])->getStatusCode()===403,'inactive administrator economic access denied');
    $admin->forceFill(['activated_at'=>now(),'email_verified_at'=>null])->save();
    check(web('GET','/admin/reglas-comisiones')->isRedirect(),'unverified administrator economic access denied');
    check(DB::table('jobs')->count()===0 && Http::recorded()->count()===0 && Notification::sentNotifications()===[],'engine sends no CRM jobs notifications HTTP requests or payments');
    file_put_contents('/evidence/commission-browser-fixtures.json',json_encode(['rules'=>$rulesFixture,'empty_rules'=>$emptyRules,'ledger'=>$ledgerFixture,'affiliate'=>$affiliate],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    $result=['passed'=>count($passed),'postgresql'=>'isolated schema','live_events_created'=>0,'live_users_created'=>0,'active_live_rules_created'=>0,'automatic_approvals'=>0,'payments'=>0];
    file_put_contents('/evidence/tests.json',json_encode($result,JSON_PRETTY_PRINT).PHP_EOL);echo json_encode($result).PHP_EOL;
} catch(Throwable $e) {
    fwrite(STDERR,get_class($e).' '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine().PHP_EOL);$failed=true;
} finally {
    $app->instance('env','testing');
    DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');unlink('/tmp/commercial-test-secret');
}
exit(isset($failed)?1:0);
