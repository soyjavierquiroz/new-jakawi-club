<?php

namespace App\Support;

final class RegistrationNextStep
{
    public static function whatsappGroup(): ?string
    {
        $url = config('jakawi.whatsapp_group_url');

        // Only an operationally approved group invitation; never an editorial URL.
        return is_string($url) && preg_match('~^https://chat\.whatsapp\.com/[A-Za-z0-9]{10,64}$~D', $url) ? $url : null;
    }

    public static function resolve(array $settings, bool $activated): ?array
    {
        $action = $settings['action'] ?? 'recover';
        $url = match ($action) {
            'recover' => '/recuperar-password',
            'account' => $activated ? '/dashboard' : '/login',
            'home' => '/',
            'whatsapp' => self::whatsappGroup(),
            default => null,
        };
        if ($url === null) {
            return null;
        }

        $seconds = $settings['redirect_seconds'] ?? 0;

        return [
            'url' => $url, 'external' => $action === 'whatsapp',
            'redirect_seconds' => $action === 'whatsapp' && in_array($seconds, [5, 10, 15, 30, 60], true) ? $seconds : 0,
        ];
    }
}
