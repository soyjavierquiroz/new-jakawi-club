<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
final class PartnerSsoEntry {
 public function handle(Request $request, Closure $next): Response {
  if (!$request->user()) {
   $request->session()->put('url.intended', url('/afiliados'));
   return app(\App\Http\Controllers\JakawiLoginController::class)->partner($request);
  }
  $response=$next($request);
  $response->headers->set('Cache-Control', 'private, no-store');
  return $response;
 }
}
