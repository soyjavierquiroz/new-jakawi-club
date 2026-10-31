<?php

namespace App\Support;

use Illuminate\Http\Request;

final class MembershipJourney
{
    public static function remember(Request $request): void
    {
        $request->session()->put('membership_continue_until', now()->addHours(2)->timestamp);
    }

    public static function pending(Request $request): bool
    {
        return $request->hasSession() && (int) $request->session()->get('membership_continue_until', 0) > now()->timestamp;
    }

    public static function forget(Request $request): void
    {
        $request->session()->forget('membership_continue_until');
    }
}
