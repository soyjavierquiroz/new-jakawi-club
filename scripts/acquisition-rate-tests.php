<?php
// Included by the existing HTTP/HMAC runner inside its disposable PostgreSQL schema.
use App\Services\Commissions\AcquisitionRates;
use App\Services\Commissions\MembershipAcquisitionEvidence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

$ledgerBefore = DB::table('commission_ledger')->orderBy('id')->get()->toJson();
$rules10e = app(AcquisitionRates::class)->publish($admin);
check(count($rules10e) === 3, '10E three official rates published');
check(count(app(AcquisitionRates::class)->publish($admin)) === 3, '10E publication idempotent');
check(array_map(fn($r)=>(string)$r->fixed_amount,$rules10e) === ['15.0000','25.0000','30.0000'], '10E exact fixed BOB rates');
check(DB::table('commission_ledger')->orderBy('id')->get()->toJson() === $ledgerBefore, '10E publication preserves historical ledger');
check(DB::table('commission_audit')->whereIn('rule_id',array_column($rules10e,'id'))->count() === 3, '10E publication audited');
Carbon::setTestNow(now()->addSeconds(2));
$fixtureState = 'FREE'; $fixtureFirst = true;
// An isolated substitute for the MISSING authoritative COM evidence, never production data.
$fixtureEvidence = new class extends MembershipAcquisitionEvidence {
    public function resolve(object $person, object $event, Carbon $at): ?array {
        global $fixtureState, $fixtureFirst;
        return ['first_acquisition'=>$fixtureFirst,'membership_status'=>$fixtureState,'reference'=>'isolated-COM-contract-fixture'];
    }
};
$make10e = function (?array $attribution, ?string $subject = null) use ($make): array {
    $subject ??= (string)Illuminate\Support\Str::uuid();
    $lead = $make(['event_id'=>'com:member:'.$subject.':registered','event_type'=>'member.registered','global_subject_id'=>$subject,'com_reference'=>['type'=>'user','id'=>$subject],'acquisition'=>$attribution]);
    check(send($lead)->getStatusCode() === 201,'10E isolated signed lead accepted');
    $ref = 'RATE-'.Illuminate\Support\Str::uuid();
    return ['schema_version'=>1,'event_id'=>'com:membership:'.$ref,'event_type'=>'membership.activated','occurred_at'=>now()->toIso8601String(),'com_reference'=>['type'=>'membership','id'=>$ref],'global_subject_id'=>$subject,'membership_reference'=>$ref,'payment_reference'=>(string)Illuminate\Support\Str::uuid()];
};
$individualOrigin = ['source'=>'jakawi.com','kind'=>'club_referral','club_referral_code'=>$individual->referral_code,'fallback_partner_id'=>43];
$unknown = $make10e($individualOrigin);
$n = DB::table('commission_ledger')->count();
check(send($unknown)->getStatusCode() === 201 && DB::table('commission_ledger')->count() === $n, '10E real resolver withholds commission without COM evidence');

