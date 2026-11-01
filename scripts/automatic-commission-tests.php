<?php
use Illuminate\Support\Facades\DB;
use App\Services\Commissions\CommissionEngine;

$engineAuto = app(CommissionEngine::class);
foreach ([$gfree, $gpaid, $gpartner] as $fixtureAuto) {
    $rowAuto = DB::table('commission_ledger')->where('com_reference_id', $fixtureAuto['membership_reference'])->first();
    check($rowAuto->status === 'approved' && !$rowAuto->review_required && $rowAuto->approved_at !== null && $rowAuto->approved_by === null, 'verified acquisition automatically approved without administrative actor');
    check(DB::table('commission_audit')->where('ledger_id', $rowAuto->id)->where('action', 'automatically_approved')->count() === 1, 'single auditable automatic approval');
}
$rowAuto = DB::table('commission_ledger')->where('com_reference_id', $gpaid['membership_reference'])->first();
$beforeAuto = json_encode($rowAuto);
check(Illuminate\Support\Facades\Artisan::call('commissions:automatic', ['--entry' => (string)$rowAuto->id]) === 0, 'directed existing-entry command uses common verifier');
check(Illuminate\Support\Facades\Artisan::call('commissions:automatic') === 0, 'bounded scheduled evidence command available');
$engineAuto->approveExistingAcquisition($rowAuto->id);
check(json_encode(DB::table('commission_ledger')->find($rowAuto->id)) === $beforeAuto, 'existing Bs25 retry leaves version amount and approval intact');
$buyerAuto = new App\Models\User(['name'=>'Authorized buyer', 'email'=>'automatic-buyer@example.test']);
$buyerAuto->forceFill(['global_subject_id'=>$gfree['global_subject_id'], 'referred_by_id'=>$individual->id])->save();
$identityAuto = ['schema_version'=>1,'event_id'=>'com:membership:'.$gfree['payment_reference'].':identity-linked',
    'event_type'=>'membership.identity_linked','occurred_at'=>now()->toIso8601String(),
    'payment_reference'=>$gfree['payment_reference'],'club_reference'=>(string)Illuminate\Support\Str::uuid(),
    'club_user_uuid'=>$buyerAuto->uuid,'global_subject_id'=>$gfree['global_subject_id'],'membership_reference'=>$gfree['membership_reference']];
