<?php

declare(strict_types=1);

namespace App\Shared;

final class Env
{
    public static function get(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if (($value === false || $value === '') && isset($_ENV[$key]) && is_string($_ENV[$key])) {
            $value = $_ENV[$key];
        }
        if (!is_string($value) || $value === '') {
            return $default;
        }

        return $value;
    }
}
