<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class MediaReferences
{
    public function all(): array
    {
        $keys = [];
        DB::table('marketing_landings')->select('id', 'configuration', 'published_configuration')->orderBy('id')->chunkById(200, function ($rows) use (&$keys): void {
            foreach ($rows as $row) {
                $this->collect(json_decode($row->configuration, true, 64, JSON_THROW_ON_ERROR), $keys);
                $this->collect(json_decode($row->published_configuration ?? 'null', true, 64, JSON_THROW_ON_ERROR), $keys);
            }
        });
        DB::table('marketing_landing_snapshots')->select('id', 'configuration')->orderBy('id')->chunkById(200, function ($rows) use (&$keys): void {
            foreach ($rows as $row) $this->collect(json_decode($row->configuration, true, 64, JSON_THROW_ON_ERROR), $keys);
        });
        return $keys;
    }

    public function contains(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    private function collect(mixed $value, array &$keys): void
    {
        if (is_array($value)) {
            foreach ($value as $item) $this->collect($item, $keys);
        } elseif (is_string($value) && app(MediaUploadService::class)->isMediaKey($value)) {
            $keys[$value] = true;
        }
    }
}