check(send($identityAuto)->getStatusCode() === 201, 'authorized membership identity event accepted after approval');
$identityRowAuto = DB::table('commission_ledger')->where('com_reference_id', $gfree['membership_reference'])->first();
check($identityRowAuto->status === 'approved' && !$identityRowAuto->review_required, 'benign identity link and compatible refreshed evidence preserve approval');
check($engineAuto->approveExistingAcquisition($identityRowAuto->id)->status === 'approved', 'existing entry resolves authorized merged person without changing original snapshot');
$identityConflictAuto = $identityAuto; $identityConflictAuto['occurred_at'] = now()->addSecond()->toIso8601String();
check(send($identityConflictAuto)->getStatusCode() === 409 && DB::table('commission_ledger')->find($identityRowAuto->id)->review_required, 'identity receiver conflict immediately requires review');
// Same signed event ID with different bytes must immediately suspend normal approval display.
$conflictAuto = $gpaid; $conflictAuto['occurred_at'] = Illuminate\Support\Carbon::parse($conflictAuto['occurred_at'])->addSecond()->toIso8601String();
check(send($conflictAuto)->getStatusCode() === 409, 'signed conflicting duplicate rejected');
$rowAuto = DB::table('commission_ledger')->find($rowAuto->id);
check($rowAuto->review_required && $rowAuto->amount === '25.0000', 'conflict requires review and never rewrites Bs25');
$engineAuto->approveExistingAcquisition($rowAuto->id);
check(DB::table('commission_ledger')->find($rowAuto->id)->review_required, 'repeat cannot clear conflict alert');
foreach (['FRAUD', 'DISPUTED', 'REVERSED', 'CONTRADICTION'] as $signalAuto) {
    $corrupt10g = $signalAuto;
    $eventAuto = $makeG($individualOrigin);
    check(send($eventAuto)->getStatusCode() === 201 && $amountG($eventAuto) === null, 'adverse evidence '.$signalAuto.' never approves');
    check(DB::table('acquisition_evidence')->where('event_id', DB::table('commercial_events')->where('event_id', $eventAuto['event_id'])->value('id'))->value('status') === 'invalid', 'adverse evidence requires review');
}
$corrupt10g = null;
$first10g = 'unknown'; $unknownAuto = $makeG($individualOrigin);
check(send($unknownAuto)->getStatusCode() === 201 && $amountG($unknownAuto) === null, 'UNKNOWN never values or approves a commission');
$first10g = true;
// Roll back receipt, economic entry and status together when writing approval audit fails.
DB::unprepared("CREATE FUNCTION fail_auto_audit() RETURNS trigger AS $$ BEGIN IF NEW.action = 'automatically_approved' THEN RAISE EXCEPTION 'isolated audit failure'; END IF; RETURN NEW; END; $$ LANGUAGE plpgsql; CREATE TRIGGER fail_auto_audit BEFORE INSERT ON commission_audit FOR EACH ROW EXECUTE FUNCTION fail_auto_audit()");
$errorAuto = $makeG($individualOrigin);
check(send($errorAuto)->getStatusCode() === 500, 'approval audit error returns failure');
check(!DB::table('commercial_events')->where('event_id', $errorAuto['event_id'])->exists() && $amountG($errorAuto) === null, 'audit error leaves no partial economic approval or receipt');
DB::unprepared('DROP TRIGGER fail_auto_audit ON commission_audit; DROP FUNCTION fail_auto_audit()');
check(send($errorAuto)->getStatusCode() === 201 && DB::table('commission_ledger')->where('com_reference_id', $errorAuto['membership_reference'])->value('status') === 'approved', 'retry after audit error succeeds once');
$adminAuto = login(true);
$browserAuto = ['all' => payload(web('GET', '/admin/comisiones'))];
foreach (['approved', 'evidence', 'review', 'pending_payment'] as $categoryAuto) {
    $pageAuto = payload(web('GET', '/admin/comisiones?status='.$categoryAuto));
    $browserAuto[$categoryAuto] = $pageAuto;
    if ($categoryAuto === 'review') check(collect($pageAuto['props']['evidence'])->contains(fn ($item) => $item['event_id'] === DB::table('commercial_events')->where('event_id', $unknownAuto['event_id'])->value('id') && $item['requires_review']), 'UNKNOWN appears in requires review category');
    if ($categoryAuto === 'evidence') check(!collect($pageAuto['props']['evidence'])->contains(fn ($item) => $item['event_id'] === DB::table('commercial_events')->where('event_id', $unknownAuto['event_id'])->value('id')), 'UNKNOWN is separated from missing evidence');
    check(($pageAuto['component'] ?? null) === 'admin/CommissionLedger', 'admin category '.$categoryAuto.' available');
    if ($categoryAuto === 'pending_payment') foreach ($pageAuto['props']['entries']['data'] as $itemAuto) check($itemAuto['status'] === 'approved' && !$itemAuto['review_required'], 'pending payment contains approved unflagged entries only');
}
file_put_contents('/evidence/admin-browser-fixture.json', json_encode(payload(web('GET', '/admin/comisiones')), JSON_THROW_ON_ERROR));
check(!Illuminate\Support\Facades\Schema::hasTable('commission_payments'), 'approval introduces no payment or disbursement table');

$cookies=[]; Illuminate\Support\Facades\Auth::forgetGuards(); app('session')->flush(); app('session')->regenerate();
$loginResponseAuto = web('POST', '/login', ['email'=>$individual->email,'password'=>'FixturePassword123'], false);
$affiliateResponseAuto = web('GET', '/afiliados?tab=results');
check($affiliateResponseAuto->getStatusCode() === 200, 'affiliate fixture HTTP 200; login '.$loginResponseAuto->getStatusCode().' destination '.$affiliateResponseAuto->headers->get('Location'));
$browserAuto['affiliate'] = payload($affiliateResponseAuto);
file_put_contents('/evidence/browser-fixtures.json', json_encode($browserAuto, JSON_THROW_ON_ERROR));
