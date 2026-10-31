<?php
// Included only by the bounded isolated-schema runner; providers and bank data are fakes.
use App\Models\{AdEvent, AdVisit, MembershipPurchase};
use App\Services\Advertising;
use App\Jobs\DeliverAdEvent;
use Illuminate\Support\Facades\{Http, DB, Auth};
$configured = ['ads.privacy_ready'=>true, 'ads.meta.enabled'=>true, 'ads.meta.pixel_id'=>'123456789', 'ads.meta.token'=>'test-meta',
    'ads.tiktok.enabled'=>true, 'ads.tiktok.pixel_id'=>'TESTTIKTOK', 'ads.tiktok.token'=>'test-tiktok'];
config($configured);
$cookies=[];Auth::forgetGuards();app('session')->flush();app('session')->regenerate();
$adsCalls=[];$adsFailure=false;$approvedState=null;
Http::swap(new Illuminate\Http\Client\Factory);
Http::preventStrayRequests();
Http::fake(function (Illuminate\Http\Client\Request $request) use (&$adsCalls,&$adsFailure,&$approvedState) {
    if (str_starts_with($request->url(),'https://jakawi.com/')) {
        if ($approvedState) return Http::response($approvedState);
        $input=$request->data();
        return Http::response(['contract'=>'jakawi-club-purchases-v1','club_reference'=>$input['club_reference']??'',
            'payment_reference'=>(string)Illuminate\Support\Str::uuid(),'amount'=>'100.00','currency'=>'BOB','status'=>'awaiting_payment','identity_status'=>'pending','qr_ready'=>true]);
    }
    $adsCalls[]=['url'=>$request->url(),'body'=>json_decode(json_encode($request->data()),true)];
    if($adsFailure)return Http::response(['error'=>['code'=>2]],503);
    return Http::response(str_contains($request->url(),'facebook')?['events_received'=>1]:['code'=>0]);
});
$organic=payload(web('GET','/membresia?utm_source=meta'));
check(!$organic['props']['ads']['allowed'] && !$organic['props']['ads']['provider'] && AdVisit::count()===0,'UTMs on organic neither install pixels nor create Ads consent');
$noConsent=web('GET','/ads/meta/membresia?fbclid=abc123&utm_campaign=launch');
check($noConsent->getStatusCode()===200 && !payload($noConsent)['props']['ads']['allowed'] && AdVisit::count()===0,'Ads entry without consent persists no advertising identifiers');
check(payload($noConsent)['props']['landing']['social']['robots']==='noindex, nofollow' && str_ends_with(payload($noConsent)['props']['landing']['social']['canonical'],'/membresia'),'Ads use organic CMS with noindex and organic canonical');
web('POST','/preferencias',['allow_ads'=>true]);
$meta=payload(web('GET','/ads/meta/membresia?fbclid=abc123&utm_campaign=launch&ref='.$ref->referral_code));
check($meta['props']['ads']['allowed'] && $meta['props']['ads']['provider']==='meta' && $meta['props']['ads']['pixel_id']==='123456789','Meta consent enables exclusively Meta');
$visit=AdVisit::latest('consented_at')->first();
check($visit->context['fbclid']==='abc123' && $visit->context['utm_campaign']==='launch' && !str_contains(DB::table('ad_visits')->value('context'),'abc123'),'authorized click context encrypted at rest');
web('POST','/activar/compra',['name'=>'Ads buyer','email'=>'adsbuyer@example.test','phone'=>'+59176666666']);
$adPurchase=MembershipPurchase::where('ad_visit_id',$visit->id)->firstOrFail();
$adBuyer=App\Models\User::find($adPurchase->user_id);
check($adBuyer->referred_by_id===$ref->id && $adBuyer->registration_origin['landing_slug']==='membresia','Ads leaves affiliate and registration origin intact');
$adCheckout=payload(web('GET','/ads/meta/activar'));
check(count($adCheckout['props']['ads']['events'])===2 && AdEvent::where('club_reference',$adPurchase->id)->count()===2,'persisted buyer and new intention generate Lead and InitiateCheckout');
web('POST','/activar/compra');web('GET','/ads/meta/activar');
check(AdEvent::where('club_reference',$adPurchase->id)->count()===2,'reload and repeated submit reuse checkout event identity');
$lead=AdEvent::where('club_reference',$adPurchase->id)->where('event_name','Lead')->firstOrFail();
$adsFailure=true;(new DeliverAdEvent($lead->id));$retry=false;
try {(new DeliverAdEvent($lead->id))->handle();}catch(RuntimeException){$retry=true;}
check($retry && $lead->fresh()->status==='pending' && $lead->fresh()->attempts===1 && $adPurchase->fresh()->status==='awaiting_payment','API failure persists attempt and never blocks purchase');
$adsFailure=false;(new DeliverAdEvent($lead->id))->handle();$last=end($adsCalls)['body']['data'][0];
check($last['event_id']===$lead->id && $last['event_name']==='Lead' && $last['event_time']===$lead->occurred_at->timestamp && $last['user_data']['em'][0]===hash('sha256','adsbuyer@example.test'),'Meta CAPI shares original browser ID name time and hashed buyer');
$count=count($adsCalls);(new DeliverAdEvent($lead->id))->handle();check(count($adsCalls)===$count && $lead->fresh()->status==='accepted','accepted event cannot be delivered again');
$payment=$adPurchase->fresh()->payment_reference;
$activationAds=['schema_version'=>1,'event_id'=>'com:membership:'.$payment.':activated','event_type'=>'membership.activated',
 'occurred_at'=>now()->subSeconds(10)->toIso8601String(),'payment_reference'=>$payment,'club_reference'=>$adPurchase->id,'club_user_uuid'=>$adBuyer->uuid,
 'guest_purchase_id'=>null,'global_subject_id'=>null,'membership_reference'=>'ads-901','com_reference'=>['type'=>'membership','id'=>'ads-901'],'origin'=>'CLUB','identity_status'=>'pending'];
