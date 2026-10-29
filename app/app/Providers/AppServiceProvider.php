<?php

namespace App\Providers;

use App\Support\Normalize;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // This event is emitted inside the native activation-token transaction.
        // CLI bootstrap and historical timestamps are never backfilled as token evidence.
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Verified::class, function ($event): void {
            if (config('identity.enabled') && request()->routeIs('activation.store')) {
                app(\App\Services\Identity\ActivationEvidence::class)->record($event->user);
            }
        });
        RateLimiter::for('registration', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('account-mail', fn (Request $request) => [
            Limit::perMinute(5)->by('ip:'.$request->ip()),
            Limit::perMinute(2)->by('email:'.hash('sha256', Normalize::email($request->input('email')))),
        ]);
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(20)->by('ip:'.$request->ip()),
            Limit::perMinute(5)->by(hash('sha256', Normalize::email($request->input('email')).'|'.$request->ip())),
        ]);
        RateLimiter::for('referral', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        ResetPassword::createUrlUsing(fn (object $user, string $token): string =>
            rtrim(config('app.url'), '/').'/restablecer-password/'.$token.'?'.http_build_query(['email' => $user->email]));
        ResetPassword::toMailUsing(fn (object $user, string $token): MailMessage => (new MailMessage)
            ->subject('Recupera tu acceso a JAKAWI')
            ->greeting('Hola')
            ->line('Recibimos una solicitud para restablecer tu contraseña.')
            ->action('Elegir una nueva contraseña', rtrim(config('app.url'), '/').'/restablecer-password/'.$token.'?'.http_build_query(['email' => $user->email]))
            ->line('El enlace vence en 60 minutos y es de un solo uso.')
            ->line('Si no solicitaste este cambio, ignora este mensaje.'));
    }
}
