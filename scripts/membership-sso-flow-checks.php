<?php
// Runs within sso-tests.php, after native Phase 03 consent in the disposable COM runtime.
function locationOf(array $response): string {
    foreach ($response[2] as $line) if (stripos($line,'Location:') === 0) return trim(substr($line,9));
    return '';
}
function startSso(): array {
    freshBrowser(); app('cache')->flush();
    $response = http('GET','/auth/jakawi');
    check($response->isRedirect() && str_starts_with($response->headers->get('Location',''),'https://jakawi.com/oauth/authorize?'),'CLUB redirects to the deployed COM authorize endpoint');
    parse_str(parse_url($response->headers->get('Location'),PHP_URL_QUERY),$query);
    return [$response,$query];
}
[$start,$query]=startSso();
$pending=json_decode(Illuminate\Support\Facades\Crypt::decryptString(app('session')->get('jakawi_oidc')),true);
$verifier=$pending['values']['openid_connect_code_verifier'];
check($query['redirect_uri']===App\Services\Identity\JakawiOidc::CALLBACK && $query['code_challenge_method']==='S256'
    && $query['code_challenge']===rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'exact registered callback and PKCE S256 match the private verifier');
check(strlen($query['state'])>=64 && strlen($query['nonce'])>=64 && $query['nonce']!==$query['state']
    && !str_contains($start->headers->get('Location'),$verifier) && !str_contains($start->headers->get('Location'),config('oidc.client_secret')),'state and nonce have 256 bits; browser receives neither verifier nor secret');
$oldSession=app('session')->getId();
$auth=comRequest('GET','/oauth/authorize?'.http_build_query($query),'',['Accept'=>'text/html'],$cookies);
$callback=locationOf($auth);
check($auth[0]===302 && str_starts_with($callback,App\Services\Identity\JakawiOidc::CALLBACK.'?'),'existing real COM session issues a code without another password');
$beforeSso=$existing->fresh()->getRawOriginal();
$done=http('GET',substr($callback,strlen('https://jakawi.club')));
check($done->isRedirect('https://jakawi.club/dashboard'),'real COM code and ID token authenticate CLUB through its HTTP callback');
check(app('session')->getId()!==$oldSession && !DB::table('sessions')->where('id',$oldSession)->exists(),'successful SSO destroys the previous CLUB session id');
check(payload(http('GET','/dashboard',[],true))['props']['can_manage_landings']===true
    && http('GET','/admin/landings',[],true)->getStatusCode()===200,'SSO administrator reaches dashboard and administrative panel');
check($beforeSso===$existing->fresh()->getRawOriginal(),'real SSO preserves all account columns, credentials, role and attribution');
check(!app('session')->has('jakawi_oidc') && !str_contains(json_encode(app('session')->all()),'id_token'),'callback consumes transaction and never stores provider tokens');
check(str_contains($done->headers->get('Cache-Control',''),'no-store') && $done->headers->get('Referrer-Policy')==='no-referrer','callback is non-cacheable and does not leak referrer');
$tokenRequests=Illuminate\Support\Facades\Http::recorded(fn($r)=>$r->url()==='https://jakawi.com/oauth/token');
check($tokenRequests->count()===1 && $tokenRequests->first()[0]->hasHeader('Authorization','Basic '.base64_encode(config('oidc.client_id').':'.config('oidc.client_secret')))
    && !$tokenRequests->first()[0]->hasHeader('Cookie'),'real token exchange uses confidential Basic and no browser cookies');
