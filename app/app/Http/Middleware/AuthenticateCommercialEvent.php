<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateCommercialEvent
{
    public function handle(Request $request, Closure $next): Response
    {
        $fail = fn (string $error, int $status): Response => response()->json(['error' => $error], $status)
            ->header('Cache-Control', 'no-store');
        $path = '/internal/commercial/events';
        // Check the original target as Nginx rewrites the front controller URI.
        if ($request->getPathInfo() !== $path || $request->getQueryString() !== null) {
            return $fail('invalid_target', 400);
        }
        $body = $request->getContent();
        if (strlen($body) > config('commercial.max_body_bytes')) {
            return $fail('payload_too_large', 413);
        }
        $file = config('commercial.secret_file');
        $secret = is_string($file) && is_readable($file) ? trim(file_get_contents($file)) : '';
        if (strlen($secret) < 64) {
            return $fail('receiver_unavailable', 503);
        }
        $timestamp = $request->header('X-Jakawi-Com-Timestamp', '');
        $signature = $request->header('X-Jakawi-Com-Signature', '');
        $canonical = $timestamp."\nPOST\n".$path."\n".hash('sha256', $body);
        if ($request->header('X-Jakawi-Com-Key-Id') !== config('commercial.key_id')
            || ! preg_match('/^[0-9]{10}$/D', $timestamp)
            || abs(time() - (int) $timestamp) > config('commercial.clock_tolerance')
            || ! preg_match('/^sha256=[a-f0-9]{64}$/D', $signature)
            || ! hash_equals('sha256='.hash_hmac('sha256', $canonical, $secret), $signature)) {
            return $fail('unauthenticated', 401);
        }
        if (strtolower(trim(explode(';', $request->header('Content-Type', ''))[0])) !== 'application/json') {
            return $fail('unsupported_media_type', 415);
        }

        return $next($request)->header('Cache-Control', 'no-store');
    }
}
