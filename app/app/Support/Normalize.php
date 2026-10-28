<?php

namespace App\Support;

class Normalize
{
    public static function email(mixed $value): string
    {
        return mb_strtolower(trim(is_string($value) ? $value : ''));
    }

    public static function phone(mixed $value): string
    {
        return preg_replace('/[\s().-]+/u', '', is_string($value) ? $value : '') ?? '';
    }

    public static function name(mixed $value): string
    {
        return preg_replace('/\s+/u', ' ', trim(is_string($value) ? $value : '')) ?? '';
    }
}
