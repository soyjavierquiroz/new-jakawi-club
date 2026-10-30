<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Commercial links are stateless; 12-character CLUB invitations keep their web route.
            \Illuminate\Support\Facades\Route::get('/r/{shortCode}', \App\Http\Controllers\ShortLinkController::class)
                ->where('shortCode', '(?![A-Z0-9]{12}$)[A-Za-z0-9]+')->name('short-link');
            // Machine-only receiver: no browser session, CSRF exemption or CRM hooks.
            \Illuminate\Support\Facades\Route::post('/internal/commercial/events', \App\Http\Controllers\CommercialEventController::class)
                ->middleware(\App\Http\Middleware\AuthenticateCommercialEvent::class)->name('commercial.receive');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Only the confirmed Docker network shared with Traefik is trusted.
        $middleware->trustProxies(
            at: ['172.18.0.0/16'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PORT,
        );

        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->redirectUsersTo('/dashboard');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'internal/commercial/*') || $request->expectsJson(),
        );
    })->create();
