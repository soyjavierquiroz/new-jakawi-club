<?php

namespace App\Http\Controllers;

use App\Services\Identity\IdentityFailure;
use App\Services\Identity\IdentityLink;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MembershipCheckoutController extends Controller
{
    public function __invoke(Request $request, IdentityLink $identity): Response
    {
        try {
            $state = $identity->refresh($request->user());
            if (! $state['connected'] || ! $request->user()->fresh()->global_subject_id) {
                // Reuse explicit consent, email evidence and authenticated COM linking.
                // Provisioning a mapping does not establish a COM browser session.
                return app(IdentityController::class)->activate($request, $identity);
            }
            $user = $request->user()->fresh();
            return app(JakawiLoginController::class)->start($request, [
                'club_user_id' => $user->uuid, 'subject' => $user->global_subject_id,
            ]);
        } catch (IdentityFailure) {
            return redirect()->route('dashboard')->with('status', 'No pudimos verificar tu conexión con JAKAWI.COM. Vuelve a intentarlo desde tu cuenta.');
        }
    }
}
