<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class AffiliateResults
{
    public function summary(User $user): array
    {
        // Resolve persisted pending events at read time. The immutable user FK is the
        // attribution authority, even when identity linking follows delivery.
        $leads = DB::table('users')->where('referred_by_id', $user->id)->count();
        $activated = DB::table('users as lead')->where('lead.referred_by_id', $user->id)
            ->whereExists(function (Builder $query): void {
                $query->selectRaw('1')->from('commercial_events as event')
                    ->where('event.source', 'jakawi.com')->where('event.event_type', 'membership.activated')
                    ->where('event.conversion_status', 'activated')->where('event.com_reference_type', 'membership')
                    ->whereColumn('event.global_subject_id', 'lead.global_subject_id');
            })->count();
        return [
            'leads_captured' => $leads,
            'memberships_activated' => $activated,
            'activation_rate' => $leads ? round($activated * 100 / $leads, 1) : 0,

            'clicks' => app(AffiliateLinkClicks::class)->summary($user),
            // Existing permanent, individual FK written by CLUB registration.
            // No email matching or inferred COM registrations.
            'club_registrations' => $leads,
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
