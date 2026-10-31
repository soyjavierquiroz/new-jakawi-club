<?php
// Real resolver, HTTP HMAC client and engine; only COM transport is replaced.
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

Carbon::setTestNow(now()->addSeconds(3));
$individual->refresh();
$first10g = true; $state10g = 'FREE'; $corrupt10g = null; $http10g = 200;
$requests10g = []; $nonces10g = [];
Http::swap(new Illuminate\Http\Client\Factory);
Http::preventStrayRequests();
config(['purchases.secret'=>str_repeat('h',64)]);
Http::fake(function ($request) use (&$first10g, &$state10g, &$corrupt10g, &$http10g, &$requests10g, &$nonces10g) {
    $path = '/internal/club/acquisitions/evidence';
    check($request->url() === 'https://jakawi.com'.$path, '10G exact endpoint');
    $header = fn($key) => $request->header($key)[0] ?? '';
    $nonce = $header('X-Jakawi-Nonce');
    check(!in_array($nonce,$nonces10g,true), '10G fresh nonce each attempt'); $nonces10g[]=$nonce;
    $canonical=implode("\n",['jakawi-club-purchases-v1','club-purchases-v1',$header('X-Jakawi-Timestamp'),$nonce,'POST',$path,hash('sha256',$request->body())]);
    check($header('X-Jakawi-Key-Id') === 'club-purchases-v1' && hash_equals(hash_hmac('sha256',$canonical,str_repeat('h',64)),$header('X-Jakawi-Signature')), '10G outbound HMAC valid');
    $input = json_decode($request->body(),true); $requests10g[]=$input;
    if ($http10g === 0) throw new Illuminate\Http\Client\ConnectionException('Isolated timeout');
    if ($http10g !== 200) return Http::response([], $http10g);
    $event = DB::table('commercial_events')->where('event_type','membership.activated')->whereRaw("raw_body::jsonb->>'payment_reference' = ?",[$input['purchase_reference']])->orderBy('id')->firstOrFail();
    $data=json_decode($event->raw_body,true);
    $at=$data['occurred_at'];
    $buyer=$event->global_subject_id;
    if (!$buyer) {
        $personId=DB::table('acquisition_activations')->where('event_id',$event->id)->value('person_id');
        $buyer=DB::table('acquisition_aliases')->where('person_id',$personId)->where('kind','subject')->value('reference');
    }
    $body=['contract'=>'jakawi-acquisition-evidence-v1','purchase_reference'=>$input['purchase_reference'],
        'purchase_source'=>'membership_bank_payments','membership_reference'=>$event->com_reference_id,
        'global_subject_id'=>$buyer,'identity_reference'=>$buyer ? 'com:user:123' : null,
        'first_activated_at'=>$at,'first_paid_acquisition'=>$first10g,'reason'=>'HISTORY_BEFORE_OBSERVATION_BOUNDARY','evaluated_at'=>$at,
        'affiliate'=>$input['affiliate_global_subject_id'] ? ['global_subject_id'=>$input['affiliate_global_subject_id'],'state'=>$state10g,'reason'=>'FIXTURE','evaluated_at'=>$at,'membership_references'=>[]] : null,
        'history_observed_from'=>now()->subDay()->toIso8601String(),'evidence_references'=>['com:membership-evidence:123']];
    if ($corrupt10g === 'buyer') $body['global_subject_id']=(string)Illuminate\Support\Str::uuid();
    if ($corrupt10g === 'subject') $body['affiliate']['global_subject_id']=(string)Illuminate\Support\Str::uuid();
    if ($corrupt10g === 'reference') $body['purchase_reference']='OTHER';
    if ($corrupt10g === 'typed') $body['first_paid_acquisition']='true';
    if ($corrupt10g === 'contract') $body['contract']='other';
    if ($corrupt10g === 'activation') $body['evaluated_at']=now()->subDay()->toIso8601String();
    $body['evidence_reference']='com:acquisition-evidence:sha256:'.hash('sha256',json_encode($body,JSON_UNESCAPED_SLASHES));
    if ($corrupt10g === 'digest') $body['evidence_reference'].='0';
    return Http::response($body);
});
$ledgerStart=DB::table('commission_ledger')->orderBy('id')->get()->keyBy('id');
$makeG=function (?array $originG = null) use($make10e,$origin):array {return $make10e($originG ?? $origin);};
$amountG=fn($e)=>DB::table('commission_ledger')->where('com_reference_id',$e['membership_reference'])->value('amount');
$beforeRateG=$makeG();$beforeRateG['occurred_at']=Carbon::parse($rules10e[0]->effective_at)->subSecond()->toIso8601String();
check(send($beforeRateG)->getStatusCode()===201 && $amountG($beforeRateG)===null,'10G real evidence before v1 validity never earns retroactively');
$gfree=$makeG($individualOrigin);check(send($gfree)->getStatusCode()===201 && $amountG($gfree)==='15.0000','10G real resolver first FREE Bs15');
check(end($requests10g)['affiliate_global_subject_id']===$individual->global_subject_id && end($requests10g)['purchase_reference']===$gfree['payment_reference'],'10G original affiliate and COM payment reference');
$state10g='PAID';$gpaid=$makeG($individualOrigin);check(send($gpaid)->getStatusCode()===201 && $amountG($gpaid)==='25.0000','10G first PAID Bs25');
$gpartner=$makeG();check(send($gpartner)->getStatusCode()===201 && $amountG($gpartner)==='30.0000' && end($requests10g)['affiliate_global_subject_id']===null,'10G Partner Bs30 without owner state');
foreach ([false,'unknown'] as $firstG) {
 $first10g=$firstG;$e=$makeG();check(send($e)->getStatusCode()===201 && $amountG($e)===null,'10G false or unknown cannot create commission');
}
$first10g=true;$state10g='UNKNOWN';$e=$makeG($individualOrigin);check(send($e)->getStatusCode()===201 && $amountG($e)===null,'10G UNKNOWN affiliate unvalued');
$state10g='FREE';
$unlinkedAffiliate=App\Models\User::create(['name'=>'Unlinked affiliate','email'=>'unlinked-g@example.test']);
$unlinkedOrigin=['source'=>'jakawi.com','kind'=>'club_referral','club_referral_code'=>$unlinkedAffiliate->referral_code];
$unlinkedG=$makeG($unlinkedOrigin);$n=count($requests10g);
check(send($unlinkedG)->getStatusCode()===201 && $amountG($unlinkedG)===null && count($requests10g)===$n,'10G missing authorized affiliate identity remains pending without FREE fallback');
$unlinkedAffiliate->forceFill(['global_subject_id'=>(string)Illuminate\Support\Str::uuid()])->save();
check(send($unlinkedG)->getStatusCode()===200 && $amountG($unlinkedG)==='15.0000','10G authorized affiliate link permits reevaluation');
foreach (['subject','buyer','reference','typed','contract','activation','digest'] as $bad) {
 $corrupt10g=$bad;$e=$makeG($individualOrigin);check(send($e)->getStatusCode()===201 && $amountG($e)===null,'10G invalid '.$bad.' withheld');
}
$corrupt10g=null;
foreach ([0,429,503] as $statusG) {
 $http10g=$statusG;$e=$makeG();check(send($e)->getStatusCode()===201 && $amountG($e)===null,'10G HTTP '.$statusG.' no speculative commission');
 $n=count($requests10g);send($e);check(count($requests10g)===$n,'10G repeated request bounded by cooldown');
}
$http10g=200;
// Guest evidence remains pending until an authorized identity event supplies the buyer.
$guestG=$ga;$guestPayG=(string)Illuminate\Support\Str::uuid();
$guestG['event_id']='com:membership:'.$guestPayG.':activated';$guestG['payment_reference']=$guestPayG;
$guestG['club_reference']=(string)Illuminate\Support\Str::uuid();$guestG['guest_purchase_id']=(string)Illuminate\Support\Str::uuid();
$guestG['membership_reference']='MEM-GUEST-10G';$guestG['com_reference']['id']='MEM-GUEST-10G';$guestG['occurred_at']=now()->toIso8601String();
$first10g='unknown';check(send($guestG)->getStatusCode()===201,'10G guest activation retained');
$guestFixedG=$fixed;$guestFixedG['payment_reference']=$guestPayG;$guestFixedG['club_reference']=$guestG['club_reference'];
$guestFixedG['guest_purchase_id']=$guestG['guest_purchase_id'];$guestFixedG['event_id']='com:membership:'.$guestPayG.':attribution-fixed';$guestFixedG['occurred_at']=now()->toIso8601String();
check(send($guestFixedG)->getStatusCode()===201 && $amountG($guestG)===null,'10G guest UNKNOWN pending');
$first10g=true;$guestLinkedG=$guestFixedG;$guestLinkedG['event_type']='membership.attribution_identity_linked';
$guestLinkedG['event_id']='com:membership:'.$guestPayG.':attribution-identity-linked';$guestLinkedG['global_subject_id']=(string)Illuminate\Support\Str::uuid();$guestLinkedG['purchase_acquisition']=$origin;
check(send($guestLinkedG)->getStatusCode()===201 && $amountG($guestG)==='30.0000','10G late buyer identity reconsults evidence immediately');
$n=DB::table('commission_ledger')->count();send($guestLinkedG);check(DB::table('commission_ledger')->count()===$n,'10G identity repeat never second acquisition');
// Publish a new amount using the existing admin version workflow, while preserving v1.
$draftG=['beneficiary_type'=>'INDIVIDUAL','membership_status'=>'FREE','nature'=>'ONE_TIME_ACQUISITION','partner_id'=>'*','event_type'=>'membership.activated','fixed_amount'=>'20','currency'=>'BOB','effective_at'=>now()->addMinute()->toIso8601String()];
$adminG=login(true);web('POST','/admin/reglas-comisiones',$draftG);$ruleG=DB::table('commission_rules')->orderByDesc('id')->first();
check($ruleG->rule_version !== null && $ruleG->rule_version !== App\Services\Commissions\AcquisitionRates::VERSION,'10G changed Bs20 has distinct version');
web('POST','/admin/reglas-comisiones/'.$ruleG->id.'/estado',['status'=>'active','reason'=>'Isolated future version']);
$lateG=$makeG($individualOrigin);
Carbon::setTestNow(now()->addMinutes(2));
check(send($lateG)->getStatusCode()===201 && $amountG($lateG)==='15.0000','10G old activation delivered after new tariff keeps Bs15');
$newG=$makeG($individualOrigin);check(send($newG)->getStatusCode()===201 && $amountG($newG)==='20.0000','10G subsequent activation uses new Bs20 version');
check($amountG($gfree)==='15.0000','10G new version never recalculates ledger');
foreach($ledgerStart as $id=>$oldG) check(json_encode(DB::table('commission_ledger')->find($id))===json_encode($oldG),'10G historical ledger row intact');
$n=DB::table('commission_ledger')->count();send($gfree);send($gpaid);check(DB::table('commission_ledger')->count()===$n,'10G repeated evidence and events deduplicated');
check(DB::table('acquisition_evidence')->where('response_body','<>',null)->exists(),'10G private raw evidence persisted');
$realEntry=DB::table('commission_ledger')->where('com_reference_id',$gfree['membership_reference'])->first();
check($realEntry->status==='candidate','10G real evidence never approves automatically');
$realApproved=app(App\Services\Commissions\CommissionEngine::class)->review($realEntry->id,['action'=>'approve','version'=>$realEntry->version,'reason'=>'Isolated real resolver approval'],$adminG);
check($realApproved->status==='approved' && $realApproved->amount==='15.0000','10G manual approval preserves frozen tariff after change');
$adminPage=payload(web('GET','/admin/comisiones'));check(count($adminPage['props']['evidence'])>0 && !isset($adminPage['props']['evidence'][0]['response_body']),'10G admin reasons visible without private body');
Carbon::setTestNow();
