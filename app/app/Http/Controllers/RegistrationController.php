<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegistrationRequest;
use App\Models\User;
use App\Services\AccountMail;
use App\Services\MarketingAnalytics;
use App\Services\ReferralAttribution;
use App\Support\LaunchSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationController extends Controller
{
    public function store(RegistrationRequest $request, ReferralAttribution $referrals, AccountMail $mail, MarketingAnalytics $analytics): RedirectResponse
    {
        $data = $request->validated();
        $referrer = $referrals->current($request, $data['referral_code'] ?? null);
        $created = false;
        try {
            DB::transaction(function () use ($data, $referrer, $analytics, &$created): void {
                if (User::where('email', $data['email'])->exists()) {
                    return;
                }
                $user = new User([
                    'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'],
                ]);
                if ($referrer && $referrer->email !== $data['email']) {
                    $user->referred_by_id = $referrer->id;
                }
                $user->privacy_accepted_at = now();
                $user->privacy_version = config('jakawi.privacy_version');
                $user->save();
                // Confirmed server-side, in the same transaction as the new account.
                $analytics->record('registration_completed', 'form');
                $created = true;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // The database also handles two concurrent requests for the same email.
            if (! User::where('email', $data['email'])->exists()) {
                throw $exception;
            }
        }
        if ($created) {
            $mail->send($data['email']);
        }
        $request->session()->regenerate();
        $request->session()->put('welcome_expires_at', now()->addMinutes(15)->timestamp);
        $referrals->forget($request);

        // Identical receipt for new and existing accounts. No email, name or code leaks.
        return redirect()->route('welcome');
    }

    public function welcome(Request $request): Response|RedirectResponse
    {
        if ($request->session()->get('welcome_expires_at', 0) <= now()->timestamp) {
            $request->session()->forget('welcome_expires_at');

            return redirect()->route('home');
        }
        $user = $request->user();

        return Inertia::render('Registered', [
            'referral_url' => $user?->hasVerifiedEmail() && $user->activated_at ? $user->referralUrl() : null,
            'launch' => LaunchSettings::publicData((bool) $user?->hasVerifiedEmail()),
        ]);
    }
}
