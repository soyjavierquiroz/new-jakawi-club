<?php
namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PartnerAcquisition
{
    public function token(string $token): ?array
    {
        if (strlen($token) > 8192 || !preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $token, $m)) return null;
        $file = config('commercial.secret_file');
        $secret = is_readable($file) ? trim(file_get_contents($file)) : '';
        if (!$secret || !hash_equals(hash_hmac('sha256', "jakawi-com-origin-v1\n".$m[1], $secret), $m[2])) return null;
        $data = json_decode(base64_decode(strtr($m[1], '-_', '+/'), true) ?: '', true);
        if (!is_array($data) || ($data['contract'] ?? '') !== 'jakawi-com-origin-v1' || ($data['source'] ?? '') !== 'jakawi.com'
            || ($data['audience'] ?? '') !== 'jakawi.club' || !is_int($data['issued_at'] ?? null) || !is_int($data['expires_at'] ?? null)
            || $data['issued_at'] > time()+300 || $data['expires_at'] <= time() || $data['expires_at'] <= $data['issued_at']) return null;
        return is_array($data['acquisition'] ?? null) ? $data['acquisition'] : null;
    }

    public function capture(Request $request): void
    {
        if ($request->isMethod('GET') && is_string($request->query('com_origin')) && $this->token($request->query('com_origin'))) {
            // A candidate only. A real registration/purchase fixes the beneficiary.
            $request->session()->put('com_origin', $request->query('com_origin'));
            $a = $this->token($request->query('com_origin'));
            if (($a['kind'] ?? '') === 'club_referral' && is_string($a['club_referral_code'] ?? null)) app(ReferralAttribution::class)->capture($request,$a['club_referral_code']);
        }
        if (is_string($request->query('club_ref'))) app(ReferralAttribution::class)->capture($request, $request->query('club_ref'));
    }

    public function resolve(?array $a): ?array
    {
        if (!$a || ($a['source'] ?? '') !== 'jakawi.com') return null;
        if (($a['kind'] ?? '') === 'club_referral') {
            $user = app(ReferralAttribution::class)->valid($a['club_referral_code'] ?? null);
            if ($user) return ['beneficiary_type'=>'INDIVIDUAL','beneficiary_id'=>$user->uuid,'referral_code'=>$user->referral_code];
            $id = $a['fallback_partner_id'] ?? null;
        } elseif (($a['kind'] ?? '') === 'com_referral') {
            if (!Str::isUuid($a['referrer_global_subject_id'] ?? '')) return null;
            $user = User::where('global_subject_id', $a['referrer_global_subject_id'])->first();
            return $user ? ['beneficiary_type'=>'INDIVIDUAL','beneficiary_id'=>$user->uuid,'referral_code'=>$user->referral_code] : null;
        } elseif (($a['kind'] ?? '') === 'partner') $id = $a['partner_id'] ?? null;
        else return null;
        if ((!is_int($id) && !is_string($id)) || !DB::table('affiliate_partners')->where('source','jakawi.com')->where('partner_id',(string)$id)->where('status','enabled')->exists()) return null;
        return ['beneficiary_type'=>'PARTNER','beneficiary_id'=>(string)$id,'referral_code'=>null];
    }

    // Calls are serialized with receipt processing. Aliases are verified IDs only.
    public function person(array $refs, ?array $candidate = null): object
    {
        DB::select("select pg_advisory_xact_lock(hashtextextended('club_acquisition', 0))");
        $refs = array_filter($refs, fn($v) => is_string($v) && $v !== '');
        // Known CLUB identity is authoritative over an incoming purchase candidate.
        $user = isset($refs['user']) ? User::where('uuid',$refs['user'])->first() : null;
        if (!$user && isset($refs['subject'])) $user = User::where('global_subject_id',$refs['subject'])->first();
        if ($user) {
            abort_unless(!$user->global_subject_id || !isset($refs['subject']) || $refs['subject'] === $user->global_subject_id,409);
            $refs['user'] = $user->uuid;
            if ($user->global_subject_id) $refs['subject'] = $user->global_subject_id;
            if ($user->referred_by_id && ($r = User::find($user->referred_by_id))) $candidate = ['beneficiary_type'=>'INDIVIDUAL','beneficiary_id'=>$r->uuid,'referral_code'=>$r->referral_code];
        }
        // Legacy CLUB referrals predate these tables. Materialize that original
        // before merging a guest purchase so delivery order cannot replace it.
        if ($user?->referred_by_id && $candidate && !DB::table('acquisition_aliases')->where('kind','user')->where('reference',$user->uuid)->exists()) {
            $id=(string)Str::uuid();
            DB::table('acquisition_people')->insert(['id'=>$id,...$candidate,'fixed_at'=>now(),'created_at'=>now()]);
            DB::table('acquisition_aliases')->insert(['kind'=>'user','reference'=>$user->uuid,'person_id'=>$id]);
        }
        $people = [];
        foreach ($refs as $kind=>$ref) {
            $alias = DB::table('acquisition_aliases')->where('kind',$kind)->where('reference',$ref)->first();
            if ($alias) $people[$kind] = DB::table('acquisition_people')->find($alias->person_id);
        }
        $person = $people['user'] ?? $people['subject'] ?? reset($people);
        if (!$person) {
            $id = (string)Str::uuid();
            DB::table('acquisition_people')->insert(['id'=>$id,'created_at'=>now()]);
            $person = DB::table('acquisition_people')->find($id);
        }
        foreach ($people as $other) {
            if ($other->id === $person->id) continue;
            // Preserve the user's original. Purchase provenance stays in immutable facts.
            if (!$person->beneficiary_id && !$candidate && $other->beneficiary_id) $candidate = ['beneficiary_type'=>$other->beneficiary_type,'beneficiary_id'=>$other->beneficiary_id,'referral_code'=>$other->referral_code];
            $ledgers = DB::table('commission_ledger')->where('acquisition_person_id',$other->id)->get();
            foreach ($ledgers as $ledger) {
                $effective = $person->beneficiary_id ? ['beneficiary_type'=>$person->beneficiary_type,'beneficiary_id'=>$person->beneficiary_id] : $candidate;
                $rival = DB::table('commission_ledger')->where('acquisition_person_id',$person->id)->exists();
                if (!$rival && $effective && $effective['beneficiary_type'] === $ledger->beneficiary_type && $effective['beneficiary_id'] === $ledger->beneficiary_id) continue;
                $before = $ledger;
                DB::table('commission_ledger')->where('id',$ledger->id)->update(['eligible'=>false,'review_required'=>true,'updated_at'=>now(),'version'=>$ledger->version+1]);
                app(\App\Services\Commissions\CommissionEngine::class)->audit($before,DB::table('commission_ledger')->find($ledger->id),'identity_review',null,'Identidad de compra vinculada; revisar beneficiario original y evitar duplicar captación.');
            }
            DB::table('acquisition_aliases')->where('person_id',$other->id)->update(['person_id'=>$person->id]);
            DB::table('acquisition_activations')->where('person_id',$other->id)->update(['person_id'=>$person->id]);
        }
        foreach ($refs as $kind=>$ref) DB::table('acquisition_aliases')->insertOrIgnore(['kind'=>$kind,'reference'=>$ref,'person_id'=>$person->id]);
        if (!$person->beneficiary_id && $candidate && $candidate['beneficiary_id'] !== $user?->uuid) {
            DB::table('acquisition_people')->where('id',$person->id)->update([...$candidate,'fixed_at'=>now()]);
        }
        return DB::table('acquisition_people')->find($person->id);
    }

    public function replay(): void
    {
        DB::table('acquisition_facts')->orderBy('id')->chunkById(100, function($facts): void {
            foreach ($facts as $fact) DB::transaction(function() use($fact): void {
                $event = DB::table('commercial_events')->find($fact->event_id);
                $this->event($event,json_decode($event->raw_body,true,32,JSON_THROW_ON_ERROR));
            },3);
        });
    }

    public function register(User $user, Request $request): void
    {
        $token = $request->session()->get('com_origin');
        $this->person(['user'=>$user->uuid], $this->resolve(is_string($token) ? $this->token($token) : null));
    }

    public function purchase(\App\Models\MembershipPurchase $purchase, Request $request): void
    {
        $token = $request->session()->get('com_origin');
        $referrer = app(ReferralAttribution::class)->current($request);
        $candidate = $referrer ? ['beneficiary_type'=>'INDIVIDUAL','beneficiary_id'=>$referrer->uuid,'referral_code'=>$referrer->referral_code] : $this->resolve(is_string($token) ? $this->token($token) : null);
        $this->person(['purchase'=>$purchase->id,'user'=>$purchase->intent['club_user_uuid']],$candidate);
    }

    public function event(object $event, array $data): void
    {
        if (!in_array($event->event_type, ['member.registered','membership.attribution_fixed','membership.attribution_identity_linked','membership.activated','membership.identity_linked'], true)) return;
        $purchase = isset($data['club_reference']) ? \App\Models\MembershipPurchase::find($data['club_reference']) : null;
        $refs = ['com_user'=>$event->event_type === 'member.registered' ? $data['com_reference']['id'] : null,'user'=>$data['club_user_uuid'] ?? null,'subject'=>$data['global_subject_id'] ?? null,'payment'=>$data['payment_reference'] ?? null,'purchase'=>$data['club_reference'] ?? null];
        if ($purchase && !empty($purchase->intent['club_user_uuid'])) abort_unless(($data['club_user_uuid'] ?? null) === $purchase->intent['club_user_uuid'],409);
        if ($purchase?->user_id) $refs['user'] = User::find($purchase->user_id)?->uuid;
        $candidate = $this->resolve($data['acquisition'] ?? null);
        $person = $this->person($refs,$candidate);
        DB::table('acquisition_facts')->insertOrIgnore(['event_id'=>$event->id,'payment_reference'=>$data['payment_reference'] ?? null,'club_reference'=>$data['club_reference'] ?? null,'club_user_uuid'=>$data['club_user_uuid'] ?? null,'global_subject_id'=>$data['global_subject_id'] ?? null,'acquisition'=>isset($data['acquisition']) ? json_encode($data['acquisition'], JSON_THROW_ON_ERROR) : null]);
        if ($event->event_type === 'membership.activated') {
            $prior = DB::table('acquisition_activations')->where('membership_reference',$data['membership_reference'])->first();
            abort_unless(!$prior || $prior->person_id === $person->id,409);
            DB::table('acquisition_activations')->insertOrIgnore(['membership_reference'=>$data['membership_reference'],'person_id'=>$person->id,'event_id'=>$event->id,'activated_at'=>$event->occurred_at]);
        }
        // Identity and late attribution can complete an earlier activation, never add one.
        $activation = DB::table('acquisition_activations')->where('person_id',$person->id)->orderBy('activated_at')->orderBy('id')->first();
        if ($activation) app(\App\Services\Commissions\CommissionEngine::class)->recordMembership($person,$activation);
    }

}
