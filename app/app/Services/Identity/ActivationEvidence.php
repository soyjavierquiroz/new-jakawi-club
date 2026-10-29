<?php

namespace App\Services\Identity;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ActivationEvidence
{
    public function find(User $user): ?object
    {
        return DB::table('club_identity_activations')->where('user_id', $user->id)
            ->where('email_digest', hash('sha256', $user->email))->first();
    }

    public function record(User $user): void
    {
        if (! $user->activated_at || ! $user->hasVerifiedEmail()) {
            throw new IdentityFailure('LOCAL_VERIFICATION', false);
        }
        DB::table('club_identity_activations')->insertOrIgnore([
            'id' => strtolower((string) Str::uuid()), 'user_id' => $user->id,
            'email_digest' => hash('sha256', $user->email), 'activated_at' => now(),
        ]);
    }

    public function proof(User $user, string $handoff): string
    {
        $event = $this->find($user);
        if (! $event || ! $user->activated_at || ! $user->hasVerifiedEmail()) {
            throw new IdentityFailure('ACTIVATION_EVIDENCE_REQUIRED', false);
        }
        [, , $secret] = app(ComIdentityClient::class)->credentials();
        $claims = [
            'iss' => 'https://jakawi.club', 'aud' => ComIdentityClient::ISSUER,
            'sub' => $user->uuid, 'email' => $user->email, 'handoff_id' => $handoff,
            'jti' => strtolower((string) Str::uuid()), 'method' => 'email_activation_token',
            'activation_ref' => 'activation:'.$event->id,
            'activated_at' => \Illuminate\Support\Carbon::parse($event->activated_at)->utc()->toIso8601String(),
            'iat' => now()->timestamp, 'exp' => now()->addSeconds(240)->timestamp,
        ];
        $encoded = rtrim(strtr(base64_encode(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $encoded.'.'.hash_hmac('sha256', "jakawi-email-activation-v1\n".$encoded, $secret);
    }
}
