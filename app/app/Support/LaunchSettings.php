<?php

namespace App\Support;

class LaunchSettings
{
    public static function mailReady(): bool
    {
        $mailer = config('mail.mailers.'.config('mail.default'));
        $host = strtolower((string) ($mailer['host'] ?? ''));
        $from = (string) config('mail.from.address');

        // Explicit operational approval plus a real SMTP transport. Never fall back to log.
        return config('jakawi.transactional_mail_ready') === true
            && ($mailer['transport'] ?? null) === 'smtp'
            && $host !== ''
            && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            && filter_var($from, FILTER_VALIDATE_EMAIL) !== false
            && ! str_ends_with($from, '@example.com');
    }

    public static function registrationOpen(): bool
    {
        return config('jakawi.registration_open') === true && self::mailReady();
    }

    public static function httpsUrl(mixed $value): ?string
    {
        if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_URL)
            || parse_url($value, PHP_URL_SCHEME) !== 'https'
            || parse_url($value, PHP_URL_USER) !== null) {
            return null;
        }

        return $value;
    }

    public static function youtubeUrl(mixed $value): ?string
    {
        $url = self::httpsUrl($value);
        if (! $url || parse_url($url, PHP_URL_PASS) !== null || parse_url($url, PHP_URL_PORT) !== null) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        $id = null;
        if ($host === 'youtu.be' && preg_match('~^/([\w-]{11})/?$~D', $path, $match)) {
            $id = $match[1];
        } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'], true)) {
            if ($path === '/watch') {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $id = $query['v'] ?? null;
            } elseif (preg_match('~^/(?:embed|shorts|live|v)/([\w-]{11})/?$~D', $path, $match)) {
                $id = $match[1];
            }
        }

        return is_string($id) && preg_match('/^[\w-]{11}$/D', $id) ? 'https://www.youtube.com/watch?v='.$id : null;
    }

    public static function videoPoster(mixed $value): ?string
    {
        // Posters are local files under public/; no remote tracking image or SVG uploads.
        if (! is_string($value) || ! preg_match('~^/marketing/[a-zA-Z0-9/_-]+\.(?:webp|png|jpe?g)$~D', $value)) {
            return null;
        }

        return is_file(public_path(ltrim($value, '/'))) ? $value : null;
    }

    public static function defaultVideoUrl(mixed $value): ?string
    {
        return self::youtubeUrl($value);
    }

    /** @return array<string, mixed> */
    public static function publicData(bool $private = false): array
    {
        $title = trim((string) config('jakawi.event.title'));
        $startsAt = config('jakawi.event.starts_at');
        $date = null;
        if (is_string($startsAt) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $startsAt)) {
            try {
                $date = \Carbon\CarbonImmutable::parse($startsAt)->toIso8601String();
            } catch (\Exception) {
                $date = null;
            }
        }

        return [
            'registration_open' => self::registrationOpen(),
            'video_url' => self::youtubeUrl(config('jakawi.video_url')),
            'video_poster' => self::videoPoster(config('jakawi.video_poster')),
            'event' => $title !== '' && $date ? [
                'title' => $title,
                'starts_at' => $date,
                'zoom_url' => $private ? self::httpsUrl(config('jakawi.event.zoom_url')) : null,
            ] : null,
            'whatsapp_url' => RegistrationNextStep::whatsappGroup(),
        ];
    }
}