app()->instance(MembershipAcquisitionEvidence::class,$fixtureEvidence);
$free = $make10e($individualOrigin);
check(send($free)->getStatusCode() === 201,'10E free affiliate activation accepted');
$freeEntry = DB::table('commission_ledger')->where('com_reference_id',$free['membership_reference'])->first();
check($freeEntry?->amount === '15.0000','10E FREE earns Bs15 with authoritative evidence fixture');
$snapshot = json_decode($freeEntry->rule_snapshot,true);
check($snapshot['membership_evidence']['membership_status'] === 'FREE' && $snapshot['payment_reference'] === $free['payment_reference'] && $snapshot['rule_version'] === AcquisitionRates::VERSION && isset($snapshot['acquisition_at']), '10E immutable snapshot holds category rule version purchase and acquisition time');
$fixtureState = 'PAID';
$paid = $make10e($individualOrigin);
check(send($paid)->getStatusCode() === 201 && DB::table('commission_ledger')->where('com_reference_id',$paid['membership_reference'])->value('amount') === '25.0000','10E PAID earns Bs25 with authoritative evidence fixture');
check(DB::table('commission_ledger')->find($freeEntry->id)->amount === '15.0000','10E affiliate upgrade preserves prior Bs15');
$partnerActivation = $make10e($origin);
check(send($partnerActivation)->getStatusCode() === 201,'10E Partner activation accepted');
$business = DB::table('commission_ledger')->where('com_reference_id',$partnerActivation['membership_reference'])->first();
check($business?->amount === '30.0000' && $business->beneficiary_id === '43' && $business->affiliate_uuid === null,'10E Partner business receives Bs30 independent of personal membership');
$n = DB::table('commission_ledger')->count();
check(send($free)->getStatusCode() === 200 && DB::table('commission_ledger')->count() === $n,'10E duplicate produces zero additional commission');
foreach (['renewal','reactivation','second-payment'] as $kind) {
    $again = $free; $ref = $kind.'-'.Illuminate\Support\Str::uuid();
    $again['event_id']='com:membership:'.$ref; $again['membership_reference']=$ref; $again['com_reference']['id']=$ref; $again['payment_reference']=(string)Illuminate\Support\Str::uuid();
    check(send($again)->getStatusCode() === 201 && DB::table('commission_ledger')->count() === $n,'10E '.$kind.' produces zero additional commission');
}
$fixtureFirst = false;
$notFirst = $make10e($origin);
check(send($notFirst)->getStatusCode() === 201 && DB::table('commission_ledger')->count() === $n,'10E prior COM purchase outside CLUB history blocked');
$fixtureFirst = true;
$noRef = $make10e(null);
check(send($noRef)->getStatusCode() === 201 && DB::table('commission_ledger')->count() === $n,'10E no referrer produces zero');
$beforeLead = DB::table('commission_ledger')->count(); $leadOnly = $make10e($origin);
check(DB::table('commission_ledger')->count() === $beforeLead,'10E free lead and unapproved purchase produce zero');
$individual->forceFill(['global_subject_id'=>(string)Illuminate\Support\Str::uuid()])->save();
$self = $make10e($individualOrigin,$individual->global_subject_id);
check(send($self)->getStatusCode() === 201 && DB::table('commission_ledger')->count() === $n,'10E self referral blocked with authenticated subject');
$historic = $make10e($origin); $historic['occurred_at']=Carbon::parse($rules10e[0]->effective_at)->subSecond()->toIso8601String();
// This timestamp still permits a legacy rule; new rates must never value it.
check(send($historic)->getStatusCode() === 201 && !DB::table('commission_ledger')->where('com_reference_id',$historic['membership_reference'])->whereIn('rule_id',array_column($rules10e,'id'))->exists(),'10E new tariffs never apply before publication');
check(DB::table('commission_ledger')->find($freeEntry->id)->status === 'candidate','10E approval remains manual');
$approved10e = app(App\Services\Commissions\CommissionEngine::class)->review($freeEntry->id,['action'=>'approve','version'=>$freeEntry->version,'reason'=>'Isolated 10E manual approval'],$admin);
check($approved10e->status === 'candidate' && $approved10e->review_required && $approved10e->amount === '15.0000','insufficient evidence cannot use manual approval bypass');
// Existing authenticated guest, identity merge and original-referrer competition cases
// above exercise the same acquisition resolver. Revalue the retained guest with new
// rules forbidden by its original activation date.
check(DB::table('commission_ledger')->where('com_reference_id','MEM-GUEST')->count() === 1,'10E original guest ledger and identity uniqueness preserved');
$guestPayment=(string)Illuminate\Support\Str::uuid(); $guestPurchaseId=(string)Illuminate\Support\Str::uuid();
$guest10=$ga; $guest10['event_id']='com:membership:'.$guestPayment.':activated'; $guest10['payment_reference']=$guestPayment;
$guest10['club_reference']=$guestPurchaseId; $guest10['guest_purchase_id']=(string)Illuminate\Support\Str::uuid();
$guest10['membership_reference']='MEM-10E-GUEST'; $guest10['com_reference']['id']='MEM-10E-GUEST'; $guest10['occurred_at']=now()->toIso8601String();
check(send($guest10)->getStatusCode() === 201,'10E guest activation retained');
$guestAttribution=$fixed; $guestAttribution['payment_reference']=$guestPayment; $guestAttribution['club_reference']=$guestPurchaseId;
$guestAttribution['guest_purchase_id']=$guest10['guest_purchase_id']; $guestAttribution['event_id']='com:membership:'.$guestPayment.':attribution-fixed';
$guestAttribution['occurred_at']=now()->toIso8601String();
check(send($guestAttribution)->getStatusCode() === 201 && DB::table('commission_ledger')->where('com_reference_id','MEM-10E-GUEST')->value('amount') === '30.0000','10E attributed guest acquires exactly Bs30 under evidence fixture');
$guestCount=DB::table('commission_ledger')->count();
$guestIdentity=$guestAttribution; $guestIdentity['event_type']='membership.attribution_identity_linked';
$guestIdentity['event_id']='com:membership:'.$guestPayment.':attribution-identity-linked';
$guestIdentity['global_subject_id']=(string)Illuminate\Support\Str::uuid(); $guestIdentity['purchase_acquisition']=$origin;
check(send($guestIdentity)->getStatusCode() === 201 && DB::table('commission_ledger')->count() === $guestCount,'10E later identity_linked creates zero additional commission');
check(DB::table('commission_ledger')->where('com_reference_id',$paid['membership_reference'])->value('beneficiary_id') === $individual->uuid,'10E original individual beats competing Partner fallback');
app()->forgetInstance(MembershipAcquisitionEvidence::class);
\Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory);
\Illuminate\Support\Facades\Http::fake(['*'=>\Illuminate\Support\Facades\Http::response([],503)]);
$blockedPartner = $make10e($origin); $n=DB::table('commission_ledger')->count();
check(send($blockedPartner)->getStatusCode() === 201 && DB::table('commission_ledger')->count() === $n,'10E Partner also requires first-ever purchase evidence');
$rulesAdmin=login(true);
$rulesResponse=payload(web('GET','/admin/reglas-comisiones'));
check(($rulesResponse['props']['acquisition_contract_ready'] ?? null) === true,'10G admin reports connected COM contract');
$draft=['beneficiary_type'=>'INDIVIDUAL','membership_status'=>'FREE','nature'=>'ONE_TIME_ACQUISITION',
    'partner_id'=>'*','event_type'=>'membership.activated','fixed_amount'=>'15','currency'=>'BOB',
    'effective_at'=>now()->addMinute()->toIso8601String()];
check(web('POST','/admin/reglas-comisiones',$draft)->getStatusCode() === 302,'10E future acquisition rule editable in existing admin');
$draftId=DB::table('commission_rules')->max('id');
Carbon::setTestNow(now()->addMinutes(2));
web('POST','/admin/reglas-comisiones/'.$draftId.'/estado',['status'=>'active','reason'=>'Isolated late activation']);
check(DB::table('commission_rules')->find($draftId)->status === 'draft','10E delayed activation cannot backdate new rates');
Carbon::setTestNow();
