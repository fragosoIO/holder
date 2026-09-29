<?php

declare(strict_types=1);

namespace App\Api;

use Psr\Http\Message\ResponseInterface;

final class Cookies
{
    public static function withSession(ResponseInterface $response, string $token): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', self::header($token, 60 * 60 * 24 * 30));
    }

    public static function clearSession(ResponseInterface $response): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', self::header('', 0));
    }

    private static function header(string $token, int $maxAge): string
    {
        return 'holder_session=' . rawurlencode($token)
            . '; Path=/; HttpOnly; SameSite=Lax; Max-Age=' . $maxAge;
    }
}
