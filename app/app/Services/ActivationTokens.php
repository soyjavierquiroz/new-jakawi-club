<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ActivationTokens
{
    // The user row serializes issuance, revocation and consumption, including first issuance.
    public function issue(User $user): ?string
    {
        return DB::transaction(function () use ($user): ?string {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($locked->activated_at && $locked->hasVerifiedEmail()) return null;
            $token = bin2hex(random_bytes(32));
            DB::table('account_activation_tokens')->updateOrInsert(['user_id' => $locked->id], [
                'token_hash' => hash('sha256', $token), 'created_at' => now(), 'revoked_at' => null,
            ]);
            return $token;
        });
    }

    public function valid(User $user, string $token): bool
    {
        if ($user->activated_at && $user->hasVerifiedEmail()) return false;
        $hash = DB::table('account_activation_tokens')->where('user_id', $user->id)->whereNull('revoked_at')->value('token_hash');
        return is_string($hash) && hash_equals($hash, hash('sha256', $token));
    }

    public function revoke(User $user): void
    {
        DB::transaction(function () use ($user): void {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            DB::table('account_activation_tokens')->where('user_id', $user->id)->update(['revoked_at' => now()]);
        });
    }
}
