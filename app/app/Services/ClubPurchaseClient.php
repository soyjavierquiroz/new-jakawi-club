<?php
namespace App\Services;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
final class ClubPurchaseClient {
    public function request(string $method, string $suffix = '', ?array $data = null, int $timeout = 15, int $attempts = 2): Response {
        return $this->signedRequest($method, '/internal/club/purchases'.$suffix, $data, $timeout, $attempts);
    }

    public function partnerAccess(string $subject): Response {
        return $this->signedRequest('POST', '/internal/club/partners/access', ['global_subject_id' => $subject], 8, 1);
    }

    public function acquisitionEvidence(string $payment, ?string $affiliate): Response {
        return $this->signedRequest('POST', '/internal/club/acquisitions/evidence', [
            'purchase_reference' => $payment, 'affiliate_global_subject_id' => $affiliate,
        ], 8, 2);
    }

    private function signedRequest(string $method, string $path, ?array $data, int $timeout, int $attempts): Response {
        $secret = config('purchases.secret');
        if (!is_string($secret) || strlen($secret) < 32) throw new \RuntimeException('Purchase service unavailable');
        $raw = $data === null ? '' : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $time = (string) time(); $nonce = (string) Str::uuid();
            $canonical = implode("\n", ['jakawi-club-purchases-v1', 'club-purchases-v1', $time, $nonce, $method, $path, hash('sha256', $raw)]);
            try {
                $response = Http::connectTimeout(3)->timeout($timeout)->withOptions(['allow_redirects' => false])->withHeaders([
                    'Accept' => 'application/json', 'X-Jakawi-Key-Id' => 'club-purchases-v1',
                    'X-Jakawi-Timestamp' => $time, 'X-Jakawi-Nonce' => $nonce,
                    'X-Jakawi-Signature' => hash_hmac('sha256', $canonical, $secret),
                ])->withBody($raw, 'application/json')->send($method, 'https://jakawi.com'.$path);
            } catch (\Illuminate\Http\Client\ConnectionException) {
                if ($attempt + 1 < $attempts) { usleep(random_int(100000, 250000)); continue; }
                throw new \RuntimeException('Purchase service unavailable');
            }
            if ($attempt + 1 < $attempts && ($response->serverError() || $response->status() === 429)) {
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
