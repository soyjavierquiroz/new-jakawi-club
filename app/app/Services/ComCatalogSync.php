<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

final class ComCatalogSync
{
    public const ENDPOINT = 'https://jakawi.com/api/catalog/v1/offers';

    public function run(): array
    {
        // Session advisory lock is released even on exceptions/process disconnect.
        if (! DB::selectOne('SELECT pg_try_advisory_lock(70702) AS locked')->locked) {
            throw new RuntimeException('Catalog sync already running.');
        }
        $started = now();
        try {
            [$rows, $pages] = $this->fetch();
            DB::transaction(function () use ($rows, $pages, $started): void {
                $seen = [];
                foreach ($rows as $row) {
                    $id = $row['source_id'];
                    $existing = DB::table('commercial_offers')->where('source', 'com')->where('source_id', $id)->first();
                    $key = $existing?->key ?? $id;
                    if ($existing) {
                        DB::table('commercial_offers')->where('key', $key)->update($row);
                    } else {
                        DB::table('commercial_offers')->insert([...$row, 'key' => $key, 'original_url' => $row['destination_url'], 'created_at' => now()]);
                    }
                    $seen[] = $key;
                }
                DB::table('commercial_offers')->where('source', 'com')->whereNotIn('key', $seen)->update(['catalog_present' => false]);
                DB::table('catalog_sync_runs')->insert(['status' => 'success', 'offers' => count($rows), 'pages' => $pages, 'started_at' => $started, 'completed_at' => now()]);
            });
            return ['offers' => count($rows), 'pages' => $pages];
        } catch (\Throwable $error) {
            // Never persist upstream response bodies, URLs or exceptions in logs.
            DB::table('catalog_sync_runs')->insert(['status' => 'failed', 'started_at' => $started, 'completed_at' => now()]);
            throw new RuntimeException('COM no disponible o recorrido incompleto; último catálogo válido conservado.');
        } finally {
            DB::select('SELECT pg_advisory_unlock(70702)');
        }
    }