http('POST','/logout');
check(http('GET','/dashboard')->getStatusCode()===401,'CLUB logout closes only the local session');
$replay=http('GET',substr($callback,strlen('https://jakawi.club')));
check($replay->isRedirect('https://jakawi.club/login') && Illuminate\Support\Facades\Http::recorded(fn($r)=>$r->url()==='https://jakawi.com/oauth/token')->count()===1,'consumed callback cannot exchange again or reopen CLUB');
[$second,$secondQuery]=startSso();
$stillCom=comRequest('GET','/oauth/authorize?'.http_build_query($secondQuery),'',['Accept'=>'text/html'],$cookies);
check($stillCom[0]===302 && str_starts_with(locationOf($stillCom),App\Services\Identity\JakawiOidc::CALLBACK.'?'),'COM session survives CLUB logout and independently authorizes a new flow');
$guestCookies=[];
$noCom=comRequest('GET','/oauth/authorize?'.http_build_query($secondQuery),'',['Accept'=>'text/html'],$guestCookies);
check($noCom[0]===302 && str_ends_with(locationOf($noCom),'/login'),'without COM session the same flow requires native COM login');
$afterLogout=$existing->fresh()->getRawOriginal();
$originalAccount=$beforeSso;unset($afterLogout['remember_token'],$originalAccount['remember_token']);
check($afterLogout===$originalAccount,'native CLUB logout rotates only its existing remember token');
check(http('POST','/login',['email'=>$existing->email,'password'=>'FixtureClubPassword123'])->isRedirect(),'local CLUB password remains a working alternative');
// Real COM authorization and checkout, exclusively disposable runtime fixtures.
$membershipStart=http('POST','/cuenta/membresia');
check($membershipStart->isRedirect() && str_starts_with($membershipStart->headers->get('Location',''),'https://jakawi.com/oauth/authorize?'),'membership action verifies linked identity and starts OIDC for COM session');
$membershipAuthorize=$membershipStart->headers->get('Location');
$membershipGuestCookies=[];
$requiresCom=comRequest('GET',substr($membershipAuthorize,strlen('https://jakawi.com')),'',['Accept'=>'text/html'],$membershipGuestCookies);
check($requiresCom[0]===302 && str_ends_with(locationOf($requiresCom),'/login'),'CLUB session alone cannot establish a COM session');
$membershipAuth=comRequest('GET',substr($membershipAuthorize,strlen('https://jakawi.com')),'',['Accept'=>'text/html'],$cookies);
$membershipCallback=locationOf($membershipAuth);
check($membershipAuth[0]===302 && str_starts_with($membershipCallback,App\Services\Identity\JakawiOidc::CALLBACK.'?'),'existing COM session returns real authorization code for membership');
check(http('GET',substr($membershipCallback,strlen('https://jakawi.club')))->isRedirect('https://jakawi.com/membresia/checkout'),'verified exact global subject continues to unique COM checkout');
[$checkoutCode,$checkoutHtml]=comRequest('GET','/membresia/checkout','',['Accept'=>'text/html'],$cookies);
check(comRequest('GET','/membresia/checkout','',['Accept'=>'text/html'],$cookies)[0]===200,'reloading isolated checkout reuses existing intent');
check($checkoutCode===200 && str_contains($checkoutHtml,'100') && !str_contains($checkoutHtml,'type="file"'),'real COM checkout starts in isolation with QR payment blocked');
check(http('GET',substr($membershipCallback,strlen('https://jakawi.club')))->isRedirect('https://jakawi.club/login'),'membership callback is consumed once');
$beforeAdverse=$existing->fresh()->getRawOriginal();

