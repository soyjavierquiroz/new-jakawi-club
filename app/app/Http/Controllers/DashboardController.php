<?php

namespace App\Http\Controllers;

use App\Support\LaunchSettings;
use App\Support\Normalize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        $checkout = app(\App\Services\PublicMembershipCheckout::class);
        $purchase = $checkout->forBenefits($request);
        if ($purchase && !$request->isMethod('HEAD')) {
            try { $purchase = $checkout->refresh($purchase); }
            catch (\RuntimeException) { /* Retain the last authorized COM state. */ }
        }

        $membership = app(\App\Services\ComMembershipStatus::class)->forUser($user);

        return Inertia::render('Dashboard', [
            'com_membership' => $membership,
            'email_verified' => $user->hasVerifiedEmail(),
            'membership_purchase' => $purchase ? ['needs_access_consent' => !$purchase->user_id && $purchase->identity_status !== 'linked', 'receipt_pending' => $purchase->receipt_pending && !$purchase->activated_at,
                'receipt_received' => $purchase->receipt_received || (bool) $purchase->receipt_sha256,
                'status' => $purchase->status] : null,
            'membership_activated' => $membership !== null ? ($membership['is_active'] && ($membership['benefits']['exclusive_member_benefits'] ?? false)) : ((bool) $purchase?->activated_at || app(\App\Services\MemberActivation::class)->confirmed($user)),
            'membership_continue' => \App\Support\MembershipJourney::pending($request),
            'member' => $user->only('name', 'email', 'phone', 'city'),
            'can_access_affiliates' => $user->activated_at !== null && $user->hasVerifiedEmail(),
            'referral_url' => $user->referralUrl(),
            'invitees_count' => $user->invitees()->count(),
            'launch' => LaunchSettings::publicData(true),
            'identity' => app(\App\Services\Identity\IdentityLink::class)->state($user),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->merge([
            'name' => Normalize::name($request->input('name')),
            'phone' => Normalize::phone($request->input('phone')),
            'city' => Normalize::name($request->input('city')) ?: null,
        ]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'city' => ['nullable', 'string', 'max:100'],
        ], [
            'name.*' => 'Escribe tu nombre (hasta 120 caracteres).',
            'phone.*' => 'Incluye el prefijo de tu país y un teléfono válido.',
            'city.*' => 'La ciudad admite hasta 100 caracteres.',
        ]);
        $request->user()->update($data);

        return back()->with('status', 'Perfil actualizado.');
    }
}
