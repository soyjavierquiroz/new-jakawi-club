<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\ConfirmIdentityEmail;
use App\Services\Identity\ActivationEvidence;
use App\Services\Identity\IdentityFailure;
use App\Services\Identity\IdentityLink;
use App\Support\LaunchSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

final class IdentityController extends Controller
{
    private function user(Request $request): User
    {
        abort_unless(config('identity.enabled'), 503);
        abort_unless($request->user()?->activated_at && $request->user()?->hasVerifiedEmail(), 403);
        return $request->user();
    }

    public function activate(Request $request, IdentityLink $identity): \Symfony\Component\HttpFoundation\Response
    {
        $user = $this->user($request);
        try {
            $state = $identity->activate($user);
            if ($state['authorization_url']) {
                return $request->header('X-Inertia') ? Inertia::location($state['authorization_url']) : redirect()->away($state['authorization_url']);
            }
            if ($state['needs_email_confirmation'] && $state['can_activate']) {
                if (! LaunchSettings::mailReady()) throw new IdentityFailure('MAIL_UNAVAILABLE');
                try {
                    $result = Password::broker('identity_activation')->sendResetLink(['email' => $user->email], function (User $recipient, string $token): void {
                        $recipient->notify(new ConfirmIdentityEmail($token));
                    });
                } catch (\Throwable) { throw new IdentityFailure('MAIL_UNAVAILABLE'); }
                if (! in_array($result, [Password::RESET_LINK_SENT, Password::RESET_THROTTLED], true)) throw new IdentityFailure('MAIL_UNAVAILABLE');
                return redirect()->route('dashboard')->with('status', 'Te enviamos un enlace para confirmar tu correo. Después podrás activar tu acceso a JAKAWI.COM.');
            }
            return redirect()->route('dashboard')->with('status', $state['message']);
        } catch (IdentityFailure) {
            return redirect()->route('dashboard')->with('status', 'No pudimos completar la conexión con JAKAWI.COM. Tu cuenta CLUB sigue disponible; puedes volver a intentarlo.');
        }
    }

    public function status(Request $request, IdentityLink $identity): JsonResponse
    {
        $user = $this->user($request);
        try {
            return response()->json($identity->refresh($user))->header('Cache-Control', 'no-store, private');
        } catch (IdentityFailure $failure) {
            return response()->json(['error' => 'IDENTITY_UNAVAILABLE'], 503)
                ->header('Retry-After', (string) $failure->retryAfter)->header('Cache-Control', 'no-store, private');
        }
    }

    public function confirmation(Request $request, string $token): Response
    {
        $user = $this->user($request);
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token) && Password::broker('identity_activation')->tokenExists($user, $token), 410);
        return Inertia::render('IdentityEmailConfirmation', ['token' => $token]);
    }

    public function confirm(Request $request, ActivationEvidence $evidence): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);
        DB::transaction(function () use ($user, $data, $evidence): void {
            // Same lock order as native account changes: broker row, then user.
            DB::table('club_identity_activation_tokens')->where('email', $user->email)->lockForUpdate()->first();
            $current = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->email === $user->email && $current->activated_at && $current->hasVerifiedEmail()
                && Password::broker('identity_activation')->tokenExists($current, $data['token']), 410);
            Password::broker('identity_activation')->deleteToken($current);
            $evidence->record($current);
        });
        return redirect()->route('dashboard')->with('status', 'Correo confirmado. Ya puedes activar tu acceso a JAKAWI.COM.');
    }
}
