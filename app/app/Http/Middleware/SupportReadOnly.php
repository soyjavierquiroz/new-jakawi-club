<?php

namespace App\Http\Middleware;

use App\Services\Support\SupportAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SupportReadOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = $request->session()->get('support_view');
        if ($context) {
            $access = app(SupportAccess::class);
            if ($request->isMethod('POST') && $request->is('admin/soporte/salir')) return $next($request);
            $actor = $access->requireActor($request);
            abort_unless(($context['actor'] ?? null) === $actor->uuid, 403);
            if (($context['expires_at'] ?? 0) <= time()) {
                if (empty($context['expired'])) {
                    $access->audit($request, 'expired');
                    $context['expired'] = true;
                    $request->session()->put('support_view', $context);
                    $request->session()->forget('support_reauth');
                }
                if ($request->isMethod('GET') && $request->is('admin/soporte')) return $next($request);
                abort(403, 'El modo soporte venció. Sal del modo soporte para continuar.');
            }
            if ($request->isMethod('GET') && $request->is('mi-cuenta', 'dashboard', 'afiliados')) {
                return redirect('/admin/soporte/'.($request->is('afiliados') ? 'afiliados' : 'cuenta'));
            }
            if (!$request->isMethod('GET') || !$request->is('admin/soporte', 'admin/soporte/cuenta', 'admin/soporte/afiliados')) {
                $access->audit($request, 'blocked');
                abort(403, 'MODO SOPORTE: solo lectura. Sal del modo soporte para realizar acciones.');
            }
        }
        $response = $next($request);
        if ($request->is('admin/soporte', 'admin/soporte/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Referrer-Policy', 'no-referrer');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }
        return $response;
    }
}
