<?php
// Extends the real COM/CLUB isolated SSO harness, never LIVE data.
use App\Models\User;
use App\Notifications\ActivateAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use App\Support\MembershipJourney;
use Illuminate\Support\Facades\Http;

function realComTransport(): void {
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(function ($r) {
        if (!str_starts_with($r->url(),'https://jakawi.com/')) throw new RuntimeException('Unexpected target');
        $headers=$r->headers();unset($headers['Host']);
        [$code,$body]=comRequest($r->method(),parse_url($r->url(),PHP_URL_PATH),$r->body(),$headers);
        return Http::response($body,$code,['Content-Type'=>'application/json']);
    });
}
realComTransport();
$existing->refresh();
freshBrowser();app('cache')->flush();
$initialUsers=User::count();
check(http('GET','/activar?next=https://example.test')->isRedirect('https://jakawi.club/login'),'public activation sends guests to existing login with no open redirect');
check(User::count()===$initialUsers && Http::recorded()->isEmpty(),'guest visit creates no account COM request or checkout');
$loginPage=payload(http('GET','/login',[],true));
check($loginPage['props']['membershipIntent']===true,'login exposes membership continuation and existing registration choice');
check(http('POST','/login',['email'=>$existing->email,'password'=>'incorrect'])->getStatusCode()===422 && User::count()===$initialUsers,'failed login does not create a lead or lose intent');
check(http('POST','/login',['email'=>$existing->email,'password'=>'FixtureClubPassword123'])->isRedirect('https://jakawi.club/activar'),'local login resumes fixed activation URL');
$start=http('GET','/activar');
check($start->isRedirect() && str_starts_with($start->headers->get('Location',''),'https://jakawi.com/oauth/authorize?'),'authenticated activation URL starts existing OIDC');
$authorized=comRequest('GET',substr($start->headers->get('Location'),strlen('https://jakawi.com')),'',['Accept'=>'text/html'],$cookies);
check(http('GET',substr(locationOf($authorized),strlen('https://jakawi.club')))->isRedirect('https://jakawi.com/membresia/checkout'),'public URL reaches only COM checkout after real signed identity verification');
check(!MembershipJourney::pending(request()),'successful checkout continuation consumes intent');
// COM SSO login also resumes the same fixed intent rather than losing it to dashboard.
freshBrowser();app('cache')->flush();http('GET','/activar');
$sso=http('GET','/auth/jakawi');
$authorized=comRequest('GET',substr($sso->headers->get('Location'),strlen('https://jakawi.com')),'',['Accept'=>'text/html'],$cookies);
check(http('GET',substr(locationOf($authorized),strlen('https://jakawi.club')))->isRedirect('https://jakawi.club/activar'),'native JAKAWI SSO login preserves activation destination');
// First stored referral wins over a later code. Registration/WhatsApp response unchanged.
freshBrowser();app('cache')->flush();
check(http('GET','/r/'.$existing->referral_code)->isRedirect(),'existing affiliate link captures original attribution');
http('GET','/activar?ref='.$new->referral_code);
check(http('POST','/registro',registration('activation-referred@example.test',['referral_code'=>$new->referral_code]))->isRedirect('https://jakawi.club/bienvenida'),'membership registration retains existing confirmation destination');
$lead=User::where('email','activation-referred@example.test')->firstOrFail();
check($lead->referred_by_id===$existing->id,'original affiliate survives activation visit and later referral parameter');
$notice=Notification::sent($lead,ActivateAccount::class)->first();
check($notice->membership && str_contains($notice->toMail($lead)->actionUrl,'continue=membership'),'email carries fixed continuation for another browser');
$confirmation=payload(http('GET','/bienvenida',[],true));
check($confirmation['component']==='Registered' && !array_key_exists('membershipIntent',$confirmation['props']),'confirmation WhatsApp page gains no membership CTA');
// A different email-browser session obtains intent from the account activation link.
freshBrowser();app('cache')->flush();
http('GET','/activar/'.$notice->token.'?'.http_build_query(['email'=>$lead->email,'continue'=>'membership']));
check(http('POST','/activar',['email'=>$lead->email,'token'=>$notice->token,'password'=>'FixtureClubPassword123','password_confirmation'=>'FixtureClubPassword123'])->isRedirect('https://jakawi.club/activar'),'account token activation in another browser resumes membership without changing POST contract');
$leadOrigin=$lead->fresh()->registration_origin;
check($lead->fresh()->global_subject_id===null,'CLUB account activation does not invent global identity');
$unlinkedStart=http('GET','/activar');
check(str_starts_with($unlinkedStart->headers->get('Location',''),'https://jakawi.com/oauth/authorize?') && Illuminate\Support\Str::isUuid($lead->fresh()->global_subject_id),'missing subject is linked by existing authority before OIDC checkout continuation');
check($lead->fresh()->referred_by_id===$existing->id && $lead->fresh()->registration_origin===$leadOrigin,'real provisioning preserves lead attribution and origin');
$unpaid=payload(http('GET','/dashboard',[],true));
// Organic path neither infers nor invents a referrer.
freshBrowser();app('cache')->flush();http('GET','/activar');
http('POST','/registro',registration('activation-organic@example.test'));
$organic=User::where('email','activation-organic@example.test')->firstOrFail();
check($organic->referred_by_id===null,'activation URL supports organic registration without invented affiliate');
// Persist the real HMAC event contract in the isolated schema and suppress repeat purchase.
freshBrowser();app('cache')->flush();
http('POST','/login',['email'=>$existing->email,'password'=>'FixtureClubPassword123']);
$raceStart=http('GET','/activar');
$raceAuth=comRequest('GET',substr($raceStart->headers->get('Location'),strlen('https://jakawi.com')),'',['Accept'=>'text/html'],$cookies);
$ledger=DB::table('commission_ledger')->orderBy('id')->get()->toJson();
$body=['schema_version'=>1,'event_id'=>'com:membership:activation-url:activated','event_type'=>'membership.activated','global_subject_id'=>$existing->global_subject_id,'membership_reference'=>'activation-url-501','com_reference'=>['type'=>'membership','id'=>'activation-url-501'],'payment_reference'=>'activation-url-payment','occurred_at'=>now()->subMinute()->toRfc3339String()];
$raw=json_encode($body);$time=time();$secret=str_repeat('c',64);file_put_contents('/tmp/activation-url-secret',$secret);
config(['commercial.secret_file'=>'/tmp/activation-url-secret']);
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
$request=Illuminate\Http\Request::create('https://jakawi.club/internal/commercial/events','POST',[],[],[],['HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json','HTTP_X_JAKAWI_COM_KEY_ID'=>'com-commercial-v1','HTTP_X_JAKAWI_COM_TIMESTAMP'=>(string)$time,'HTTP_X_JAKAWI_COM_SIGNATURE'=>'sha256='.hash_hmac('sha256',$time."\nPOST\n/internal/commercial/events\n".hash('sha256',$raw),$secret)],$raw);
$response=$kernel->handle($request);$kernel->terminate($request,$response);
check($response->getStatusCode()===201,'confirmed membership received through existing HMAC receptor');
check(http('GET',substr(locationOf($raceAuth),strlen('https://jakawi.club')))->isRedirect('https://jakawi.club/dashboard'),'activation received during OIDC suppresses checkout at callback too');
$beforeRequests=Http::recorded()->count();
check(http('GET','/activar')->isRedirect('https://jakawi.club/dashboard') && Http::recorded()->count()===$beforeRequests,'already activated member returns to dashboard without initiating checkout');
$paid=payload(http('GET','/dashboard',[],true));
check($paid['props']['membership_activated']===true && $unpaid['props']['membership_activated']===false,'dashboard reflects confirmed activation separately from free CLUB access');
check(DB::table('commission_ledger')->orderBy('id')->get()->toJson()===$ledger,'activation URL and signed membership create no commission');
file_put_contents('/evidence/activation-browser-fixtures.json',json_encode(['login'=>$loginPage,'unpaid'=>$unpaid,'paid'=>$paid],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
file_put_contents('/evidence/tests.json',json_encode(['passed'=>count($passed),'live_fixtures'=>0,'organic_registration'=>true,'original_attribution'=>true,'cross_browser_continuation'=>true,'active_member_no_checkout'=>true],JSON_PRETTY_PRINT));
unlink('/tmp/activation-url-secret');
