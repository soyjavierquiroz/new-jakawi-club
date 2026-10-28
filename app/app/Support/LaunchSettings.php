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
            'video_url' => self::httpsUrl(config('jakawi.video_url')),
            'event' => $title !== '' && $date ? [
                'title' => $title,
                'starts_at' => $date,
                'zoom_url' => $private ? self::httpsUrl(config('jakawi.event.zoom_url')) : null,
            ] : null,
            'whatsapp_url' => self::httpsUrl(config('jakawi.whatsapp_url')),
        ];
    }
}
