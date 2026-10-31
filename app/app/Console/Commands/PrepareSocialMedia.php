<?php

namespace App\Console\Commands;

use App\Services\MediaUrl;
use App\Support\LaunchSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class PrepareSocialMedia extends Command
{
    protected $signature = 'media:prepare-social';
    protected $description = 'Reuse local marketing images in existing MinIO for social imgproxy variants';

    public function handle(MediaUrl $media): int
    {
        $count = 0;
        $disk = Storage::disk(config('media.disk'));
        // Local assets only; no snapshots, video posters or upstream systems are changed.
        foreach (glob(public_path('marketing/*')) ?: [] as $file) {
            $path = '/marketing/'.basename($file);
            if (!is_file($file) || !LaunchSettings::videoPoster($path)) continue;
            $key = $media->localSocialKey($path);
            if (!$disk->exists($key) && !$disk->put($key, file_get_contents($file), [
                'visibility' => 'private', 'ContentType' => mime_content_type($file), 'CacheControl' => 'public, max-age=31536000, immutable',
            ])) {
                $this->error('No se pudo preparar una imagen social CLUB.');
                return self::FAILURE;
            }
            $count++;
        }
        $this->info('Medios locales reutilizados en MinIO: '.$count);
        return self::SUCCESS;
    }
}
