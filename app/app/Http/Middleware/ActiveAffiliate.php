<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ActiveAffiliate
{
    public function handle(Request $request, Closure $next): Response
    {
        // Same local eligibility as existing verified, activated CLUB accounts.
        abort_unless($request->user()?->activated_at !== null && $request->user()?->hasVerifiedEmail(), 403);

        return $next($request);
    }
}
