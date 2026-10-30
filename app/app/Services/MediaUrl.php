<?php

namespace App\Services;

use App\Support\LaunchSettings;

final class MediaUrl
{
    public function legacy(?string $value): ?string
    {
        return LaunchSettings::videoPoster($value) ?? LaunchSettings::httpsUrl($value);
    }

    public function validReference(?string $value): bool
    {
        return $value === null || app(MediaUploadService::class)->isMediaKey($value) || $this->legacy($value) !== null;
    }

    public function url(?string $key, string $preset = 'card'): ?string
    {
        if (!$key) return null;
        $media = app(MediaUploadService::class);
        if (!$media->isMediaKey($key) && !$media->isTemporaryKey($key)) return $this->legacy($key);
        $options = config('media.presets.'.$preset);
        if (!$options || !LaunchSettings::httpsUrl(config('media.imgproxy_url')) || !config('media.imgproxy_key') || !config('media.imgproxy_salt')) return null;
        $path = sprintf('/rs:fill:%d:%d/q:%d/bg:fffdf9/f:%s/plain/s3://%s/%s',
            $options['width'], $options['height'], $options['quality'], $options['format'], config('media.bucket'), $key);
        $signature = hash_hmac('sha256', $this->decode((string)config('media.imgproxy_salt')).$path, $this->decode((string)config('media.imgproxy_key')), true);
        return config('media.imgproxy_url').'/'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=').$path;
    }

    private function decode(string $value): string
    {
        if (ctype_xdigit($value) && strlen($value) % 2 === 0) return hex2bin($value) ?: $value;
        return base64_decode(strtr($value, '-_', '+/'), true) ?: $value;
    }
}
