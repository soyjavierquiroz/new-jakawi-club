<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

final class LandingSlug
{
    /** Include namespaces even when their only real endpoint uses POST or a child path. */
    public static function reserved(): array
    {
        $reserved = ['login', 'registro', 'register', 'logout', 'dashboard', 'profile', 'admin', 'r', 'l', 'up', 'privacy', 'preferencias', 'password', 'email', 'storage', 'build', 'api'];
        foreach (Route::getRoutes() as $route) {
            $root = explode('/', trim($route->uri(), '/'))[0];
            if ($root !== '' && ! str_contains($root, '{')) {
                $reserved[] = $root;
            }
        }
        return array_values(array_unique($reserved));
    }

    public static function allowed(string $slug): bool
    {
        return strlen($slug) <= 100 && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)
            && ! in_array($slug, self::reserved(), true);
    }

    public static function pattern(): string
    {
        return '(?!(?:'.implode('|', array_map(fn (string $slug): string => preg_quote($slug, '~'), self::reserved())).')$)[a-z0-9]+(?:-[a-z0-9]+)*';
    }
}
