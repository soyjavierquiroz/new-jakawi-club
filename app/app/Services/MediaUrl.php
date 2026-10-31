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
        return $this->signed('s3://'.config('media.bucket').'/'.$key, $preset);
    }

    public function social(?string $reference): ?string
    {
        if (!$reference) return null;
        if (app(MediaUploadService::class)->isMediaKey($reference)) return $this->url($reference, 'social');
        $url = $this->legacy($reference);
        if (!$url) return null;
        // Reuse the original object behind COM's synchronized imgproxy URL.
        $proxy = rtrim((string) config('media.imgproxy_url'), '/').'/';
        $sourcePrefix = '/plain/s3://'.config('media.bucket').'/';
        if (str_starts_with($url, $proxy) && str_contains($url, $sourcePrefix)) {
            $key = explode($sourcePrefix, $url, 2)[1];
            if (preg_match('~^[a-zA-Z0-9/_\-.]+$~D', $key) && !str_contains($key, '..')) {
                return $this->signed('s3://'.config('media.bucket').'/'.$key, 'social');
            }
            return null;
        }
        if (str_starts_with($url, '/marketing/')) {
            return $this->signed('s3://'.config('media.bucket').'/'.$this->localSocialKey($url), 'social');
        }
        return null;
    }

    public function localSocialKey(string $path): string
    {
        $file = public_path(ltrim($path, '/'));
        return 'club/social/'.hash_file('sha256', $file).'.'.strtolower(pathinfo($file, PATHINFO_EXTENSION));
    }

    private function signed(string $source, string $preset): ?string
    {
        $options = config('media.presets.'.$preset);
        if (!$options || !LaunchSettings::httpsUrl(config('media.imgproxy_url')) || !config('media.imgproxy_key') || !config('media.imgproxy_salt')) return null;
        $encoded = str_starts_with($source, 's3://') ? 'plain/'.$source : rtrim(strtr(base64_encode($source), '+/', '-_'), '=');
        $path = sprintf('/rs:fill:%d:%d%s/q:%d/bg:fffdf9/f:%s/%s',
            $options['width'], $options['height'], $preset === 'social' ? ':1' : '', $options['quality'], $options['format'], $encoded);
        $signature = hash_hmac('sha256', $this->decode((string)config('media.imgproxy_salt')).$path, $this->decode((string)config('media.imgproxy_key')), true);
        return config('media.imgproxy_url').'/'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=').$path;
    }

    private function decode(string $value): string
    {
        if (ctype_xdigit($value) && strlen($value) % 2 === 0) return hex2bin($value) ?: $value;
        return base64_decode(strtr($value, '-_', '+/'), true) ?: $value;
    }
}
