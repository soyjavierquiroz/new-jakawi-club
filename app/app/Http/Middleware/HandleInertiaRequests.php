<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Closure;
use Symfony\Component\HttpFoundation\Response;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function handle(Request $request, Closure $next): Response
    {
        $response = parent::handle($request, $next);
        $response->headers->set('Cache-Control', 'no-store, private');
        if ($request->is('activar/*', 'restablecer-password/*', 'cuenta/jakawi/confirmar-email/*', 'auth/jakawi', 'auth/jakawi/*')) {
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        if ($request->is('ads/*')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            $response->headers->set('Referrer-Policy', 'strict-origin');
        }
        return $response;
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'ads' => fn () => app(\App\Services\Advertising::class)->safely(fn () => app(\App\Services\Advertising::class)->browser($request)),
            'signed_in' => (bool) $request->user(),
            'can_manage_landings' => $request->user()?->is_marketing_admin === true && $request->user()?->hasVerifiedEmail() && $request->user()?->activated_at !== null,
            'status' => fn () => $request->session()->get('status'),
        ];
    }
}
