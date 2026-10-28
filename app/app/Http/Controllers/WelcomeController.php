<?php

namespace App\Http\Controllers;

use App\Services\ReferralAttribution;
use App\Support\LaunchSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WelcomeController extends Controller
{
    public function __invoke(Request $request, ReferralAttribution $referrals): Response
    {
        return Inertia::render('Welcome', [
            'launch' => LaunchSettings::publicData(),
            'referral_code' => $referrals->current($request)?->referral_code,
            'referral_preference' => $request->cookie('jakawi_referral_consent'),
        ]);
    }
}
