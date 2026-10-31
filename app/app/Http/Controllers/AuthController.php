<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountMail;
use App\Support\Normalize;
use App\Support\MembershipJourney;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function login(Request $request): Response
    {
        return Inertia::render('Auth', [
            'mode' => 'login', 'jakawiSso' => (bool) config('oidc.enabled'),
            'membershipIntent' => MembershipJourney::pending($request),
            'linkRequired' => (bool) $request->session()->get('jakawi_link_required', false),
        ]);
    }

    public function authenticate(Request $request): RedirectResponse
    {
        $request->merge(['email' => Normalize::email($request->input('email'))]);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:256'],
        ]);
        if (! Auth::attempt([
            ...$data,
            fn (Builder $query) => $query->whereNotNull('activated_at')->whereNotNull('email_verified_at'),
        ])) {
            throw ValidationException::withMessages(['email' => 'No pudimos iniciar sesión. Revisa tus datos o solicita un enlace de acceso.']);
        }
        $request->session()->regenerate();

        return MembershipJourney::pending($request) ? redirect()->route('membership.activate') : redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Inertia::clearHistory();

        return redirect()->route('home');
    }

    public function forgot(): Response
    {
        return Inertia::render('Auth', ['mode' => 'forgot']);
    }

    public function email(Request $request, AccountMail $mail): RedirectResponse
    {
        $request->merge(['email' => Normalize::email($request->input('email'))]);
        $data = $request->validate(['email' => ['required', 'email', 'max:254']]);
        $mail->send($data['email']);

        return back()->with('status', 'Si podemos atender la solicitud, recibirás un enlace por correo. Revisa también spam.');
    }

    public function activation(Request $request, string $token): Response
    {
        return $this->tokenPage($request, $token, 'activate');
    }

    public function reset(Request $request, string $token): Response
    {
        return $this->tokenPage($request, $token, 'reset');
    }

    private function tokenPage(Request $request, string $token, string $mode): Response
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $token), 404);
        if ($request->query('continue') === 'membership') MembershipJourney::remember($request);

        return Inertia::render('Auth', [
            'mode' => $mode, 'token' => $token, 'email' => Normalize::email($request->query('email')),
        ]);
    }

    public function activate(Request $request): RedirectResponse
    {
        return $this->savePassword($request, true);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        return $this->savePassword($request, false);
    }

    private function savePassword(Request $request, bool $activation): RedirectResponse
    {
        $request->merge(['email' => Normalize::email($request->input('email'))]);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'password' => [
                'required', 'string', 'confirmed', PasswordRule::min(12)->letters()->numbers(),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (strlen($value) > 72) {
                        $fail('La contraseña admite hasta 72 bytes.');
                    }
                },
            ],
        ], [
            'password.*' => 'Usa al menos 12 caracteres, letras y números, y confirma la contraseña.',
            'token.*' => 'El enlace no es válido. Solicita uno nuevo.',
            'email.*' => 'Escribe un correo electrónico válido.',
        ]);

        $activatedUser = null;
        $status = DB::transaction(function () use ($data, $activation, &$activatedUser): string {
            // Serialize validation, consumption and account update for single-use tokens.
            DB::table('password_reset_tokens')->where('email', $data['email'])->lockForUpdate()->first();
            $user = User::where('email', $data['email'])->lockForUpdate()->first();
            if (! $user || ($activation && $user->activated_at && $user->hasVerifiedEmail())
                || (! $activation && (! $user->activated_at || ! $user->hasVerifiedEmail()))) {
                return Password::INVALID_TOKEN;
            }

            return Password::reset($data, function (User $user, string $password) use ($activation, &$activatedUser): void {
                $user->password = $password;
                $user->setRememberToken(Str::random(60));
                if ($activation) {
                    $user->activated_at = now();
                    $user->markEmailAsVerified();
                    event(new Verified($user));
                    $activatedUser = $user;
                }
                $user->save();
                if ($activation) app(\App\Services\Crm\ClubContact::class)->enqueue($user);
                // Revoke all other sessions after a password change.
                DB::table('sessions')->where('user_id', $user->id)->delete();
                event(new PasswordReset($user));
            });
        }, 3);

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'El enlace no es válido o ha vencido. Solicita uno nuevo.']);
        }
        if ($activatedUser) {
            Auth::login($activatedUser);
            $request->session()->regenerate();
            $digest = hash_hmac('sha256', $activatedUser->email, config('app.key'));
            if (! hash_equals($digest, (string) $request->session()->get('registration_email_digest', ''))) {
                $request->session()->forget(['registration_receipt', 'registration_email_digest']);
            }
            $request->session()->put('welcome_expires_at', now()->addMinutes(15)->timestamp);

            return MembershipJourney::pending($request) ? redirect()->route('membership.activate') : redirect()->route('welcome');
        }

        return redirect()->route('login')->with('status', 'Contraseña actualizada. Ya puedes iniciar sesión.');
    }
}
