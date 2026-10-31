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

// Issue links only for real, eligible CLUB accounts; reruns reuse the same pair.
Artisan::command('short-links:prepare-pilot', function (): int {
    $links = app(App\Services\AffiliateShortLinks::class);
    $count = 0;
    App\Models\User::whereNotNull('activated_at')->whereNotNull('email_verified_at')
        ->orderBy('id')->chunkById(100, function ($users) use ($links, &$count): void {
            foreach ($users as $user) {
                $links->forAffiliate($user, 'dossis-43');
                $count++;
            }
        });
    $this->info('Enlaces permanentes Dossis 43 disponibles: '.$count.'. Sin eventos ni conversiones.');
    return 0;
})->purpose('Preparar enlaces Dossis 43 para afiliados CLUB existentes');

// Reuse the existing CLUB catalog timer and process; no new worker or container.
Illuminate\Support\Facades\Schedule::call(function (): void {
    if (Artisan::call('media:gc') !== 0) throw new RuntimeException('No se pudo completar GC de CLUB.');
})->name('club-media-gc')->dailyAt('03:20')->timezone('America/La_Paz')->withoutOverlapping(120);

// Existing CLUB scheduler and database queues; bounded cleanup and recovery, no new services.
Illuminate\Support\Facades\Schedule::call(function (): void {
    Illuminate\Support\Facades\DB::table('membership_purchases')->whereIn('ad_visit_id',
        Illuminate\Support\Facades\DB::table('ad_visits')->select('id')->where('expires_at', '<=', now()))->update(['ad_visit_id' => null]);
    Illuminate\Support\Facades\DB::table('ad_events')->where('expires_at', '<=', now())->delete();
    Illuminate\Support\Facades\DB::table('ad_visits')->where('expires_at', '<=', now())->delete();
    App\Models\AdEvent::where('status', 'received')->limit(20)->get()->each(function ($event): void {
        app(App\Services\Advertising::class)->safely(fn () => app(App\Services\Advertising::class)->enqueue($event));
    });
})->name('club-ads-maintenance')->everyTenMinutes()->withoutOverlapping();
