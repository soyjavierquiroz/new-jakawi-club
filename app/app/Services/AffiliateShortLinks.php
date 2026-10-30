<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AffiliateShortLinks
{
    public function validDestination(string $url): bool
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);

        return $parts !== false && ($parts['scheme'] ?? '') === 'https'
            && in_array(strtolower($parts['host'] ?? ''), ['jakawi.com', 'www.jakawi.com'], true)
            && ! isset($parts['user'])
            && ! isset($parts['pass']) && ! isset($parts['port']);
    }

    public function destination(string $url, string $referralCode): string
    {
        if (! $this->validDestination($url) || ! preg_match('/^[A-Z0-9]{12}$/D', $referralCode)) {
            throw new InvalidArgumentException('Destino comercial o código CLUB no válido.');
        }
        // Preserve every other query byte (including duplicate keys/signatures).
        // parse_str also catches PHP-normalized aliases such as club.ref and arrays.
        $parts = parse_url($url);
        $query = array_filter(explode('&', $parts['query'] ?? ''), function (string $pair): bool {
            parse_str($pair, $parsed);

            return $pair !== '' && ! array_key_exists('club_ref', $parsed);
        });
        $query[] = 'club_ref='.rawurlencode($referralCode);
        $base = explode('?', explode('#', $url, 2)[0], 2)[0];

        return $base.'?'.implode('&', $query).(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    public function forAffiliate(User $user, string $offerKey): object
    {
        if (! $user->exists || ! $user->activated_at || ! $user->hasVerifiedEmail()
            || ! preg_match('/^[A-Z0-9]{12}$/D', $user->referral_code)) {
            throw new InvalidArgumentException('Se requiere un afiliado CLUB activo con código real.');
        }
        $query = DB::table('affiliate_short_links')->where('affiliate_id', $user->id)->where('offer_key', $offerKey);
        // Database uniqueness handles simultaneous panel requests and random collisions.
        for ($attempt = 0; $attempt < 10; $attempt++) {
            if ($link = (clone $query)->first()) {
                return $link;
            }
            DB::table('affiliate_short_links')->insertOrIgnore([
                'code' => Str::random(8), 'affiliate_id' => $user->id, 'offer_key' => $offerKey,
                'referral_code' => $user->referral_code, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $query->first() ?? throw new \RuntimeException('No se pudo reservar el enlace permanente.');
    }

    public function url(string $code): string
    {
        return rtrim(config('app.url'), '/').'/r/'.$code;
    }

    public function campaign(User $user, object $offer): array
    {
        $link = $this->forAffiliate($user, $offer->key);

        return [
            'name' => $offer->name, 'status' => $offer->status,
            'personal_url' => $this->url($link->code),
            'long_url' => $this->destination($offer->destination_url, $link->referral_code),
        ];
    }
}
