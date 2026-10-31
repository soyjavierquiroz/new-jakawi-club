<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class MemberActivation
{
    // COM v1 sends activation facts, not a live entitlement/expiry API.
    public function confirmed(User $user): bool
    {
        return \App\Models\MembershipPurchase::where('user_id', $user->id)->whereNotNull('activated_at')->exists()
            || ($user->global_subject_id !== null && DB::table('commercial_events')
            ->where('source', 'jakawi.com')->where('event_type', 'membership.activated')
            ->where('com_reference_type', 'membership')->where('conversion_status', 'activated')
            ->where('global_subject_id', $user->global_subject_id)->exists());
    }
}
