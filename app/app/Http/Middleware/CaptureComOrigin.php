<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
final class CaptureComOrigin {
 public function handle(Request $request, Closure $next): mixed {
  if ($request->is('membresia','activar','registro') && !$request->headers->has('Purpose') && !$request->headers->has('Sec-Purpose')) app(\App\Services\PartnerAcquisition::class)->capture($request);
  return $next($request);
 }
}