// Signed adverse responses exercise the actual library verifier and CLUB HTTP kernel.
$rsa=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
$rsaDetails=openssl_pkey_get_details($rsa);
function url64(string $value):string{return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
$testJwks=['keys'=>[['kty'=>'RSA','use'=>'sig','alg'=>'RS256','kid'=>'test-key','n'=>url64($rsaDetails['rsa']['n']),'e'=>url64($rsaDetails['rsa']['e'])]]];
function signedFixture(array $claims,array $header=[]):string {
    global $rsa;
    $body=url64(json_encode(array_merge(['alg'=>'RS256','typ'=>'JWT','kid'=>'test-key'],$header))).'.'.url64(json_encode($claims));
    openssl_sign($body,$signature,$rsa,OPENSSL_ALGO_SHA256);
    return $body.'.'.url64($signature);
}
function mockOidc(callable $mutate,array $header=[],bool $badSignature=false,?string $subject=null,?string $linkStatus='LINKED'):void {
    global $testJwks,$existing;
    Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory);
    Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Http::fake(function($request)use($mutate,$header,$badSignature,$subject,$linkStatus){
        global $testJwks,$existing;
        if(str_ends_with($request->url(),'/oauth/jwks'))return Illuminate\Support\Facades\Http::response($testJwks);
        if(str_contains($request->url(),'/internal/identity/'))return Illuminate\Support\Facades\Http::response([
            'source_app'=>'club','club_user_id'=>$existing->uuid,'global_subject_id'=>$existing->global_subject_id,
            'issuer'=>'https://jakawi.com','status'=>$linkStatus,'method'=>'COM_AUTHENTICATED_CONSENT',
            'authorized_at'=>now()->subDay()->toIso8601String(),'revoked_at'=>$linkStatus==='REVOKED'?now()->toIso8601String():null]);
        if(!str_ends_with($request->url(),'/oauth/token'))throw new RuntimeException('Unexpected test target');
        // The controller consumed the session; the test retains only the fixture nonce.
        $claims=['iss'=>'https://jakawi.com','sub'=>$subject??$existing->global_subject_id,'aud'=>config('oidc.client_id'),
            'iat'=>time(),'exp'=>time()+3600,'nonce'=>$GLOBALS['fixtureNonce'],'email'=>$existing->email,'is_marketing_admin'=>true];
        $jwt=signedFixture($mutate($claims),$header);
        if($badSignature)$jwt=substr($jwt,0,-10).'AAAAAAAAAA';
        return Illuminate\Support\Facades\Http::response(['token_type'=>'Bearer','expires_in'=>300,'access_token'=>'fixture-access-token','id_token'=>$jwt]);
    });
}
function mockedCallback(array $mutate=[]):\Symfony\Component\HttpFoundation\Response {
    [, $q]=startSso();$GLOBALS['fixtureNonce']=$q['nonce'];
    return http('GET','/auth/jakawi/callback?'.http_build_query(array_merge(['code'=>'fixture-code','state'=>$q['state']],$mutate)));
}
$existing->refresh();
mockOidc(fn($c)=>$c,[],false,(string)Illuminate\Support\Str::uuid());
$wrongStart=http('POST','/cuenta/membresia');
parse_str(parse_url($wrongStart->headers->get('Location'),PHP_URL_QUERY),$wrongQuery);
$GLOBALS['fixtureNonce']=$wrongQuery['nonce'];
check(http('GET','/auth/jakawi/callback?'.http_build_query(['code'=>'fixture-code','state'=>$wrongQuery['state']]))->isRedirect('https://jakawi.club/dashboard'),'different COM subject cannot continue to checkout or switch CLUB identity');
check(Auth::id()===$existing->id,'wrong COM session preserves authenticated CLUB user');
$cases=[
    'wrong issuer'=>fn($c)=>array_replace($c,['iss'=>'https://evil.example']),
    'wrong audience'=>fn($c)=>array_replace($c,['aud'=>'different-client']),
    'expired token'=>fn($c)=>array_replace($c,['exp'=>time()-1]),
    'future issuance'=>fn($c)=>array_replace($c,['iat'=>time()+100]),
    'missing expiry'=>function($c){unset($c['exp']);return $c;},
    'missing issuance'=>function($c){unset($c['iat']);return $c;},
    'missing nonce'=>function($c){unset($c['nonce']);return $c;},
    'wrong nonce'=>fn($c)=>array_replace($c,['nonce'=>'wrong']),
    'missing subject'=>function($c){unset($c['sub']);return $c;},
    'non UUID subject'=>fn($c)=>array_replace($c,['sub'=>'123']),
    'future not before'=>fn($c)=>array_replace($c,['nbf'=>time()+100]),
    'unauthorized party'=>fn($c)=>array_replace($c,['azp'=>'different-client']),
    'multiple audiences without authorized party'=>fn($c)=>array_replace($c,['aud'=>[config('oidc.client_id'),'other']]),
];
foreach($cases as $label=>$change){
    mockOidc($change);$response=mockedCallback();
    check($response->isRedirect('https://jakawi.club/login') && http('GET','/dashboard')->getStatusCode()===401,'reject '.$label.' without a CLUB session');
}
foreach(['none','HS256','RS512'] as $algorithm){
    mockOidc(fn($c)=>$c,['alg'=>$algorithm]);$response=mockedCallback();
    check($response->isRedirect('https://jakawi.club/login'),'reject unexpected algorithm '.$algorithm);
}
foreach([['kid'=>'unknown'],['jwk'=>$testJwks['keys'][0]],['jku'=>'https://evil.example/key'],['crit'=>['custom']]] as $header){
    mockOidc(fn($c)=>$c,$header);$response=mockedCallback();
    check($response->isRedirect('https://jakawi.club/login'),'reject untrusted JWT header '.array_key_first($header));
}
mockOidc(fn($c)=>$c,[],true);check(mockedCallback()->isRedirect('https://jakawi.club/login'),'reject modified signature');
foreach([['state'=>'wrong'],['state'=>null],['error'=>'access_denied'],['code'=>null],['state'=>['invalid']]] as $parameters){
    mockOidc(fn($c)=>$c);$response=mockedCallback($parameters);
    check($response->isRedirect('https://jakawi.club/login') && Illuminate\Support\Facades\Http::recorded()->isEmpty(),'invalid state/error/code stops before any token request');
}
mockOidc(fn($c)=>$c);[, $q]=startSso();
$expired=json_decode(Illuminate\Support\Facades\Crypt::decryptString(app('session')->get('jakawi_oidc')),true);$expired['expires_at']=time()-1;
app('session')->put('jakawi_oidc',Illuminate\Support\Facades\Crypt::encryptString(json_encode($expired)));app('session')->save();
check(http('GET','/auth/jakawi/callback?'.http_build_query(['code'=>'fixture','state'=>$q['state']]))->isRedirect('https://jakawi.club/login')
    && Illuminate\Support\Facades\Http::recorded()->isEmpty(),'expired transaction stops before token exchange');
