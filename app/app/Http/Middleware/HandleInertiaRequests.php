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
            'ads' => fn () => $request->is('admin/soporte', 'admin/soporte/*') ? null : app(\App\Services\Advertising::class)->safely(fn () => app(\App\Services\Advertising::class)->browser($request)),
            'admin_support_mode' => fn () => $request->session()->has('support_view'),
            'admin_navigation' => fn () => \App\Http\Controllers\AdminDashboardController::navigation($request),
            'account_navigation' => function () use ($request) {
                $user = $request->user();
                if (!$user || $request->session()->has('support_view')) return [];
                $links = [
                    ['label' => 'Mi cuenta', 'href' => '/mi-cuenta'],
                ];
                // Match verified + ActiveAffiliate; Partner grants remain enforced by the panel.
                if ($user->activated_at !== null && $user->hasVerifiedEmail()) {
                    $links[] = ['label' => 'Mis referidos y ganancias', 'href' => '/afiliados'];
                }
                $links[] = ['label' => 'Explorar beneficios', 'href' => 'https://jakawi.com/beneficios'];
                $links[] = ['label' => 'Editar perfil COM', 'href' => 'https://jakawi.com/perfil'];
                $admin = \App\Http\Controllers\AdminDashboardController::navigation($request);
                if (in_array('/admin', array_column($admin, 'href'), true)) {
                    $links[] = ['label' => 'Administración CLUB', 'href' => '/admin'];
                } elseif ($admin !== []) {
                    $links[] = ['label' => 'Administración CLUB', 'href' => $admin[0]['href']];
                }
                return $links;
            },
            'appearance_identity' => \Inertia\Inertia::always($request->user()?->uuid),
            'theme_preference' => \Inertia\Inertia::always($request->user()?->theme_preference ?? 'system'),
            'signed_in' => (bool) $request->user(),
            'can_manage_landings' => $request->user()?->is_marketing_admin === true && $request->user()?->hasVerifiedEmail() && $request->user()?->activated_at !== null,
            'status' => fn () => $request->session()->get('status'),
        ];
    }
}
