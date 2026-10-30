<?php

namespace App\Services\Identity;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Jumbojett\OpenIDConnectClient;
use Jumbojett\OpenIDConnectClientException;

/** Laravel transport/session adapter and the restrictions of COM's deployed OIDC contract. */
final class JakawiOidc extends OpenIDConnectClient
{
    public const ISSUER = 'https://jakawi.com';
    public const CALLBACK = 'https://jakawi.club/auth/jakawi/callback';
    public array $transaction = [];
    private ?string $authorizationUrl = null;

    public function __construct()
    {
        if (! config('oidc.enabled') || ! is_string(config('oidc.client_id')) || ! Str::isUuid(config('oidc.client_id'))
            || ! is_string(config('oidc.client_secret')) || strlen(config('oidc.client_secret')) < 32) {
            throw new OpenIDConnectClientException('OIDC unavailable');
        }
        parent::__construct(self::ISSUER, config('oidc.client_id'), config('oidc.client_secret'));
        $this->providerConfigParam([
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'/oauth/authorize',
            'token_endpoint' => self::ISSUER.'/oauth/token',
            'jwks_uri' => self::ISSUER.'/oauth/jwks',
            'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
            'code_challenge_methods_supported' => ['S256'],
        ]);
        $this->setIssuerValidator(fn (string $issuer): bool => $issuer === self::ISSUER);
        $this->setRedirectURL(self::CALLBACK);
        $this->setCodeChallengeMethod('S256');
        $this->addScope(['profile', 'email']);
    }

    public function begin(): string
    {
        $this->run([]);
        if (! $this->authorizationUrl || empty($this->transaction['openid_connect_code_verifier'])) {
            throw new OpenIDConnectClientException('PKCE required');
        }
        return $this->authorizationUrl;
    }

    public function complete(array $transaction, string $code, string $state): string
    {
        $this->transaction = $transaction;
        if (! $this->run(['code' => $code, 'state' => $state])) {
            throw new OpenIDConnectClientException('OIDC rejected');
        }
        return $this->getVerifiedClaims('sub');
    }

    private function run(array $parameters): bool
    {
        // Upstream reads $_REQUEST. Supply only controller-validated query values, never cookies/POST.
        $original = $_REQUEST;
        try {
            $_REQUEST = $parameters;
            return $this->authenticate();
        } finally {
            $_REQUEST = $original;
        }
    }

    protected function generateRandString(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function redirect(string $url): void
    {
        $this->authorizationUrl = $url;
    }

    protected function commitSession(): void {}

    protected function getSessionKey(string $key): mixed
    {
        return $this->transaction[$key] ?? false;
    }

    protected function setSessionKey(string $key, $value): void
    {
        $this->transaction[$key] = $value;
    }

    protected function unsetSessionKey(string $key): void
    {
        unset($this->transaction[$key]);
    }

    public function verifyJWTSignature(string $jwt): bool
    {
        if (strlen($jwt) > 16384 || count(explode('.', $jwt)) !== 3) return false;
        $header = $this->decodeJWT($jwt);
        if (! is_object($header) || ($header->alg ?? null) !== 'RS256'
            || ! is_string($header->kid ?? null) || $header->kid === '' || strlen($header->kid) > 256) return false;
        foreach (['jwk', 'jku', 'x5u', 'x5c', 'enc', 'crit'] as $key) {
            if (property_exists($header, $key)) return false;
        }
        return parent::verifyJWTSignature($jwt);
    }

    protected function verifyJWTClaims($claims, ?string $accessToken = null): bool
    {
        if (! is_object($claims) || ($claims->iss ?? null) !== self::ISSUER
            || ! is_string($claims->sub ?? null) || ! Str::isUuid($claims->sub)
            || ! is_string($claims->nonce ?? null) || ! is_string($this->getNonce())
            || ! hash_equals($this->getNonce(), $claims->nonce)
            || ! is_int($claims->iat ?? null) || ! is_int($claims->exp ?? null)
            || $claims->iat > time() + 30 || $claims->iat < time() - 630
            || $claims->exp <= time() || $claims->exp <= $claims->iat || $claims->exp - $claims->iat > 3600
            || (isset($claims->nbf) && (! is_int($claims->nbf) || $claims->nbf > time() + 30))) return false;
        $audience = $claims->aud ?? null;
        if (is_string($audience)) {
            if ($audience !== config('oidc.client_id')) return false;
        } elseif (! is_array($audience) || ! in_array(config('oidc.client_id'), $audience, true)
            || (count($audience) > 1 && ($claims->azp ?? null) !== config('oidc.client_id'))) return false;
        if (isset($claims->azp) && $claims->azp !== config('oidc.client_id')) return false;
        return parent::verifyJWTClaims($claims, $accessToken);
    }

    protected function fetchURL(string $url, ?string $post_body = null, array $headers = []): string
    {
        if (! in_array($url, [self::ISSUER.'/oauth/token', self::ISSUER.'/oauth/jwks'], true)
            || ($url === self::ISSUER.'/oauth/token') !== ($post_body !== null)) {
            throw new OpenIDConnectClientException('Unexpected OIDC endpoint');
        }
        $http = Http::acceptJson()->connectTimeout(3)->timeout(8)->withoutRedirecting();
        foreach ($headers as $header) {
            [$name, $value] = explode(':', $header, 2);
            $http = $http->withHeaders([$name => trim($value)]);
        }
        $response = $post_body === null ? $http->get($url)
            : $http->withBody($post_body, 'application/x-www-form-urlencoded')->post($url);
        if (! $response->successful() || strlen($response->body()) > 65536) {
            throw new OpenIDConnectClientException('OIDC transport rejected');
        }
        $data = $response->json();
        if (! is_array($data)) throw new OpenIDConnectClientException('OIDC response rejected');
        if ($post_body !== null) {
            if (strtolower($data['token_type'] ?? '') !== 'bearer' || ! is_string($data['access_token'] ?? null)
                || ! is_string($data['id_token'] ?? null) || isset($data['refresh_token'])) {
                throw new OpenIDConnectClientException('OIDC token response rejected');
            }
        } else {
            if (! is_array($data['keys'] ?? null) || ! count($data['keys'])) {
                throw new OpenIDConnectClientException('OIDC keys rejected');
            }
            foreach ($data['keys'] as $key) {
                if (($key['kty'] ?? null) !== 'RSA' || ($key['use'] ?? 'sig') !== 'sig'
                    || ($key['alg'] ?? 'RS256') !== 'RS256' || ! is_string($key['kid'] ?? null)
                    || ! is_string($key['n'] ?? null) || ! is_string($key['e'] ?? null) || isset($key['d'])) {
                    throw new OpenIDConnectClientException('OIDC key rejected');
                }
            }
        }
        return $response->body();
    }
}
