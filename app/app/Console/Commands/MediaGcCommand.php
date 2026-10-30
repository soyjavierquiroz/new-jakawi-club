<?php

namespace App\Console\Commands;

use App\Services\MediaReferences;
use App\Services\MediaUploadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MediaGcCommand extends Command
{
    protected $signature = 'media:gc {--dry-run : Report candidates without deleting}';

    protected $description = 'Collect unreferenced JAKAWI media after 48h and temporary uploads after 24h';

    public function handle(MediaReferences $references, MediaUploadService $media): int
    {
        $stats = array_fill_keys(['scanned', 'referenced', 'recent_orphans_skipped', 'temp_expired', 'orphan_candidates_recorded', 'candidates', 'deleted', 'bytes_reclaimed', 'errors'], 0);
        try {
            if (config('media.disk') !== 'minio-media') throw new \RuntimeException('CLUB GC requires MinIO');
            $disk = Storage::disk(config('media.disk')); 
            $referenceSet = $references->all();
            foreach (['club/landings', 'club/tmp'] as $prefix) {
                foreach ($disk->allFiles($prefix) as $key) {
                    if (! $media->isMediaKey($key) && ! $media->isTemporaryKey($key)) {
                        continue;
                    }
                    $stats['scanned']++;
                    $marker = 'club/gc/'.hash('sha256', $key).'.json';
                    if (isset($referenceSet[$key])) {
                        $stats['referenced']++;
                        if (! $this->option('dry-run') && $disk->exists($marker)) {
                            $disk->delete($marker);
                        }

                        continue;
                    }
                    try {
                        $temp = $media->isTemporaryKey($key);
                        // Grace begins when GC observes the missing reference, not when an old photo was uploaded.
                        // Small private markers avoid a DB media model and survive scheduler restarts.
                        if (! $temp) {
                            if (! $disk->exists($marker)) {
                                if (! $this->option('dry-run')) {
                                    if (! $disk->put($marker, json_encode(['key' => $key, 'since' => now()->timestamp], JSON_THROW_ON_ERROR), ['visibility' => 'private'])) {
                                        throw new \RuntimeException('Candidate write failed');
                                    }
                                }
                                $stats['orphan_candidates_recorded']++;
                                $stats['recent_orphans_skipped']++;

                                continue;
                            }
                            $candidate = json_decode($disk->get($marker), true, 512, JSON_THROW_ON_ERROR);
                            if (($candidate['key'] ?? null) !== $key || ! is_int($candidate['since'] ?? null)) {
                                throw new \RuntimeException('Invalid candidate marker');
                            }
                            if ($candidate['since'] >= now()->subHours(48)->timestamp) {
                                $stats['recent_orphans_skipped']++;

                                continue;
                            }
                        }
                        if ($disk->lastModified($key) >= now()->subHours($temp ? 24 : 48)->timestamp) {
                            $stats['recent_orphans_skipped']++;

                            continue;
                        }
                        $bytes = $this->option('dry-run') ? 0 : $disk->size($key);
                        if ($temp) {
                            $stats['temp_expired']++;
                        }
                        $stats['candidates']++;
                        if (! $this->option('dry-run')) {
                            // The editor also writes marketing_landings inside transactions.
                            // Prevent reattaching an old key between the final check and deletion.
                            \Illuminate\Support\Facades\DB::transaction(function () use ($references, $disk, $key, $temp, $marker, $bytes, &$stats): void {
                                \Illuminate\Support\Facades\DB::statement('LOCK TABLE marketing_landings IN SHARE ROW EXCLUSIVE MODE');
                                if ($references->contains($key)) {
                                    $stats['referenced']++;
                                    $disk->delete($marker);
                                    return;
                                }
                                if (!$disk->delete($key)) throw new \RuntimeException('Delete failed');
                                if (!$temp) $disk->delete($marker);
                                $stats['deleted']++;
                                $stats['bytes_reclaimed'] += $bytes;
                            });
                        }
                    } catch (\Throwable $exception) {
                        $stats['errors']++;
                        $this->error('No se pudo procesar un objeto CLUB.');
                    }
                }
            }
        } catch (\Throwable $exception) {
            $stats['errors']++;
            $this->error('No se pudo completar GC de CLUB. Verifica la configuración privada.');
        }
        $this->line(json_encode($stats, JSON_THROW_ON_ERROR));

        return $stats['errors'] ? self::FAILURE : self::SUCCESS;
    }
}
