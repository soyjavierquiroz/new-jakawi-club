<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('landings:admin {email} {--revoke}', function (): int {
    $user = App\Models\User::where('email', App\Support\Normalize::email($this->argument('email')))->first();
    if (! $user || (! $this->option('revoke') && (! $user->hasVerifiedEmail() || ! $user->activated_at))) {
        $this->error('Se requiere una cuenta existente, activada y verificada. No se creó ninguna cuenta.');
        return 1;
    }
    $user->is_marketing_admin = ! $this->option('revoke');
    $user->save();
    $this->info($this->option('revoke') ? 'Permiso administrativo revocado.' : 'Permiso administrativo de landings concedido.');
    return 0;
})->purpose('Conceder o revocar administración de landings a una cuenta existente');
