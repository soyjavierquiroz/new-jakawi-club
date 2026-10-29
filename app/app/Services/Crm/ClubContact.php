<?php
namespace App\Services\Crm;

use App\Jobs\SyncClubContact;
use App\Models\User;
use Illuminate\Support\Str;

final class ClubContact
{
    public function payload(User $user): array
    {
        $origin = $user->registration_origin ?? [];
        $activated = $user->activated_at !== null && $user->hasVerifiedEmail();
        return [
            'version'=>1, 'event_id'=>(string) Str::uuid(), 'operation'=>'CONTACT_UPSERT',
            'occurred_at'=>now()->utc()->toIso8601String(), 'source_system'=>'CLUB', 'external_id'=>$user->uuid,
            'contact'=>['name'=>$user->name, 'email'=>$user->email, 'phone'=>$user->phone],
            'attribution'=>['landing_slug'=>$origin['landing_slug'] ?? null, 'campaign'=>$origin['campaign'] ?? null,
                'referrer_external_id'=>$user->referred_by_id ? User::whereKey($user->referred_by_id)->value('uuid') : null,
                'utms'=>$origin['utms'] ?? []],
            'activation'=>['status'=>$activated?'activated':'pending', 'registered_at'=>$user->created_at->utc()->toIso8601String(),
                'activated_at'=>$activated?$user->activated_at->utc()->toIso8601String():null],
            'marketing'=>['action'=>'UNCHANGED'],
        ];
    }

    public function enqueue(User $user): void
    {
        // The existing database queue shares the account transaction. Workers can
        // only read committed rows; CRM never runs inside the public request.
        SyncClubContact::dispatch($this->payload($user))->onConnection('database')->onQueue('crm')->beforeCommit();
    }
}