$adPurchase->update(['status'=>'under_review']);
check(AdEvent::where('event_name','Purchase')->count()===0,'receipt/review creates no Purchase');
check(send($activationAds)->getStatusCode()===201 && send($activationAds)->getStatusCode()===200,'signed activation and duplicate accepted idempotently');
$purchaseEvent=AdEvent::where('club_reference',$adPurchase->id)->where('event_name','Purchase')->firstOrFail();
$approvedState=['contract'=>'jakawi-club-purchases-v1','club_reference'=>$adPurchase->id,'payment_reference'=>$payment,'amount'=>'99.50','currency'=>'BOB','status'=>'approved'];
(new DeliverAdEvent($purchaseEvent->id))->handle();$last=end($adsCalls)['body']['data'][0];
check($last['custom_data']['value']===99.5 && $last['custom_data']['currency']==='BOB' && $last['custom_data']['order_id']===$adPurchase->id && $last['event_time']===Illuminate\Support\Carbon::parse($activationAds['occurred_at'])->timestamp,'Purchase uses approved COM amount reference currency and original activation time');
$linkedAds=$activationAds;unset($linkedAds['guest_purchase_id'],$linkedAds['com_reference'],$linkedAds['origin'],$linkedAds['identity_status']);
$linkedAds['event_type']='membership.identity_linked';$linkedAds['event_id']='com:membership:'.$payment.':identity-linked';$linkedAds['global_subject_id']=(string)Illuminate\Support\Str::uuid();
send($linkedAds);check(AdEvent::where('club_reference',$adPurchase->id)->where('event_name','Purchase')->count()===1,'identity link cannot generate a second Purchase');
$fixtures['ads_meta']=$meta;$fixtures['ads_checkout']=$adCheckout;
$pending=AdEvent::where('club_reference',$adPurchase->id)->where('event_name','InitiateCheckout')->first();
web('POST','/preferencias',['allow_ads'=>false]);$count=count($adsCalls);(new DeliverAdEvent($pending->id))->handle();
check(count($adsCalls)===$count && $pending->fresh()->status==='rejected' && !payload(web('GET','/ads/meta/membresia'))['props']['ads']['allowed'],'revocation disables browser and queued server delivery');
$fixtures['ads_rejected']=payload(web('GET','/ads/meta/membresia'));
$cookies=[];Auth::forgetGuards();app('session')->flush();app('session')->regenerate();$approvedState=null;
// Reset only the isolated array cache between synthetic buyer scenarios.
app('cache')->flush();
web('GET','/ads/tiktok/membresia');web('POST','/preferencias',['allow_ads'=>true]);
$tiktok=payload(web('GET','/ads/tiktok/membresia?ttclid=tiktokclick'));
check($tiktok['props']['ads']['allowed'] && $tiktok['props']['ads']['provider']==='tiktok' && $tiktok['props']['ads']['pixel_id']==='TESTTIKTOK','TikTok consent enables exclusively TikTok');
$guestResponse=web('POST','/activar/compra',['name'=>'Guest Ads','email'=>$adBuyer->email,'phone'=>'+59177777777']);
$guestAd=MembershipPurchase::whereNull('user_id')->whereNotNull('ad_visit_id')->firstOrFail();
$ttLead=AdEvent::where('club_reference',$guestAd->id)->where('event_name','CompleteRegistration')->firstOrFail();
(new DeliverAdEvent($ttLead->id))->handle();$last=end($adsCalls)['body']['data'][0];
check($last['event_id']===$ttLead->id && $last['event']==='CompleteRegistration' && $last['user']['phone']===hash('sha256','+59177777777') && $last['user']['ttclid']==='tiktokclick','TikTok Events API deduplicates browser ID and uses E164 hash and authorized click');
$guestActivation=$activationAds;$guestActivation['club_reference']=$guestAd->id;$guestActivation['club_user_uuid']=null;$guestActivation['guest_purchase_id']=$guestAd->intent['guest_purchase_id'];
$guestActivation['payment_reference']=$guestAd->fresh()->payment_reference;$guestActivation['event_id']='com:membership:'.$guestActivation['payment_reference'].':activated';
check(send($guestActivation)->getStatusCode()===201 && AdEvent::where('club_reference',$guestAd->id)->where('event_name','Purchase')->count()===1,'guest activation attributes by club_reference without email or identity link');
$fixtures['ads_tiktok']=$tiktok;$fixtures['ads_tiktok_checkout']=payload(web('GET','/ads/tiktok/activar'));
config(['ads.privacy_ready'=>false]);
$blocked=payload(web('GET','/ads/tiktok/membresia'));
check(!$blocked['props']['ads']['allowed'] && !$blocked['props']['ads']['pixel_id'],'privacy readiness pending disables browser');
$ttCheckout=AdEvent::where('club_reference',$guestAd->id)->where('event_name','InitiateCheckout')->first();$count=count($adsCalls);(new DeliverAdEvent($ttCheckout->id))->handle();
check(count($adsCalls)===$count,'privacy readiness pending prevents real API requests');
config($configured);$ttVisit=AdVisit::find($guestAd->ad_visit_id);$ttVisit->update(['expires_at'=>now()->subSecond()]);
(new DeliverAdEvent($ttCheckout->id))->handle();check($ttCheckout->fresh()->status==='rejected','expired attribution cannot deliver');
config(['ads.meta.token'=>null]);check(!app(Advertising::class)->ready('meta'),'missing credentials disables platform');

$additionalVisit=AdVisit::create(['id'=>(string)Illuminate\Support\Str::uuid(),'provider'=>'meta','context'=>['url'=>'https://jakawi.club/ads/meta/membresia'],
    'consent_scope'=>$ttVisit->consent_scope,'consented_at'=>now(),'expires_at'=>now()->addDay()]);
web('POST','/preferencias',['allow_ads'=>false]);
check((bool)$additionalVisit->fresh()->revoked_at && (bool)$ttVisit->fresh()->revoked_at,'one privacy withdrawal revokes all visits across providers in the same consent scope');
