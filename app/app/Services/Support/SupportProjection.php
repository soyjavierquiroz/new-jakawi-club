<?php

namespace App\Services\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Allowlisted presentation only. No checkout refresh, capability, identity handoff or economic mutation. */
final class SupportProjection
{
    public function forUser(User $target): array
    {
        return DB::transaction(function () use ($target): array {
            DB::statement('SET TRANSACTION READ ONLY');
            $affiliate = $target->activated_at !== null && $target->hasVerifiedEmail();
            $purchases = DB::table('membership_purchases')->where(function ($q) use ($target): void {
                $q->where('user_id', $target->id);
                if ($target->global_subject_id) $q->orWhere(fn ($guest) => $guest->whereNull('user_id')->where('identity_status', 'linked')->where('global_subject_id', $target->global_subject_id));
            })->orderByDesc('created_at')->paginate(25, ['id', 'status', 'activated_at', 'reported_at', 'receipt_pending', 'receipt_received', 'created_at'], 'purchases_page')->withQueryString();
            $membership = app(\App\Services\ComMembershipStatus::class)->forUser($target);
            $data = ['membership' => $membership, 'verified' => $target->hasVerifiedEmail(), 'active' => (bool) $target->activated_at,
                'affiliate_allowed' => $affiliate, 'purchases' => $purchases, 'referrals_count' => $target->invitees()->count(),
                'identity_message' => app(\App\Services\Identity\IdentityLink::class)->state($target)['message'] ?? 'Conexión deshabilitada.',
                'activation_confirmed' => app(\App\Services\MemberActivation::class)->confirmed($target),
                'links' => null, 'results' => null, 'commissions' => null, 'conversions' => null, 'partners' => [], 'economics' => null];
            if (!$affiliate) return $data;
            $data['links'] = DB::table('affiliate_short_links as links')->join('commercial_offers as offers', 'offers.key', '=', 'links.offer_key')
                ->where('links.affiliate_id', $target->id)->orderByDesc('links.created_at')->paginate(25, ['offers.name', 'links.code', 'links.created_at'], 'links_page')->withQueryString();
            $data['results'] = app(\App\Services\AffiliateResults::class)->summary($target);
            $data['economics'] = app(\App\Services\Commissions\CommissionEngine::class)->summary($target);
            $data['commissions'] = DB::table('commission_ledger')->where('affiliate_uuid', $target->uuid)->where('affiliate_code', $target->referral_code)
                ->orderByDesc('id')->paginate(25, ['id', 'status', 'amount', 'currency', 'review_required', 'eligible', 'created_at', 'approved_at'], 'commission_page')->withQueryString();
            $data['conversions'] = app(\App\Services\AffiliateConversions::class)->confirmed($target)->orderByDesc('occurred_at')
                ->paginate(25, ['occurred_at', 'partner_name', 'event_type', 'conversion_status'], 'conversion_page')->withQueryString();
            $partners = app(\App\Services\PartnerEconomicAccess::class)->forSupport(request(), $target);
            $data['partners'] = array_map(function (array $panel): array { unset($panel['links']); return $panel; }, \App\Http\Controllers\PartnerAffiliateController::panels($target, $partners));
            return $data;
        });
    }
}
