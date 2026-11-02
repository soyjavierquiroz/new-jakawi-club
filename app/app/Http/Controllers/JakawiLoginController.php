<?php

namespace App\Http\Controllers;

use App\Support\MembershipJourney;
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
    public function start(Request $request, ?array $checkout = null, bool $partner = false): Response
    {
        try {
            $client = new JakawiOidc;
            $url = $client->begin();
            $request->session()->regenerate(true);
            $request->session()->put('jakawi_oidc', Crypt::encryptString(json_encode([
                'expires_at' => time() + 600, 'values' => $client->transaction, 'checkout' => $checkout, 'partner' => $partner,
            ], JSON_THROW_ON_ERROR)));
            return $request->header('X-Inertia') ? Inertia::location($url) : redirect()->away($url);
        } catch (\Throwable) {
            return $this->failure();
        }
    }

    public function partner(Request $request): Response
    {
        return $this->start($request, null, true);
    }

    public function callback(Request $request, IdentityLink $identity): RedirectResponse
    {
        $stage = 'transaction';
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
            if (! isset($pending['checkout']) && empty($pending['partner']) && $request->user()) return redirect()->route('dashboard');
            $stage = 'oidc_token_validation';
            $client = new JakawiOidc;
            $subject = $client->complete($values, $code, $state);
            if (!empty($pending['partner']) && $request->user() && $request->user()->global_subject_id !== $subject) return $this->failure();
            if (isset($pending['checkout'])) {
                $local = $request->user();
                if (! $local || $local->uuid !== $pending['checkout']['club_user_id']
                    || $subject !== $pending['checkout']['subject'] || $local->global_subject_id !== $subject
                    || ! $local->activated_at || ! $local->hasVerifiedEmail()
                    || ! $identity->refresh($local)['connected']) {
                    return redirect()->route('dashboard')->with('status', 'La sesión JAKAWI.COM no corresponde a tu cuenta vinculada. Cierra esa sesión en COM e inténtalo de nuevo.');
                }
                MembershipJourney::forget($request);
                if (app(\App\Services\MemberActivation::class)->confirmed($local->fresh())) return redirect()->route('dashboard');
                return redirect()->away('https://jakawi.com/membresia/checkout');
            }
            // Identity is resolved solely by the signed subject, never by email/profile claims.
            $stage = 'subject_projection';
            $user = app(\App\Services\Identity\SsoAccount::class)->resolve($subject, (array) $client->getVerifiedClaims());
            if (! $user->activated_at) return $this->failure();
            $stage = 'local_link';
            // Preserve historical CLUB link checks for ordinary login. Partner entry is
            // authorized independently by signed OIDC subject + the fresh COM permission lookup.
            if (empty($pending['partner']) && !$user->sso_provisioned_at
                && (! $identity->refresh($user, $subject)['connected'] || $user->fresh()->global_subject_id !== $subject)) return $this->failure();
            $user->refresh();
            if (! $user->activated_at) return $this->failure();
            Auth::login($user);
            $request->session()->forget('jakawi_link_required');
            $request->session()->put('jakawi_sso_subject', $subject);
            $request->session()->regenerate(true);
            if (MembershipJourney::pending($request)) return redirect()->route('membership.activate');
            if (!empty($pending['partner'])) {
                $request->session()->forget('url.intended');
                return redirect('/afiliados');
            }
            // A fixed local destination also strips the authorization code from subsequent URLs.
            return redirect()->route('dashboard');
        } catch (\Throwable) {
            // Only a bounded local stage; no exception text, identities, codes or tokens.
            \Illuminate\Support\Facades\Log::notice('CLUB OIDC callback rejected', ['stage' => $stage]);
            return $this->failure();
        }
    }

    private function failure(): RedirectResponse
    {
        return redirect()->route('login')->with('status',
            'No pudimos completar el acceso con JAKAWI. Vuelve a intentarlo o ingresa con tu acceso CLUB.');
    }
}
