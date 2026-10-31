<?php

namespace App\Support;

use App\Models\MarketingLanding;
use Illuminate\Support\Str;

final class LandingCommercial
{
    public static function text(mixed $value, int $limit): string
    {
        $plain = is_string($value) ? html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        $plain = preg_replace('/[\p{C}\s]+/u', ' ', $plain) ?? '';
        return Str::limit(trim($plain), $limit, '…');
    }

    public static function forLanding(MarketingLanding $landing, bool $preview = false): array
    {
        $content = $preview ? $landing->configuration : ($landing->published_configuration ?? []);
        // Historical snapshots never fall back to the mutable draft title.
        $publicName = $preview ? $landing->title : ($content['commercial']['public_name'] ?? str_replace('-', ' ', $landing->slug));
        return self::resolve($content, $publicName);
    }

    public static function socialImage(array $content): ?string
    {
        return app(\App\Services\MediaUrl::class)->social($content['commercial']['image'] ?? null)
            ?? app(\App\Services\MediaUrl::class)->social('/marketing/club-card.png');
    }

    public static function resolve(array $content, string $publicName): array
    {
        return [
            'name' => self::text($content['seo']['title'] ?? null, 160) ?: self::text($publicName, 160) ?: 'JAKAWI CLUB',
            'short_description' => self::text($content['commercial']['description'] ?? null, 200)
                ?: self::text($content['hero']['description'] ?? null, 200),
            'image_url' => app(\App\Services\MediaUrl::class)->url($content['commercial']['image'] ?? null, 'card')
                ?? LaunchSettings::videoPoster($content['video']['poster'] ?? null),
        ];
    }
}
