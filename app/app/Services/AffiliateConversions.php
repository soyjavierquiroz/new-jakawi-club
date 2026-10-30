<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class AffiliateConversions
{
    public function confirmed(User $user): Builder
    {
        // Rank before filtering attribution/status: a later cancellation or a
        // differently attributed event cannot leave an older sale counted.
        // Business time wins over delivery time, including out-of-order retries.
        $latest = DB::table('commercial_events')
            ->where('source', 'jakawi.com')
            ->select(['occurred_at', 'partner_name', 'partner_id', 'event_type', 'conversion_status',
                'com_reference_type', 'com_reference_id', 'club_referral_code', 'referrer_club_uuid'])
            ->selectRaw('row_number() over (partition by source, com_reference_type, com_reference_id order by occurred_at desc, received_at desc, id desc) as operation_row');

        return DB::query()->fromSub($latest, 'operations')
            ->where('operation_row', 1)
            ->where('conversion_status', 'confirmed')
            ->where('club_referral_code', $user->referral_code)
            ->where('referrer_club_uuid', $user->uuid);
    }
}
