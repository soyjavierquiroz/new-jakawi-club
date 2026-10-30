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

// Explicit recovery/backfill of existing receipts; never generates commercial events.
Artisan::command('commissions:sync', function (): int {
    $count = 0;
    Illuminate\Support\Facades\DB::table('commercial_events')->where('source', 'jakawi.com')->orderBy('id')
        ->chunkById(100, function ($events) use (&$count): void {
            foreach ($events as $event) {
                app(App\Services\Commissions\CommissionEngine::class)->record($event);
                $count++;
            }
        });
    $this->info('Eventos existentes evaluados: '.$count.'. No se aprobaron comisiones ni se ejecutaron pagos.');
    return 0;
})->purpose('Sincronizar candidatos idempotentes desde eventos COM existentes');
