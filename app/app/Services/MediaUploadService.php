<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MediaUploadService
{
    public function temporary(UploadedFile $file, int $userId, string $purpose = 'card'): array
    {
        if ($purpose === 'opportunity' && $file->getSize() > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['image' => 'La imagen no debe superar 5 MiB.']);
        }
        $key = 'club/tmp/'.Str::uuid().'/master.webp';
        $this->write($key, $this->normalize($file));
        return ['token' => Crypt::encryptString(json_encode(['key' => $key, 'purpose' => $purpose, 'user' => $userId, 'expires' => now()->addHours(24)->timestamp], JSON_THROW_ON_ERROR)),
            'url' => app(MediaUrl::class)->url($key, $purpose === 'opportunity' ? 'opportunity' : 'thumbnail'),
            'metadata' => $this->metadata($key)];
    }

    public function promote(string $encrypted, int $userId, int $landingId, string $purpose = 'card'): string
    {
        try {
            $token = json_decode(Crypt::decryptString($encrypted), true, 16, JSON_THROW_ON_ERROR);
            if (($token['purpose'] ?? 'card') !== $purpose || ($token['user'] ?? null) !== $userId || !is_int($token['expires'] ?? null)
                || $token['expires'] <= now()->timestamp || !$this->isTemporaryKey($token['key'] ?? null) || $landingId < 1) {
                throw new \RuntimeException('Invalid upload token');
            }
            $disk = Storage::disk(config('media.disk'));
            $content = $disk->get($token['key']);
            if (!is_string($content) || $content === '') throw new \RuntimeException('Missing upload');
            $key = 'club/landings/'.$landingId.'/cover/'.Str::uuid().'.webp';
            $this->write($key, $content);
            // Temporary objects expire independently; old permanent references are never deleted here.
            return $key;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['commercial_image_upload' => 'La subida caducó, no te pertenece o no está disponible. Selecciona la imagen de nuevo; la anterior se conserva.']);
        }
    }

    /** Existing object keys are the stable asset identifiers; no second asset registry. */
    public function usableAsset(mixed $key): bool
    {
        try {
            return is_string($key) && $this->isMediaKey($key)
                && app(MediaReferences::class)->contains($key)
                && Storage::disk(config('media.disk'))->exists($key);
        } catch (\Throwable) {
            return false;
        }
    }

    public function metadata(?string $key): ?array
    {
        if (!$this->isMediaKey($key) && !$this->isTemporaryKey($key)) return null;
        try {
            $bytes = Storage::disk(config('media.disk'))->get($key);
            $size = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
            return $size ? ['format' => $size['mime'], 'bytes' => strlen($bytes), 'width' => $size[0], 'height' => $size[1]] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function write(string $key, string $content): void
    {
        if (config('media.disk') !== 'minio-media') throw new \RuntimeException('CLUB media requires MinIO');
        if (!$this->isTemporaryKey($key) && !$this->isMediaKey($key)) throw new \InvalidArgumentException('Invalid CLUB media key');
        $disk = Storage::disk(config('media.disk'));
        if (!$disk->put($key, $content, ['visibility' => 'private', 'ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'])
            || !$disk->exists($key) || $disk->size($key) !== strlen($content)) {
            throw new \RuntimeException('Media write verification failed');
        }
    }

    public function validate(UploadedFile $file): void
    {
        if (! $file->isValid() || ! in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true) || $file->getSize() > config('media.max_bytes')) {
            throw ValidationException::withMessages(['image' => 'La imagen debe ser JPEG, PNG o WebP y no superar 10 MB.']);
        }
        $dimensions = @getimagesize($file->getRealPath());
        if ($dimensions === false || $dimensions[0] < 1 || $dimensions[1] < 1 || max($dimensions[0], $dimensions[1]) > 16000 || config('media.max_pixels') < $dimensions[0] * $dimensions[1]) {
            throw ValidationException::withMessages(['image' => 'Las dimensiones de la imagen no son válidas.']);
        }
        $decoded = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        if ($decoded === false) {
            throw ValidationException::withMessages(['image' => 'No pudimos leer la imagen. Selecciona un archivo válido.']);
        }
        imagedestroy($decoded);
    }

    private function normalize(UploadedFile $file): string
    {
        $this->validate($file);
        $image = imagecreatefromstring(file_get_contents($file->getRealPath()));
        $ratio = min(1, 1600 / max(imagesx($image), imagesy($image)));
        $output = imagecreatetruecolor(max(1, (int) round(imagesx($image) * $ratio)), max(1, (int) round(imagesy($image) * $ratio)));
        imagealphablending($output, false);
        imagesavealpha($output, true);
        imagecopyresampled($output, $image, 0, 0, 0, 0, imagesx($output), imagesy($output), imagesx($image), imagesy($image));
        // Resize before EXIF rotation: never allocate two full 24 MP canvases.
        imagedestroy($image);
        unset($image);
        if ($file->getMimeType() === 'image/jpeg') {
            $orientation = (int) (@exif_read_data($file->getRealPath())['Orientation'] ?? 1);
            if (in_array($orientation, [2, 4, 5, 7], true)) {
                imageflip($output, IMG_FLIP_HORIZONTAL);
            }
            $angle = match ($orientation) {
                3, 4 => 180, 5, 6 => -90, 7, 8 => 90, default => 0
            };
            if ($angle) {
                $rotated = imagerotate($output, $angle, 0);
                imagedestroy($output);
                $output = $rotated;
            }
        }
        if (!function_exists('imagewebp')) {
            imagedestroy($output);
            throw ValidationException::withMessages(['image' => 'No está disponible la optimización WebP con transparencia.']);
        }
        ob_start();
        try {
            if (! imagewebp($output, null, 82)) {
                throw new \RuntimeException('Image encoding failed');
            }

            return ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($output);
        }
    }

    public function isTemporaryKey(?string $key): bool
    {
        return is_string($key) && preg_match('#^club/tmp/[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}/master\.webp$#D', $key) === 1;
    }

    public function isMediaKey(?string $key): bool
    {
        return is_string($key) && preg_match('#^club/landings/[1-9][0-9]*/cover/[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\.webp$#D', $key) === 1;
    }
}
