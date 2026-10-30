<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Identity\IdentityLink;
use App\Services\Identity\JakawiOidc;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class JakawiLoginController extends Controller
{
    public function start(Request $request): Response
    {
        try {
            $client = new JakawiOidc;
            $url = $client->begin();
            $request->session()->regenerate(true);
            $request->session()->put('jakawi_oidc', Crypt::encryptString(json_encode([
                'expires_at' => time() + 600, 'values' => $client->transaction,
            ], JSON_THROW_ON_ERROR)));
            return $request->header('X-Inertia') ? Inertia::location($url) : redirect()->away($url);
        } catch (\Throwable) {
            return $this->failure();
        }
    }

    public function callback(Request $request, IdentityLink $identity): RedirectResponse
    {
        try {
            // The route session lock serializes callbacks. Persist consumption before any network call.
            $encrypted = $request->session()->pull('jakawi_oidc');
            $request->session()->save();
            if (! is_string($encrypted)) return $this->failure();
            $pending = json_decode(Crypt::decryptString($encrypted), true, 16, JSON_THROW_ON_ERROR);
            $state = $request->query('state');
            $values = $pending['values'];
            if (! is_int($pending['expires_at']) || $pending['expires_at'] < time()
                || ! is_string($state) || ! is_string($values['openid_connect_state'] ?? null)
                || ! hash_equals($values['openid_connect_state'], $state)) return $this->failure();
            if ($request->query('error') !== null) return $this->failure();
            $code = $request->query('code');
            if (! is_string($code) || $code === '' || strlen($code) > 8192) return $this->failure();
            $subject = (new JakawiOidc)->complete($values, $code, $state);
            // Identity is resolved solely by the signed subject, never by email/profile claims.
            $user = User::where('global_subject_id', $subject)->first();
            if (! $user) {
                return redirect()->route('login')->with('jakawi_link_required', true)->with('status',
                    'Tu cuenta JAKAWI aún no está conectada a CLUB. Ingresa con tu acceso CLUB y usa «ACTIVAR MI ACCESO A JAKAWI.COM» en el dashboard. Después podrás ingresar con JAKAWI.');
            }
            if (! $user->activated_at || ! $user->hasVerifiedEmail()) return $this->failure();
            // Reuse the authenticated Phase 03 lookup, including revocation and correlation checks.
            if (! $identity->refresh($user)['connected'] || $user->fresh()->global_subject_id !== $subject) return $this->failure();
            $user->refresh();
            if (! $user->activated_at || ! $user->hasVerifiedEmail()) return $this->failure();
            Auth::login($user);
            $request->session()->forget('jakawi_link_required');
            $request->session()->regenerate(true);
            // A fixed local destination also strips the authorization code from subsequent URLs.
            return redirect()->route('dashboard');
        } catch (\Throwable) {
            // Provider messages, codes, tokens and HTTP exception bodies must never enter logs/UI.
            return $this->failure();
        }
    }

    private function failure(): RedirectResponse
    {
        return redirect()->route('login')->with('status',
            'No pudimos completar el acceso con JAKAWI. Vuelve a intentarlo o ingresa con tu acceso CLUB.');
    }
}
