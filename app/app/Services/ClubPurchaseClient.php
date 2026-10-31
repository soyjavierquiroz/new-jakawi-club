<?php
namespace App\Services;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
final class ClubPurchaseClient {
    public function request(string $method, string $suffix = '', ?array $data = null): Response {
        $secret = config('purchases.secret');
        if (!is_string($secret) || strlen($secret) < 32) throw new \RuntimeException('Purchase service unavailable');
        $path = '/internal/club/purchases'.$suffix;
        $raw = $data === null ? '' : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $time = (string) time(); $nonce = (string) Str::uuid();
            $canonical = implode("\n", ['jakawi-club-purchases-v1', 'club-purchases-v1', $time, $nonce, $method, $path, hash('sha256', $raw)]);
            try {
                $response = Http::connectTimeout(3)->timeout(15)->withOptions(['allow_redirects' => false])->withHeaders([
                    'Accept' => 'application/json', 'X-Jakawi-Key-Id' => 'club-purchases-v1',
                    'X-Jakawi-Timestamp' => $time, 'X-Jakawi-Nonce' => $nonce,
                    'X-Jakawi-Signature' => hash_hmac('sha256', $canonical, $secret),
                ])->withBody($raw, 'application/json')->send($method, 'https://jakawi.com'.$path);
            } catch (\Illuminate\Http\Client\ConnectionException) {
                if ($attempt === 0) { usleep(random_int(100000, 250000)); continue; }
                throw new \RuntimeException('Purchase service unavailable');
            }
            if ($attempt === 0 && ($response->serverError() || $response->status() === 429)) {
                // Respect long server backoffs by leaving retry to a later user action.
                if ((int) $response->header('Retry-After') > 1) break;
                usleep(random_int(250000, 500000)); continue;
            }
            if ($response->successful()) return $response;
            break;
        }
        // Never propagate response bodies, buyer data or signed request headers to logs.
        throw new \RuntimeException('Purchase service unavailable');
    }
}