    private function fetch(): array
    {
        $url = self::ENDPOINT;
        $rows = [];
        $visited = [];
        $total = $last = $perPage = null;
        for ($page = 1; $page <= 1000; $page++) {
            if (isset($visited[$url]) || ! $this->validPageUrl($url)) {
                throw new RuntimeException('Invalid pagination.');
            }
            $visited[$url] = true;
            $response = Http::acceptJson()->connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false])->get($url)->throw();
            if (strlen($response->body()) > 2_000_000) {
                throw new RuntimeException('Oversized response.');
            }
            $body = $response->json();
            Validator::make(is_array($body) ? $body : [], [
                'data' => ['present', 'array', 'list'], 'meta' => ['required', 'array'],
                'meta.current_page' => ['required', 'integer', 'min:1'], 'meta.per_page' => ['required', 'integer', 'between:1,100'],
                'meta.total' => ['required', 'integer', 'between:0,50000'], 'meta.last_page' => ['required', 'integer', 'between:1,1000'],
                'links' => ['required', 'array'], 'links.next' => ['present', 'nullable', 'string', 'max:2048'],
            ])->validate();
            $meta = $body['meta'];
            $total ??= $meta['total']; $last ??= $meta['last_page']; $perPage ??= $meta['per_page'];
            if ($meta['current_page'] !== $page || $meta['total'] !== $total || $meta['last_page'] !== $last || $meta['per_page'] !== $perPage
                || $last !== max(1, (int) ceil($total / $perPage))
                || count($body['data']) !== ($page < $last ? $perPage : $total - ($last - 1) * $perPage)) {
                throw new RuntimeException('Inconsistent pagination.');
            }
            foreach ($body['data'] as $item) {
                $row = $this->normalize($item);
                if (isset($rows[$row['source_id']])) {
                    throw new RuntimeException('Duplicate stable identity.');
                }
                $rows[$row['source_id']] = $row;
            }
            $next = $body['links']['next'];
            if ($next === null) {
                if ($page !== $last || count($rows) !== $total) {
                    throw new RuntimeException('Truncated catalog.');
                }
                return [$rows, $page];
            }
            if ($page >= $last) {
                throw new RuntimeException('Unexpected extra page.');
            }
            $url = str_starts_with($next, '?') ? self::ENDPOINT.$next : (str_starts_with($next, '/') ? 'https://jakawi.com'.$next : $next);
        }
        throw new RuntimeException('Pagination limit.');
    }

    private function validPageUrl(string $url): bool
    {
        $p = parse_url($url);
        return $p !== false && ($p['scheme'] ?? '') === 'https' && ($p['host'] ?? '') === 'jakawi.com'
            && ($p['path'] ?? '') === '/api/catalog/v1/offers'
            && ! isset($p['port']) && ! isset($p['user']) && ! isset($p['pass']) && ! isset($p['fragment']);
    }

    private function normalize(mixed $item): array
    {
        Validator::make(is_array($item) ? $item : [], [
            'id' => ['required', 'string', 'max:80', 'regex:/^(benefit|experience):[1-9][0-9]*$/D'],
            'com_id' => ['required', 'integer', 'min:1'], 'type' => ['required', Rule::in(['benefit', 'experience'])],
            'name' => ['required', 'string', 'max:190'], 'short_description' => ['present', 'nullable', 'string', 'max:10000'],
            'image_url' => ['present', 'nullable', 'url:https', 'max:2048'], 'landing_url' => ['required', 'string', 'max:2048'],
            'partners' => ['required', 'array', 'list', 'min:1'], 'partners.*.id' => ['required', 'integer', 'min:1'],
            'partners.*.name' => ['required', 'string', 'max:190'], 'cities' => ['present', 'array', 'list'], 'cities.*' => ['string', 'max:190'],
            'category' => ['present', 'nullable', 'string', 'max:120'], 'publication_status' => ['required', Rule::in(['published', 'paused', 'archived'])],
            'landing_status' => ['required', Rule::in(['PUBLISHED'])],
            'availability_status' => ['required', Rule::in(['available', 'scheduled', 'upcoming', 'paused', 'unavailable', 'expired', 'ended', 'archived'])],
            'updated_at' => ['required', 'date'], 'starts_at' => ['sometimes', 'nullable', 'date'], 'ends_at' => ['sometimes', 'nullable', 'date'],
        ])->validate();
        if ($item['id'] !== $item['type'].':'.$item['com_id'] || ! app(AffiliateShortLinks::class)->validDestination($item['landing_url'])
            || ! preg_match('~^/l/[^/]+$~D', parse_url($item['landing_url'], PHP_URL_PATH) ?? '')) {
            throw new RuntimeException('Invalid stable identity or destination.');
        }
        $date = fn (?string $value) => $value === null ? null : Carbon::parse($value)->utc()->toIso8601String();
        return [
            'source' => 'com', 'source_id' => $item['id'], 'type' => $item['type'], 'name' => $item['name'],
            'short_description' => $item['short_description'], 'image_url' => $item['image_url'],
            'partners' => json_encode(array_map(fn (array $partner): array => ['id' => $partner['id'], 'name' => $partner['name']], $item['partners']), JSON_THROW_ON_ERROR), 'cities' => json_encode($item['cities'], JSON_THROW_ON_ERROR),
            'partner_search' => implode(' ', array_column($item['partners'], 'name')), 'category' => $item['category'],
            'destination_url' => $item['landing_url'], 'publication_status' => $item['publication_status'],
            'landing_status' => $item['landing_status'], 'availability_status' => $item['availability_status'],
            'starts_at' => $date($item['starts_at'] ?? null), 'ends_at' => $date($item['ends_at'] ?? null),
            'source_updated_at' => $date($item['updated_at']), 'last_synced_at' => now(), 'catalog_present' => true, 'updated_at' => now(),
        ];
    }
}
