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

try {
    DB::statement('CREATE SCHEMA '.$schema);
    config(['database.connections.pgsql.search_path'=>$schema,'commercial.secret_file'=>'/tmp/commercial-test-secret','identity.enabled'=>false,
        'purchases.secret'=>str_repeat('p',64), 'purchases.recipient'=>'Destinatario de prueba']);
    DB::purge('pgsql');
    check(DB::selectOne('select current_schema() as name')->name===$schema,'isolated schema before writes');
    Artisan::call('migrate',['--force'=>true]);
    app('router')->setCompiledRoutes(app('router')->getRoutes()->compile());
    app(Illuminate\Contracts\Debug\ExceptionHandler::class)->reportable(function(Throwable $e): void { fwrite(STDERR, get_class($e).' '.explode(' (SQL:', $e->getMessage())[0].PHP_EOL); });
    Notification::fake(); Http::preventStrayRequests();
    $remote=[]; $calls=[]; $nonces=[]; $failNext=false;
    Http::fake(function (Illuminate\Http\Client\Request $request) use (&$remote,&$calls,&$nonces,&$failNext) {
        $path=parse_url($request->url(),PHP_URL_PATH); $raw=$request->body();
        $header=fn(string $key): string => $request->header($key)[0] ?? '';
        $canonical=implode("\n",['jakawi-club-purchases-v1','club-purchases-v1',$header('X-Jakawi-Timestamp'),$header('X-Jakawi-Nonce'),$request->method(),$path,hash('sha256',$raw)]);
        if(!hash_equals(hash_hmac('sha256',$canonical,str_repeat('p',64)),$header('X-Jakawi-Signature')))throw new RuntimeException('Invalid outbound HMAC');
        if(isset($nonces[$header('X-Jakawi-Nonce')]))throw new RuntimeException('Nonce reused');
        $nonces[$header('X-Jakawi-Nonce')]=true; $calls[]=$request->method().' '.$path;
        if($failNext) { $failNext=false; return Http::response(['message'=>'Temporary unavailable'],503); }
        if($path==='/internal/club/purchases/qr')return Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j8e8AAAAASUVORK5CYII='),200,['Content-Type'=>'image/png']);
        if($path==='/internal/club/purchases') {
            $d=json_decode($raw,true); $id=$d['club_reference'];
            if(isset($remote[$id])) {if($remote[$id]['intent']!==$d)throw new RuntimeException('Intent changed');return Http::response($remote[$id]['state']);}
            $remote[$id]=['intent'=>$d,'state'=>['contract'=>'jakawi-club-purchases-v1','club_reference'=>$id,'payment_reference'=>(string)Illuminate\Support\Str::uuid(),
                'amount'=>'100.00','currency'=>'BOB','origin'=>'CLUB','club_user_uuid'=>$d['club_user_uuid'],'guest_purchase_id'=>$d['guest_purchase_id'],
                'status'=>'awaiting_payment','identity_status'=>'pending','global_subject_id'=>null,'membership_reference'=>null,'activated_at'=>null,'receipt_received'=>false,'receipt_sha256'=>null,'qr_ready'=>true]];
            return Http::response($remote[$id]['state'],201);
        }
        $parts=explode('/',trim($path,'/'));$id=$parts[3];
        if(($parts[4]??'')==='receipt') {
            $d=json_decode($raw,true);$bytes=base64_decode($d['content_base64'],true);
            if(hash('sha256',$bytes)!==$d['sha256'])throw new RuntimeException('Receipt signature mismatch');
            $remote[$id]['state']['status']='under_review';$remote[$id]['state']['receipt_received']=true;$remote[$id]['state']['receipt_sha256']=$d['sha256'];
        }
        return Http::response($remote[$id]['state']);
    });
    $home=DB::table('marketing_landings')->where('slug',config('jakawi.home_landing_slug'))->first();$homeJson=json_encode($home);
    $ref=User::create(['name'=>'Referrer','email'=>'ref@example.test']);
    $other=User::create(['name'=>'Other referrer','email'=>'other@example.test']);
    $sales=payload(web('GET','/membresia?ref='.$ref->referral_code));
    check($sales['component']==='MembershipSales' && $sales['props']['referral_code']===$ref->referral_code,'editable public sales and original referral');
    $fixtures=['sales'=>$sales,'empty'=>payload(web('GET','/activar?ref='.$other->referral_code))];
    check($fixtures['empty']['component']==='MembershipCheckout' && !$fixtures['empty']['props']['signed_in'],'public checkout without account or login');
    $r=web('POST','/activar/compra',['name'=>'Buyer new','email'=>'buyer@example.test','phone'=>'+59170000000']);
    if($r->getStatusCode()!==302) fwrite(STDERR, 'HTTP '.$r->getStatusCode().PHP_EOL);
    check($r->getStatusCode()===302 && str_ends_with($r->headers->get('Location'),'/activar'),'guest continues directly to QR');
    $buyer=User::where('email','buyer@example.test')->first(); $purchase=App\Models\MembershipPurchase::first();
    check($buyer && !$buyer->email_verified_at && !$buyer->password && !$buyer->activated_at && $buyer->referred_by_id===$ref->id,'automatic unverified account and immutable original lead referral');
    check(DB::table('jobs')->where('queue','crm')->count()===1,'new lead enqueued in existing CRM');
    check(DB::table('membership_purchases')->value('intent')!==json_encode($purchase->intent) && !str_contains(DB::table('membership_purchases')->value('intent'),'buyer@example.test'),'intent buyer data and claim encrypted at rest');
    $checkout=payload(web('GET','/activar'));$fixtures['qr']=$checkout;
    check(!$checkout['props']['signed_in'] && $checkout['props']['purchase']['qr_ready'] && !str_contains(json_encode($checkout),'claim_secret'),'QR without login email verification or secret exposure');
    web('POST','/activar/compra',['name'=>'Altered','email'=>'attacker@example.test']);
    check(App\Models\MembershipPurchase::count()===1 && User::count()===3,'repeat purchase preserves reference and immutable original payload');
    $qr=web('GET','/activar/qr?download=1',[],false);
    check($qr->getStatusCode()===200 && str_contains($qr->headers->get('Content-Disposition'),'attachment') && str_contains($qr->headers->get('Cache-Control'),'no-store'),'existing QR proxied download and never publicly cached');
    file_put_contents('/tmp/receipt.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j8e8AAAAASUVORK5CYII='));
    $upload=function () use (&$cookies): Symfony\Component\HttpFoundation\Response {
        Auth::forgetGuards();$file=new Illuminate\Http\UploadedFile('/tmp/receipt.png','comprobante.png','image/png',null,true);
        $r=kernelRequest(Request::create('https://jakawi.club/activar/comprobante','POST',[],$cookies,['receipt'=>$file],['HTTP_ACCEPT'=>'text/html','REMOTE_ADDR'=>'192.0.2.30']));
        foreach($r->headers->getCookies()as$c)$cookies[$c->getName()]=$c->getValue();return $r;
    };
    check($upload()->getStatusCode()===302,'one receipt sent through signed backend transfer');
    $review=payload(web('GET','/activar'));$fixtures['review']=$review;
    check($purchase->fresh()->status==='under_review' && !$purchase->fresh()->activated_at && $review['props']['purchase']['receipt_received'],'under_review after receipt, never prematurely activated');
    check(app(App\Services\AffiliateResults::class)->summary($ref)['memberships_activated']===0 && DB::table('commission_ledger')->count()===0,'no activation metric or commission on upload');
    $upload(); web('POST','/activar/compra');
    check(count(array_filter($calls,fn($v)=>str_ends_with($v,'/receipt')))===1 && App\Models\MembershipPurchase::count()===1,'review blocks duplicate intent and second receipt');
    $failNext=true; app(App\Services\PublicMembershipCheckout::class)->sync($purchase->fresh());
    check(!$failNext && $purchase->fresh()->status==='under_review' && App\Models\MembershipPurchase::count()===1,'transport retry uses fresh signed nonce and preserves intention');
    $late=$remote[$purchase->id]['state'];$late['status']='awaiting_payment';
    app(App\Services\PublicMembershipCheckout::class)->apply($purchase->fresh(),$late);
    check($purchase->fresh()->status==='under_review','late awaiting response cannot undo submitted receipt');
    $incomplete=$remote[$purchase->id]['state'];$incomplete['status']='approved';$blocked=false;
    try { app(App\Services\PublicMembershipCheckout::class)->apply($purchase->fresh(),$incomplete); } catch(RuntimeException) { $blocked=true; }
    check($blocked && !$purchase->fresh()->activated_at,'approval without confirmed activation cannot enable membership');
    $reference=$purchase->payment_reference;
    $activation=['schema_version'=>1,'event_id'=>'com:membership:'.$reference.':activated','event_type'=>'membership.activated',
        'occurred_at'=>now()->toIso8601String(),'payment_reference'=>$reference,'club_reference'=>$purchase->id,
        'club_user_uuid'=>$buyer->uuid,'guest_purchase_id'=>null,'global_subject_id'=>null,'membership_reference'=>'901',
        'com_reference'=>['type'=>'membership','id'=>'901'],'origin'=>'CLUB','identity_status'=>'pending'];
    $linked=['schema_version'=>1,'event_id'=>'com:membership:'.$reference.':identity-linked','event_type'=>'membership.identity_linked',
        'occurred_at'=>now()->toIso8601String(),'payment_reference'=>$reference,'club_reference'=>$purchase->id,'club_user_uuid'=>$buyer->uuid,
        'global_subject_id'=>(string)Illuminate\Support\Str::uuid(),'membership_reference'=>'901'];
    check(send($linked)->getStatusCode()===201 && !$purchase->fresh()->activated_at,'out of order identity event retained without early activation');
    check(send($activation)->getStatusCode()===201 && send($activation)->getStatusCode()===200,'confirmed activation accepted and deduplicated');
    check($purchase->fresh()->activated_at && $purchase->fresh()->identity_status==='linked' && $purchase->fresh()->global_subject_id===$linked['global_subject_id'],'late null subject does not degrade specific identity');
    $fixtures['active']=payload(web('GET','/activar'));
    check($fixtures['active']['props']['active'] && app(App\Services\AffiliateResults::class)->summary($ref)['memberships_activated']===1,'activation shown and counted only after COM confirmation');
    $conflict=$activation;$conflict['membership_reference']='902';$conflict['com_reference']['id']='902';
    check(send($conflict)->getStatusCode()===409,'conflicting duplicate event rejected');
    $guestCookies=$cookies; $cookies=[];Auth::forgetGuards();app('session')->flush();app('session')->regenerate();
    check(payload(web('GET','/activar'))['props']['purchase']===null && web('GET','/activar/qr',[],false)->getStatusCode()===404,'other session cannot access purchase or receipt via reference');
    check(web('POST','/activar/comprobante')->getStatusCode()===404,'other session cannot upload receipt for another purchase');
    $before=$buyer->fresh()->toArray();
    $guestResponse=web('POST','/activar/compra',['name'=>'Declared different','email'=>$buyer->email,'phone'=>'+59171111111']);
    $guest=App\Models\MembershipPurchase::whereNull('user_id')->first();
    if(!$guest || User::count()!==3 || $buyer->fresh()->toArray()!==$before) fwrite(STDERR,json_encode(['guest_http'=>$guestResponse->getStatusCode(),'guest_exists'=>(bool)$guest,'users'=>User::count(),'user_changed'=>$buyer->fresh()->toArray()!==$before]).PHP_EOL);
    check($guest && $guest->intent['club_user_uuid']===null && $guest->intent['guest_purchase_id'] && User::count()===3 && $buyer->fresh()->toArray()===$before,'existing email guest purchase neither takes account nor changes its data or referral');
    $fixtures['existing']=payload(web('GET','/activar'));
    $user=login(false);$user->phone='+59172222222';$user->save();
    $fixtures['signed']=payload(web('GET','/activar'));
    check($fixtures['signed']['props']['buyer']['email']===$user->email && $fixtures['signed']['props']['buyer']['phone']===$user->phone,'authenticated checkout uses actual profile data');
    web('POST','/activar/compra',['email'=>'ignored@example.test','name'=>'Ignored','phone'=>'+59173333333']);
    $own=App\Models\MembershipPurchase::where('user_id',$user->id)->first();
    check($own->intent['email']===$user->email && $own->intent['club_user_uuid']===$user->uuid,'authenticated intent cannot override identity');
    web('POST','/activar/compra');check(App\Models\MembershipPurchase::where('user_id',$user->id)->count()===1,'authenticated purchase idempotent');
    $own->update(['status'=>'under_review']);$remote[$own->id]['state']['status']='under_review';
    $fixtures['signed_review']=payload(web('GET','/activar'));
    check($fixtures['signed_review']['props']['purchase']['status']==='under_review','authenticated user resumes review');
    $own->update(['status'=>'approved','activated_at'=>now(),'membership_reference'=>'902']);
    $fixtures['signed_active']=payload(web('GET','/activar'));$count=App\Models\MembershipPurchase::count();web('POST','/activar/compra');
    check($fixtures['signed_active']['props']['active'] && App\Models\MembershipPurchase::count()===$count,'active member protected from second charge');
    check((int)DB::table('marketing_event_totals')->where('event','InitiateCheckout')->sum('total')===3,'InitiateCheckout once per stable intention without duplicate COM event');
    check(DB::table('commission_ledger')->count()===0,'membership events never create commission');
    check(json_encode(DB::table('marketing_landings')->where('id',$home->id)->first())===$homeJson,'main capture landing unchanged');
    $claimant=User::create(['name'=>'Existing claimant','email'=>'claimant@example.test']);
    $unverifiedRequest=Request::create('https://jakawi.club/activar');
    $isolatedSession=new Illuminate\Session\Store('isolated',new Illuminate\Session\ArraySessionHandler(120));$isolatedSession->start();
    $guestRequest=Request::create('https://jakawi.club/activar');$guestRequest->setLaravelSession($isolatedSession);
    $guestRequest->setUserResolver(fn()=>null);
    $claimPurchase=app(App\Services\PublicMembershipCheckout::class)->create($guestRequest,['name'=>'Declared claimant','email'=>$claimant->email,'phone'=>'+59173333333']);
    $cleanSession=new Illuminate\Session\Store('isolated2',new Illuminate\Session\ArraySessionHandler(120));$cleanSession->start();
    $unverifiedRequest->setLaravelSession($cleanSession);$unverifiedRequest->setUserResolver(fn()=>$claimant);
    check(app(App\Services\PublicMembershipCheckout::class)->current($unverifiedRequest)===null,'unverified account cannot recover guest purchase by email');
    $claimant->email_verified_at=now();$claimant->save();
    check(app(App\Services\PublicMembershipCheckout::class)->current($unverifiedRequest)?->id===$claimPurchase->id,'verified authenticated buyer can recover pending guest purchase across devices');
    $unverifiedRequest->setLaravelSession($isolatedSession);$unverifiedRequest->setUserResolver(fn()=>$user);
    check(app(App\Services\PublicMembershipCheckout::class)->current($unverifiedRequest)?->id===$own->id,'different logged-in account never adopts a guest session purchase');
    $testimonials=App\Support\LandingContent::defaults();$testimonials['testimonials']=[['name'=>'Fixture','quote'=>'Authorized only','authorized'=>false]];
    check(App\Support\LandingContent::validate($testimonials)['testimonials'][0]['authorized']===false,'CMS preserves explicit testimonial authorization without inferring consent');
    file_put_contents('/evidence/checkout-browser-fixtures.json',json_encode($fixtures,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    file_put_contents('/evidence/tests.json',json_encode(['passed'=>count($passed),'checks'=>$passed,'live_fixtures'=>0,'real_bank_payments'=>0,'outbound_hmac_verified'=>true],JSON_PRETTY_PRINT));
} catch(Throwable $e) { fwrite(STDERR,get_class($e).' '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine().PHP_EOL);$failed=true;
} finally { DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE'); @unlink('/tmp/commercial-test-secret'); }
exit(isset($failed)?1:0);
