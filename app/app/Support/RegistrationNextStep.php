<?php

namespace App\Support;

final class RegistrationNextStep
{
    public static function whatsappGroup(): ?string
    {
        return self::validGroupUrl(config('jakawi.whatsapp_group_url'));
    }

    public static function validGroupUrl(mixed $url): ?string
    {
        // Exact HTTPS invitation host/path; no credentials, ports, redirects or suffix domains.
        return is_string($url) && preg_match('~^https://chat\.whatsapp\.com/[A-Za-z0-9]{10,64}$~D', $url) ? $url : null;
    }

    public static function landingGroup(array $content): ?string
    {
        $group = $content['whatsapp_group'] ?? [];
        return match ($group['mode'] ?? 'default') {
            'default' => self::whatsappGroup(),
            'custom' => self::validGroupUrl($group['url'] ?? null),
            default => null,
        };
    }

    public static function confirmationGroup(array $content): ?string
    {
        $group = $content['confirmation_whatsapp_group'] ?? [];
        return match ($group['mode'] ?? 'inherit') {
            'inherit' => self::landingGroup($content),
            'custom' => self::validGroupUrl($group['url'] ?? null),
            default => null,
        };
    }

    public static function resolve(array $settings, bool $activated, ?string $groupUrl = null): ?array
    {
        $action = $settings['action'] ?? 'recover';
        $url = match ($action) {
            'recover' => '/recuperar-password',
            'account' => $activated ? '/dashboard' : '/login',
            'home' => '/',
            'whatsapp' => self::validGroupUrl($groupUrl),
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
