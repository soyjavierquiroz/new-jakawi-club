<?php

namespace App\Support;

use App\Models\MarketingLanding;
use App\Services\MediaUrl;

final class LandingSeo
{
    public static function canonical(MarketingLanding $landing): string
    {
        return rtrim(config('app.url'), '/').($landing->isHome() ? '/' : '/'.$landing->slug);
    }

    public static function forLanding(MarketingLanding $landing, bool $preview = false): array
    {
        $content = $preview ? $landing->configuration : ($landing->published_configuration ?? []);
        $commercial = LandingCommercial::forLanding($landing, $preview);
        return [...$commercial,
            'short_description' => LandingCommercial::text($content['seo']['description'] ?? null, 320) ?: $commercial['short_description'],
            'image_url' => LandingCommercial::socialImage($content),
            'canonical' => self::canonical($landing),
            'robots' => !$preview && $landing->isPublic() ? 'index, follow' : 'noindex, nofollow',
            'site_name' => 'JAKAWI CLUB',
        ];
    }

    public static function unavailable(object $offer): array
    {
        $label = match ($offer->effective_state) {
            'finished' => 'Oferta finalizada',
            'retired' => 'Oferta retirada del catálogo',
            'upcoming' => 'Próximamente disponible',
            default => 'Oferta no disponible por ahora',
        };
        // Keep only a public identity. Never read draft fields or affiliate data.
        $landing = $offer->source === 'club' && preg_match('/^landing:([0-9]+)$/D', $offer->source_id ?? '', $match)
            ? MarketingLanding::find((int) $match[1]) : null;
        $base = $landing && $landing->published_configuration !== null ? self::forLanding($landing) : [
            'name' => LandingCommercial::text($offer->name, 160),
            'image_url' => app(MediaUrl::class)->social($offer->image_url) ?? app(MediaUrl::class)->social('/marketing/club-card.png'),
            'canonical' => explode('?', explode('#', $offer->destination_url, 2)[0], 2)[0],
            'site_name' => 'JAKAWI',
        ];
        return [...$base, 'name' => $label.' · '.$base['name'], 'short_description' => $label.'. Este enlace conserva su oferta original y su historial.', 'robots' => 'noindex, nofollow'];
    }
}
