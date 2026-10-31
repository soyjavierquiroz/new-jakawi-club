<?php
namespace App\Services;
use App\Models\MembershipPurchase;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
final class PublicMembershipCheckout {
    public function current(Request $request): ?MembershipPurchase {
        $reference = $request->session()->get('membership_purchase');
        $purchase = is_string($reference) ? MembershipPurchase::find($reference) : null;
        // Session possession grants access only to this purchase, never account access.
        if ($purchase && (!$request->user() || $purchase->user_id === $request->user()->id
            || (!$purchase->user_id && hash_equals($purchase->intent['email'], $request->user()->email)))) return $purchase;
        if (!$request->user()) return null;
        $order = static fn ($query) => $query->whereIn('status', ['approved', 'under_review', 'awaiting_payment', 'rejected'])
            ->orderByRaw("CASE status WHEN 'approved' THEN 0 WHEN 'under_review' THEN 1 ELSE 2 END")->orderByDesc('created_at');
        $owned = $order(MembershipPurchase::where('user_id', $request->user()->id))->first();
        if ($owned || !$request->user()->hasVerifiedEmail()) return $owned;
        // Recover across devices only after account authentication AND verified email.
        return $order(MembershipPurchase::whereNull('user_id')->where('buyer_email_digest',
            hash_hmac('sha256', $request->user()->email, config('app.key'))))->first();
    }
    public function create(Request $request, array $data): MembershipPurchase {
        $purchase = $this->current($request);
        if ($purchase) return $purchase;
        $purchase = DB::transaction(function () use ($request, $data): MembershipPurchase {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['membership:'.$data['email']]);
            if ($existing = $this->current($request)) return $existing;
            $user = $request->user();
            if (!$user && !User::where('email', $data['email'])->exists()) {
                $user = new User($data);
                $referrer = app(ReferralAttribution::class)->current($request);
                if ($referrer && $referrer->email !== $data['email']) $user->referred_by_id = $referrer->id;
                $landing = \App\Models\MarketingLanding::where('slug', 'membresia')->first();
                $user->registration_origin = ['landing_id' => $landing?->id, 'landing_slug' => 'membresia',
                    'campaign' => $landing?->published_campaign, 'utms' => $request->session()->get('registration_utms.'.$landing?->id, [])];
                $user->save();
                app(\App\Services\Crm\ClubContact::class)->enqueue($user);
                app(MarketingAnalytics::class)->record('registration_completed', 'form', $landing);
            }
            $id = (string) Str::uuid();
            return MembershipPurchase::create(['id' => $id, 'user_id' => $user?->id, 'buyer_email_digest' => hash_hmac('sha256', $data['email'], config('app.key')), 'intent' => [
                'club_reference' => $id, 'club_user_uuid' => $user?->uuid,
                'guest_purchase_id' => $user ? null : (string) Str::uuid(),
                'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'],
                'amount' => '100.00', 'currency' => 'BOB', 'origin' => 'CLUB', 'claim_secret' => bin2hex(random_bytes(32)),
            ]]);
        }, 3);
        $request->session()->put('membership_purchase', $purchase->id);
        return $purchase;
    }
    public function sync(MembershipPurchase $purchase): MembershipPurchase {
        $client = app(ClubPurchaseClient::class);
        $response = $purchase->payment_reference
            ? $client->request('GET', '/'.$purchase->id)
            : $client->request('POST', '', $purchase->intent);
        $data = $response->json();
        if (!is_array($data) || ($data['contract'] ?? null) !== 'jakawi-club-purchases-v1' || ($data['club_reference'] ?? null) !== $purchase->id
            || ($data['amount'] ?? null) !== '100.00' || ($data['currency'] ?? null) !== 'BOB'
            || !Str::isUuid($data['payment_reference'] ?? '') || !in_array($data['status'] ?? '', ['awaiting_payment', 'under_review', 'approved', 'rejected'], true)) {
            throw new \RuntimeException('Invalid purchase response');
        }
        return $this->apply($purchase, $data);
    }
    public function apply(MembershipPurchase $purchase, array $data): MembershipPurchase {
        return DB::transaction(function () use ($purchase, $data): MembershipPurchase {
            $locked = MembershipPurchase::lockForUpdate()->findOrFail($purchase->id);
            if (!in_array($data['status'] ?? '', ['awaiting_payment', 'under_review', 'approved', 'rejected'], true)) throw new \RuntimeException('Invalid purchase state');
            if ($data['status'] === 'approved' && (empty($data['activated_at']) || empty($data['membership_reference']))) throw new \RuntimeException('Activation confirmation incomplete');
            if ($locked->global_subject_id && !empty($data['global_subject_id']) && $locked->global_subject_id !== $data['global_subject_id']) throw new \RuntimeException('Purchase identity mismatch');
            if ($locked->payment_reference && $locked->payment_reference !== $data['payment_reference']) throw new \RuntimeException('Purchase reference mismatch');
            // A late HTTP response/event cannot undo activation or a more specific link.
            $approved = ($data['status'] ?? '') === 'approved' && !empty($data['activated_at']) && !empty($data['membership_reference']);
            $locked->fill(['payment_reference' => $data['payment_reference'], 'qr_ready' => (bool) ($data['qr_ready'] ?? $locked->qr_ready)]);
            if (!$locked->activated_at && !($locked->status === 'under_review' && $data['status'] === 'awaiting_payment')) $locked->status = $approved ? 'approved' : ($data['status'] === 'approved' ? $locked->status : $data['status']);
            if ($approved) $locked->fill(['activated_at' => $data['activated_at'], 'membership_reference' => $data['membership_reference']]);
            if (!empty($data['receipt_sha256'])) $locked->receipt_sha256 = $data['receipt_sha256'];
            if (($data['identity_status'] ?? '') === 'linked' && !empty($data['global_subject_id'])) $locked->fill(['identity_status' => 'linked', 'global_subject_id' => $data['global_subject_id']]);
            elseif ($locked->identity_status !== 'linked') $locked->identity_status = $data['identity_status'] ?? $locked->identity_status;
            // COM creates its event ID; CLUB records no duplicate browser/CAPI checkout.
            if (!$locked->checkout_recorded_at) {
                app(MarketingAnalytics::class)->record('InitiateCheckout', 'checkout', \App\Models\MarketingLanding::where('slug', 'membresia')->first());
                $locked->checkout_recorded_at = now();
            }
            $locked->save(); return $locked;
        });
    }
}
