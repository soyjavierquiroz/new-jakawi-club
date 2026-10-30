<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AffiliateResults
{
    public function summary(User $user): array
    {
        return [
            'clicks' => app(AffiliateLinkClicks::class)->summary($user),
            // Existing permanent, individual FK written by CLUB registration.
            // No email matching or inferred COM registrations.
            'club_registrations' => DB::table('users')->where('referred_by_id', $user->id)->count(),
            // Other COM operations remain visible in the conversions list.
            'confirmed_redemptions' => app(AffiliateConversions::class)->confirmed($user)->where('com_reference_type', 'redemption')->count(),
            // Same scope and approved/review policy as the existing ledger summary.
            'approved_commissions' => DB::table('commission_ledger')
                ->where('affiliate_uuid', $user->uuid)->where('affiliate_code', $user->referral_code)
                ->where('status', 'approved')->where('review_required', false)->whereNotNull('currency')
                ->groupBy('currency')->orderBy('currency')->selectRaw('currency, sum(amount)::text AS amount')->get()->all(),
        ];
    }
}
