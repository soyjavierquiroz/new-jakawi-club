<?php

namespace App\Services\Identity;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class ComIdentityClient
{
    public const CONTRACT = 'jakawi-identity-handoff-v1';
    public const ISSUER = 'https://jakawi.com';

    public function credentials(): array
    {
        $key = config('identity.key_id');
        $secret = config('identity.secret');
        $proof = config('identity.proof_secret');
        $crmFile = config('crm.secret_file');
        $crm = is_string($crmFile) && is_readable($crmFile) ? trim(file_get_contents($crmFile)) : '';
        if (! config('identity.enabled') || config('identity.base_url') !== self::ISSUER.'/internal/identity'
            || ! is_string($key) || ! preg_match('/^[A-Za-z0-9:_-]{1,128}$/D', $key)
            || ! is_string($secret) || strlen($secret) < 32 || ! is_string($proof) || strlen($proof) < 32
            || hash_equals($secret, $proof) || hash_equals($secret, $crm) || hash_equals($proof, $crm)) {
            throw new IdentityFailure('CONFIGURATION', false);
        }

        return [$key, $secret, $proof];
    }

    public function create(string $raw): array
    {
        return $this->request('POST', '/handoffs', $raw) ?? throw new IdentityFailure('UNEXPECTED_RESPONSE');
    }

    public function handoff(string $id): ?array
    {
        $this->uuid($id);
        return $this->request('GET', '/handoffs/'.$id);
    }

    public function link(string $club): ?array
    {
        $this->uuid($club);
        return $this->request('GET', '/links/'.$club);
    }

    private function uuid(string $id): void
    {
        if (! Str::isUuid($id) || strtolower($id) !== $id) {
            throw new IdentityFailure('LOCAL_IDENTITY_INVALID', false);
        }
    }

    private function request(string $method, string $suffix, string $raw = ''): ?array
    {
        [$key, $secret] = $this->credentials();
        $backoffKey = 'com-identity-backoff:'.hash('sha256', $key);
        $until = (int) Cache::get($backoffKey, 0);
        if ($until > now()->timestamp) throw new IdentityFailure('RATE_LIMIT', true, $until - now()->timestamp);
        $path = '/internal/identity'.$suffix;
        if (strlen($raw) > 16384) {
            throw new IdentityFailure('BODY_TOO_LARGE', false);
        }
        // Exactly two bounded transport attempts; operation bytes never change.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $timestamp = (string) now()->timestamp;
            $nonce = strtolower((string) Str::uuid());
            $canonical = implode("\n", ['jakawi-identity-v1', $key, $timestamp, $nonce, $method, $path, hash('sha256', $raw)]);
            try {
                $response = Http::connectTimeout(2)->timeout(6)->withOptions(['allow_redirects' => false])
                    ->withHeaders([
                        'Accept' => 'application/json', 'X-Jakawi-Key-Id' => $key,
                        'X-Jakawi-Timestamp' => $timestamp, 'X-Jakawi-Nonce' => $nonce,
                        'X-Jakawi-Signature' => hash_hmac('sha256', $canonical, $secret),
                    ])->withBody($raw, 'application/json')->send($method, self::ISSUER.$path);
            } catch (\Throwable) {
                if ($attempt === 0) continue;
                throw new IdentityFailure('TRANSPORT');
            }
            $code = $response->status();
            if ($code === 404 && $method === 'GET') return null;
            $data = strlen($response->body()) <= 32768 ? $response->json() : null;
            if (is_array($data) && ($code === 200 || ($code === 409 && ($data['status'] ?? null) === 'CONFLICT'))) {
                return $data;
            }
            if ($code === 429) {
                $value = $response->header('Retry-After');
                $delay = ctype_digit((string) $value) ? (int) $value : max(0, (int) strtotime((string) $value) - now()->timestamp);
                $delay = max(30, $delay);
                Cache::put($backoffKey, now()->timestamp + $delay, $delay);
                throw new IdentityFailure('RATE_LIMIT', true, $delay);
            }
            if ($code >= 500 || $code === 408 || ($code === 409 && ($data['error'] ?? null) === 'REQUEST_REPLAY')) {
                if ($attempt === 0) continue;
                throw new IdentityFailure('REMOTE_UNAVAILABLE');
            }
            throw new IdentityFailure($code === 401 ? 'AUTHENTICATION' : 'INVALID_RESPONSE', $code === 200);
        }
        throw new IdentityFailure('REMOTE_UNAVAILABLE');
    }
}
