<?php

declare(strict_types=1);

namespace App\Api;

final class LocalRequest
{
    public static function trusted(string $remote, string $host): bool
    {
        $remote = strtolower($remote);
        if (str_starts_with($remote, '::ffff:')) {
            $remote = substr($remote, 7);
        }
        if ($remote === '127.0.0.1' || $remote === '::1') {
            return true;
        }
        if (!self::privateAddress($remote)) {
            return false;
        }

        $host = strtolower($host);

        return $host === 'localhost'
            || $host === '127.0.0.1'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test');
    }

    private static function privateAddress(string $remote): bool
    {
        if (filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return filter_var(
                $remote,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false;
        }
        if (filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false;
        }

        return str_starts_with($remote, 'fc')
            || str_starts_with($remote, 'fd')
            || str_starts_with($remote, 'fe80:');
    }
}
