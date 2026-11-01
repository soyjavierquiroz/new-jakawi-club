<?php
namespace App\Services;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
final class AcquisitionEvents {
 public function receive(Request $request,array $data,string $digest): JsonResponse {
  $registered = ($data['event_type'] ?? '') === 'member.registered';
  $linked = ($data['event_type'] ?? '') === 'membership.attribution_identity_linked';
  $allowed = ['schema_version','source','event_id','event_type','occurred_at','global_subject_id','acquisition', ...($registered ? ['com_reference'] : ['payment_reference','club_reference','guest_purchase_id','club_user_uuid', ...($linked ? ['purchase_acquisition'] : [])])];
  if (array_diff(array_keys($data),$allowed) || ($data['schema_version'] ?? null) !== 1) throw ValidationException::withMessages(['payload'=>'Unknown fields or schema.']);
  $validated = Validator::make($data,[
   'schema_version'=>'required|integer|in:1','source'=>'required|in:jakawi.com','event_id'=>'required|string|max:128',
   'event_type'=>'required|in:member.registered,membership.attribution_fixed,membership.attribution_identity_linked',
   'occurred_at'=>['required','date','before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
   'global_subject_id'=>[$registered || $linked ? 'required':'present','nullable','uuid'],
   'com_reference'=>$registered ? 'required|array:type,id':'prohibited', 'com_reference.type'=>$registered ? 'required|in:user':'prohibited', 'com_reference.id'=>$registered ? 'required|string|max:190':'prohibited',
   'payment_reference'=>$registered ? 'prohibited':'required|uuid','club_reference'=>$registered ? 'prohibited':'present|nullable|uuid',
   'guest_purchase_id'=>$registered ? 'prohibited':'present|nullable|uuid','club_user_uuid'=>$registered ? 'prohibited':'present|nullable|uuid',
   'acquisition'=>'present|nullable|array','purchase_acquisition'=>$linked ? 'present|nullable|array':'prohibited',
  ])->validate();
  foreach (['acquisition','purchase_acquisition'] as $field) {
   $a = $data[$field] ?? null;
   if ($a === null) continue;
   if (($a['source'] ?? null) !== 'jakawi.com' || !in_array($a['kind'] ?? null,['none','partner','club_referral','com_referral'],true)) throw ValidationException::withMessages([$field=>'Invalid acquisition.']);
   Validator::make($a,[
    'partner_id'=>[($a['kind'] === 'partner') ? 'required':'sometimes','integer','min:1'],
    'fallback_partner_id'=>'sometimes|nullable|integer|min:1','club_referral_code'=>[$a['kind']==='club_referral'?'required':'sometimes','regex:/^[A-Z0-9]{12}$/D'],
    'referrer_global_subject_id'=>[$a['kind']==='com_referral'?'required':'sometimes','nullable','uuid'],
    'attribution_id'=>'sometimes|uuid','fixed_at'=>'sometimes|date',
   ])->validate();
  }
  $expected = $registered ? 'com:member:'.$data['global_subject_id'].':registered' : 'com:membership:'.$data['payment_reference'].($linked ? ':attribution-identity-linked':':attribution-fixed');
  abort_unless($data['event_id'] === $expected,422);
  $raw=$request->getContent();
  $provenance=['raw_body'=>$raw,'raw_body_sha256'=>hash('sha256',$raw),'payload_sha256'=>$digest,'key_id'=>$request->header('X-Jakawi-Com-Key-Id'),'signed_at'=>(int)$request->header('X-Jakawi-Com-Timestamp')];
  return DB::transaction(function() use($data,$digest,$provenance,$registered): JsonResponse {
   DB::select("select pg_advisory_xact_lock(hashtextextended('club_acquisition',0))");
   $inserted=DB::table('commercial_events')->insertOrIgnore([...$provenance,'source'=>'jakawi.com','event_id'=>$data['event_id'],'schema_version'=>1,'event_type'=>$data['event_type'],'occurred_at'=>Carbon::parse($data['occurred_at'])->utc(),'received_at'=>now()->utc(), 'com_reference_type'=>$registered?'user':'membership_purchase','com_reference_id'=>$registered?$data['com_reference']['id']:$data['payment_reference'],'global_subject_id'=>$data['global_subject_id'],'conversion_status'=>$registered?'registered':'attribution']);
   $event=DB::table('commercial_events')->where('source','jakawi.com')->where('event_id',$data['event_id'])->first();
   if (!hash_equals($event->payload_sha256,$digest)) {
    DB::table('commercial_event_conflicts')->insertOrIgnore([...$provenance,'commercial_event_id'=>$event->id,'detected_at'=>now()->utc()]);
    app(\App\Services\Commissions\CommissionEngine::class)->flagOperationReview($event);
    return response()->json(['error'=>'event_id_conflict','event_id'=>$event->event_id],409);
   }
   app(PartnerAcquisition::class)->event($event,$data);
   return response()->json(['event_id'=>$event->event_id,'status'=>$inserted?'accepted':'duplicate'],$inserted?201:200);
  },3);
 }
}
