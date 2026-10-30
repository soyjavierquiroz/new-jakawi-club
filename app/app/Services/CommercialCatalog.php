<?php

namespace App\Services;

use App\Models\MarketingLanding;
use App\Support\LandingCommercial;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CommercialCatalog
{
    // Shared SQL keeps pagination, filters, generation and public redirects consistent.
    public function stateSql(): string
    {
        return "CASE
            WHEN NOT catalog_present THEN 'retired'
            WHEN publication_status = 'archived' OR availability_status IN ('expired','ended','archived') OR ends_at <= CURRENT_TIMESTAMP THEN 'finished'
            WHEN local_paused OR publication_status <> 'published' OR landing_status <> 'PUBLISHED' OR availability_status IN ('paused','unavailable') THEN 'paused'
            WHEN availability_status = 'upcoming' THEN 'upcoming'
            WHEN availability_status IN ('available','scheduled') THEN 'active'
            ELSE 'paused' END";
    }

    public function query(): Builder
    {
        return DB::query()->fromSub(DB::table('commercial_offers')->select('*')->selectRaw($this->stateSql().' AS effective_state'), 'catalog');
    }

    public function present(object $offer): array
    {
        $commercial = [];
        if ($offer->source === 'club' && preg_match('/^landing:([0-9]+)$/D', $offer->source_id ?? '', $match)
            && ($landing = MarketingLanding::find((int) $match[1])) && $landing->published_configuration !== null) {
            $commercial = LandingCommercial::forLanding($landing);
        }
        return [
            'key' => $offer->key, 'source' => $offer->source, 'source_id' => $offer->source_id,
            'name' => $offer->name, 'type' => $offer->type, 'short_description' => $offer->short_description,
            'image_url' => $offer->image_url, 'partners' => json_decode($offer->partners, true),
            'cities' => json_decode($offer->cities, true), 'category' => $offer->category,
            'effective_state' => $offer->effective_state, 'availability_status' => $offer->availability_status,
            'publication_status' => $offer->publication_status, 'landing_status' => $offer->landing_status,
            'starts_at' => $offer->starts_at, 'ends_at' => $offer->ends_at,
            'last_synced_at' => $offer->last_synced_at, 'local_paused' => $offer->local_paused,
            'destination_url' => $offer->destination_url,
            ...$commercial,
        ];
    }

    public function clubLanding(object $offer): ?MarketingLanding
    {
        if ($offer->source !== 'club' || ! preg_match('/^landing:([0-9]+)$/D', $offer->source_id ?? '', $match)) {
            return null;
        }
        $landing = MarketingLanding::find((int) $match[1]);
        // The existing /r/{real code} supports only the home landing. No invented
        // query attribution for other campaigns; include them when a mechanism exists.
        return $landing && $landing->isPublic() && $landing->isHome() ? $landing : null;
    }

    public function syncClub(): void
    {
        DB::transaction(function (): void {
            DB::table('commercial_offers')->where('source', 'club')->update(['catalog_present' => false]);
            $landing = MarketingLanding::where('slug', config('jakawi.home_landing_slug'))->first();
            if (! $landing || ! $landing->isPublic()) {
                return;
            }
            $id = 'landing:'.$landing->id;
            $row = [
                ...LandingCommercial::forLanding($landing), 'source' => 'club', 'source_id' => $id,
                'type' => 'acquisition', 'category' => 'Captación',
                'destination_url' => rtrim(config('app.url'), '/').'/',
                'publication_status' => 'published', 'landing_status' => 'PUBLISHED',
                'availability_status' => 'available', 'catalog_present' => true,
                'last_synced_at' => now(), 'updated_at' => now(),
            ];
            $existing = DB::table('commercial_offers')->where('source', 'club')->where('source_id', $id)->first();
            if ($existing) {
                DB::table('commercial_offers')->where('key', $existing->key)->update($row);
            } else {
                DB::table('commercial_offers')->insert([...$row, 'key' => 'club:'.$landing->id, 'original_url' => $row['destination_url'], 'created_at' => now()]);
            }
        });
    }
}
