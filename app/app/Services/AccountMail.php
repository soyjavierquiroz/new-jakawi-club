<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\ActivateAccount;
use App\Support\LaunchSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

class AccountMail
{
    public function send(string $email): void
    {
        if (! LaunchSettings::mailReady()) {
            return;
        }

        try {
            Password::sendResetLink(['email' => $email], function (User $user, string $token): void {
                if (! $user->activated_at || ! $user->hasVerifiedEmail()) {
                    $user->notify(new ActivateAccount($token, \App\Support\MembershipJourney::pending(request())));
                } else {
                    $user->sendPasswordResetNotification($token);
                }
            });
        } catch (\Throwable) {
            // SMTP exceptions may contain credentials or token URLs. Never log the exception.
            Log::warning('No se pudo entregar un correo de acceso JAKAWI. Revisar el servicio SMTP de forma privada.');
        }
    }
}
