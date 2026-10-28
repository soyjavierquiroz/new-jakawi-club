<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class ReferralAttribution
{
    public function valid(mixed $code): ?User
    {
        if (! is_string($code) || ! preg_match('/^[A-Z0-9]{12}$/', $code)) {
            return null;
        }

        return User::where('referral_code', $code)->first();
    }

    public function current(Request $request, mixed $fallback = null): ?User
    {
        if ($request->user()) {
            return null;
        }

        // Only opt-in visitors can restore attribution from the optional cookie.
        $cookie = $request->cookie('jakawi_referral_consent') === 'yes'
            ? $request->cookie('jakawi_referral') : null;

        return $this->valid($request->session()->get('referral_code'))
            ?? $this->valid($cookie)
            ?? $this->valid($fallback);
    }

    public function capture(Request $request, string $code): void
    {
        if ($request->user()) {
            return;
        }

        $referrer = $this->current($request, $code);
        if (! $referrer) {
            return;
        }
        $request->session()->put('referral_code', $referrer->referral_code);
        if ($request->cookie('jakawi_referral_consent') === 'yes') {
            $this->remember($referrer->referral_code);
        }
    }

    public function remember(string $code): void
    {
        Cookie::queue(cookie('jakawi_referral', $code, 60 * 24 * 30, '/', null, true, true, false, 'lax'));
    }

    public function forget(Request $request): void
    {
        $request->session()->forget('referral_code');
        Cookie::queue(Cookie::forget('jakawi_referral'));
    }
}