$beforeUsers=DB::table('users')->count();$beforeHandoffs=DB::table('club_identity_handoffs')->count();
mockOidc(fn($c)=>$c,[],false,(string)Illuminate\Support\Str::uuid());$response=mockedCallback();
check($response->isRedirect('https://jakawi.club/login') && app('session')->get('jakawi_link_required')===true
    && http('GET','/dashboard')->getStatusCode()===401,'unknown subject with matching email enters explicit Phase 03 linking and remains unauthenticated');
check(DB::table('users')->count()===$beforeUsers && DB::table('club_identity_handoffs')->count()===$beforeHandoffs,'unknown subject creates no account or handoff and cannot grant roles');
mockOidc(fn($c)=>$c,[],false,null,'REVOKED');
check(mockedCallback()->isRedirect('https://jakawi.club/login') && http('GET','/dashboard')->getStatusCode()===401,'revoked Phase 03 link cannot authenticate even with a valid signed ID token');
// Remote availability errors fail closed; the local login remains available.
mockOidc(fn($c)=>$c);
Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory);
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::fake(['https://jakawi.com/oauth/token'=>Illuminate\Support\Facades\Http::response([],503)]);
$outage=mockedCallback();check($outage->isRedirect('https://jakawi.club/login'),'provider outage returns safely to local login (HTTP '.$outage->getStatusCode().', destination '.parse_url($outage->headers->get('Location',''),PHP_URL_PATH).')');
check($beforeAdverse===$existing->fresh()->getRawOriginal(),'all adverse SSO attempts preserve administrator credentials and permissions');

$adminFixture=$existing;$existing=$new->fresh();
$beforeMember=$new->fresh()->getRawOriginal();
mockOidc(fn($c)=>array_replace($c,['email'=>'different@example.test','name'=>'Untrusted name','is_marketing_admin'=>true]));
check(mockedCallback()->isRedirect('https://jakawi.club/dashboard') && !payload(http('GET','/dashboard',[],true))['props']['can_manage_landings'] && http('GET','/admin/landings',[],true)->getStatusCode()===403,'signed profile/admin claims cannot grant administrative access to an existing member');
check($beforeMember===$new->fresh()->getRawOriginal(),'SSO resolves by subject even when email differs and does not overwrite local profile');
$existing=$adminFixture;
