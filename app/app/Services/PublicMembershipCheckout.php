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
            || (!$purchase->user_id && $purchase->identity_status === 'linked' && $purchase->global_subject_id && $purchase->global_subject_id === $request->user()->global_subject_id))) return $purchase;
        if (!$request->user()) return null;
        $order = static fn ($query) => $query->whereIn('status', ['approved', 'under_review', 'PAYMENT_REPORTED', 'awaiting_payment', 'rejected'])
            ->orderByRaw("CASE status WHEN 'approved' THEN 0 WHEN 'under_review' THEN 1 WHEN 'PAYMENT_REPORTED' THEN 2 ELSE 3 END")->orderByDesc('created_at');
        $owned = $order(MembershipPurchase::where(function ($query) use ($request): void {
            $query->where('user_id', $request->user()->id);
            if ($request->user()->global_subject_id) {
                $query->orWhere(fn ($guest) => $guest->whereNull('user_id')->where('identity_status', 'linked')
                    ->where('global_subject_id', $request->user()->global_subject_id));
            }
        }))->first();
        return $owned;
    }
    public function forBenefits(Request $request): ?MembershipPurchase {
        if ($purchase = $this->current($request)) return $purchase;
        // Only the original guest capability can offer an explicit claim. This does
        // not attach an account and must never be used by checkout creation/reporting.
        $reference = $request->session()->get('membership_purchase');
        $guest = is_string($reference) ? MembershipPurchase::find($reference) : null;
        return $request->user() && $guest && !$guest->user_id && empty($guest->intent['club_user_uuid'])
            && $guest->identity_status !== 'linked' && $guest->activated_at ? $guest : null;
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
                app(PartnerAcquisition::class)->register($user, $request);
                app(\App\Services\Crm\ClubContact::class)->enqueue($user);
                app(MarketingAnalytics::class)->record('registration_completed', 'form', $landing);
            }
            $id = (string) Str::uuid();
            $purchase = MembershipPurchase::create(['ad_visit_id' => app(Advertising::class)->safely(fn () => app(Advertising::class)->visit($request)?->id), 'id' => $id, 'user_id' => $user?->id, 'buyer_email_digest' => hash_hmac('sha256', $data['email'], config('app.key')), 'intent' => [
                'com_origin' => $request->session()->get('com_origin'),
                'original_club_referral_code' => $user?->referred_by_id ? User::find($user->referred_by_id)?->referral_code : app(ReferralAttribution::class)->current($request)?->referral_code,
                'club_reference' => $id, 'club_user_uuid' => $user?->uuid,
                'guest_purchase_id' => $user ? null : (string) Str::uuid(),
                'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'],
                'amount' => '100.00', 'currency' => 'BOB', 'origin' => 'CLUB', 'claim_secret' => bin2hex(random_bytes(32)),
            ]]);
            app(PartnerAcquisition::class)->purchase($purchase, $request);
            app(Advertising::class)->safely(function () use ($purchase): void {
                app(Advertising::class)->record($purchase, 'Lead');
                app(Advertising::class)->record($purchase, 'InitiateCheckout');
            });
            return $purchase;
        }, 3);
        $request->session()->put('membership_purchase', $purchase->id);
        return $purchase;
    }
    public function sync(MembershipPurchase $purchase): MembershipPurchase {
        // Recover an uncertain creation with GET first, using the frozen CLUB reference.
        try {
            return $this->refresh($purchase);
        } catch (ClubPurchaseFailure $failure) {
            if ($purchase->payment_reference || $failure->status !== 404) throw $failure;
        }
        try {
            $response = app(ClubPurchaseClient::class)->request('POST', '', $purchase->intent, attempts: 1);
        } catch (ClubPurchaseFailure $failure) {
            if ($failure->status !== null && $failure->status < 500) throw $failure;
            return $this->refresh($purchase);
        }
        return $this->accept($purchase, $response->json());
    }

    public function refresh(MembershipPurchase $purchase): MembershipPurchase {
        return $this->accept($purchase, app(ClubPurchaseClient::class)->request('GET', '/'.$purchase->id)->json());
    }

    public function report(MembershipPurchase $purchase): MembershipPurchase {
        // This path only queries/reports an existing purchase. It never creates an intent.
        $purchase = $this->refresh($purchase);
        if ($purchase->activated_at || $purchase->reported_at || $purchase->payment_confirmed
            || $purchase->receipt_pending || in_array($purchase->status, ['PAYMENT_REPORTED', 'under_review'], true)) return $purchase;
        if (!$purchase->qr_ready || !$purchase->beneficiary_name) throw new ClubPurchaseFailure();
        try {
            $response = app(ClubPurchaseClient::class)->request('POST', '/'.$purchase->id.'/report', ['payment_confirmed' => true], attempts: 1);
        } catch (ClubPurchaseFailure $failure) {
            if ($failure->status !== null && $failure->status < 500) throw $failure;
            // A timeout may have committed the notice. Read COM before allowing a retry.
            $purchase = $this->refresh($purchase);
            if ($purchase->reported_at || $purchase->payment_confirmed || $purchase->activated_at || $purchase->status === 'under_review') return $purchase;
            throw $failure;
        }
        return $this->accept($purchase, $response->json());
    }

    public function accept(MembershipPurchase $purchase, mixed $data): MembershipPurchase {
        if (!is_array($data) || ($data['contract'] ?? null) !== 'jakawi-club-purchases-v1' || ($data['club_reference'] ?? null) !== $purchase->id
            || ($data['amount'] ?? null) !== '100.00' || ($data['currency'] ?? null) !== 'BOB'
            || !Str::isUuid($data['payment_reference'] ?? '') || !in_array($data['status'] ?? '', ['awaiting_payment', 'PAYMENT_REPORTED', 'under_review', 'approved', 'rejected'], true)
            || !is_string($data['beneficiary_name'] ?? null) || trim($data['beneficiary_name']) === '' || mb_strlen($data['beneficiary_name']) > 255) {
            throw new \RuntimeException('Invalid purchase response');
        }
        foreach (['payment_confirmed', 'receipt_pending', 'receipt_received'] as $field) {
            if (!is_bool($data[$field] ?? null)) throw new \RuntimeException('Invalid purchase response');
        }
        foreach (['reported_at', 'payment_confirmed_at'] as $field) {
            if (!array_key_exists($field, $data) || ($data[$field] !== null && (!is_string($data[$field]) || !strtotime($data[$field])))) throw new \RuntimeException('Invalid purchase response');
        }
        return $this->apply($purchase, $data);
    }
    public function apply(MembershipPurchase $purchase, array $data): MembershipPurchase {
        return DB::transaction(function () use ($purchase, $data): MembershipPurchase {
            $locked = MembershipPurchase::lockForUpdate()->findOrFail($purchase->id);
            if (!in_array($data['status'] ?? '', ['awaiting_payment', 'PAYMENT_REPORTED', 'under_review', 'approved', 'rejected'], true)) throw new \RuntimeException('Invalid purchase state');
            if ($data['status'] === 'approved' && (empty($data['activated_at']) || empty($data['membership_reference']))) throw new \RuntimeException('Activation confirmation incomplete');
            if ($locked->global_subject_id && !empty($data['global_subject_id']) && $locked->global_subject_id !== $data['global_subject_id']) throw new \RuntimeException('Purchase identity mismatch');
            if ($locked->payment_reference && $locked->payment_reference !== $data['payment_reference']) throw new \RuntimeException('Purchase reference mismatch');
            // A late HTTP response/event cannot undo activation or a more specific link.
            $approved = ($data['status'] ?? '') === 'approved' && !empty($data['activated_at']) && !empty($data['membership_reference']);
            $locked->fill(['payment_reference' => $data['payment_reference'], 'qr_ready' => (bool) ($data['qr_ready'] ?? $locked->qr_ready)]);
            $stale = ($locked->status === 'under_review' && in_array($data['status'], ['awaiting_payment', 'PAYMENT_REPORTED'], true))
                || ($locked->reported_at && $data['status'] === 'awaiting_payment');
            if (!$locked->activated_at && !$stale) $locked->status = $data['status'];
            if ($approved) $locked->fill(['activated_at' => $data['activated_at'], 'membership_reference' => $data['membership_reference']]);
            // COM declarations are persisted separately from bank-confirmed activation.
            if (isset($data['beneficiary_name'])) $locked->beneficiary_name = $data['beneficiary_name'];
            if (!empty($data['reported_at']) && !$locked->reported_at) $locked->reported_at = $data['reported_at'];
            if (($data['payment_confirmed'] ?? false) === true) $locked->payment_confirmed = true;
            if (!empty($data['payment_confirmed_at']) && !$locked->payment_confirmed_at) $locked->payment_confirmed_at = $data['payment_confirmed_at'];
            if (($data['receipt_received'] ?? false) === true) $locked->receipt_received = true;
            if (!$stale && array_key_exists('receipt_pending', $data)) $locked->receipt_pending = $data['receipt_pending'];
            if ($locked->activated_at || $locked->receipt_received) $locked->receipt_pending = false;
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
