<?php

namespace App\Http\Controllers;

use App\Services\ReferralAttribution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class ReferralController extends Controller
{
    public function show(Request $request, string $code, ReferralAttribution $referrals): RedirectResponse
    {
        // Invalid links do not overwrite a valid first attribution.
        $referrals->capture($request, $code);

        return redirect('/#registro')->header('Cache-Control', 'no-store');
    }

    public function preferences(Request $request, ReferralAttribution $referrals): RedirectResponse
    {
        $data = $request->validate(['allow_referral_cookie' => ['required', 'boolean']]);
        if ($data['allow_referral_cookie']) {
            if ($referrer = $referrals->current($request)) {
                $referrals->remember($referrer->referral_code);
            }
        } else {
            $referrals->forget($request);
        }
        Cookie::queue(cookie('jakawi_referral_consent', $data['allow_referral_cookie'] ? 'yes' : 'no', 60 * 24 * 30, '/', null, true, true, false, 'lax'));

        return back()->with('status', 'Preferencias de privacidad guardadas.');
    }
}
