<?php

namespace App\Http\Middleware;

use App\Support\LaunchSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RegistrationOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(LaunchSettings::registrationOpen(), 403, 'Los registros aún no están abiertos.');

        return $next($request);
    }
}
