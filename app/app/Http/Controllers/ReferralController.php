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
        $data = $request->validate(['allow_referral_cookie' => ['sometimes', 'boolean'], 'allow_ads' => ['sometimes', 'boolean']]);
        if (array_key_exists('allow_ads', $data)) {
            if (!$data['allow_ads']) {
                app(\App\Services\Advertising::class)->safely(fn () => \App\Models\AdVisit::whereKey($request->session()->get('ad_visit_id'))->update(['revoked_at' => now()]));
                $scope = $request->session()->get('ad_consent_scope');
                if (is_string($scope)) app(\App\Services\Advertising::class)->safely(fn () => \App\Models\AdVisit::where('consent_scope', $scope)->update(['revoked_at' => now()]));
                $request->session()->forget(['ad_visit_id', 'ad_consent_scope']);
                foreach (['_fbp','_fbc','_ttp'] as $name) Cookie::queue(Cookie::forget($name));
            }
            Cookie::queue(cookie('jakawi_ads_consent', $data['allow_ads'] ? 'yes' : 'no', 60 * 24 * config('ads.retention_days'), '/', null, true, true, false, 'lax'));
        }
        if (!array_key_exists('allow_referral_cookie', $data)) return back()->with('status', 'Preferencias de privacidad guardadas.');
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
