<?php

namespace App\Http\Controllers;

use App\Services\Identity\IdentityFailure;
use App\Services\MemberActivation;
use App\Services\ReferralAttribution;
use App\Support\MembershipJourney;
use App\Services\Identity\IdentityLink;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MembershipCheckoutController extends Controller
{
    public function entry(Request $request, IdentityLink $identity, ReferralAttribution $referrals): Response
    {
        if ($request->isMethod('HEAD')) return redirect()->route($request->user() ? 'dashboard' : 'login');
        MembershipJourney::remember($request);
        if (! $request->user()) {
            $code = $request->query('ref', $request->query('club_ref'));
            if (is_string($code)) $referrals->capture($request, $code);
            return redirect()->route('login');
        }
        abort_unless($request->user()->activated_at && $request->user()->hasVerifiedEmail(), 403);
        return $this->__invoke($request, $identity);
    }

    public function __invoke(Request $request, IdentityLink $identity): Response
    {
        if (app(MemberActivation::class)->confirmed($request->user())) {
            MembershipJourney::forget($request);
            return redirect()->route('dashboard');
        }
        try {
            $state = $identity->refresh($request->user());
            if (! $state['connected'] || ! $request->user()->fresh()->global_subject_id) {
                // Reuse explicit consent, email evidence and authenticated COM linking.
                // Provisioning a mapping does not establish a COM browser session.
                $response = app(IdentityController::class)->activate($request, $identity);
                if (! MembershipJourney::pending($request) || ! $identity->state($request->user()->fresh())['connected']) return $response;
            }
            $user = $request->user()->fresh();
            if (app(MemberActivation::class)->confirmed($user)) {
                MembershipJourney::forget($request);
                return redirect()->route('dashboard');
            }
            return app(JakawiLoginController::class)->start($request, [
                'club_user_id' => $user->uuid, 'subject' => $user->global_subject_id,
            ]);
        } catch (IdentityFailure) {
            return redirect()->route('dashboard')->with('status', 'No pudimos verificar tu conexión con JAKAWI.COM. Vuelve a intentarlo desde tu cuenta.');
        }
    }
}
