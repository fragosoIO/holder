<?php

declare(strict_types=1);

namespace App\Domain\Github;

use App\Domain\HolderException;

final class GithubUrl
{
    public static function canonicalize(string $url): string
    {
        $parts = parse_url(trim($url));
        $scheme = is_array($parts) ? (string) ($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        $hasExtra = is_array($parts) && (
            isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || isset($parts['query']) || isset($parts['fragment'])
        );
        if (str_ends_with($path, '/')) {
            $path = substr($path, 0, -1);
        }
        $segments = explode('/', $path);
        if ($segments !== [] && $segments[0] === '') {
            array_shift($segments);
        }
        if (
            $hasExtra
            || strtolower($scheme) !== 'https'
            || strtolower($host) !== 'github.com'
            || count($segments) !== 2
        ) {
            throw new HolderException('invalid_repo_url', 'Repository URL must be https://github.com/owner/repo.', 422);
        }
        $repo = $segments[1];
        if (str_ends_with($repo, '.git')) {
            $repo = substr($repo, 0, -4);
        }
        if (!self::segment($segments[0], 39) || !self::segment($repo, 100)) {
            throw new HolderException('invalid_repo_url', 'Repository URL must be https://github.com/owner/repo.', 422);
        }

        return 'https://github.com/' . $segments[0] . '/' . $repo;
    }

    private static function segment(string $value, int $max): bool
    {
        return $value !== '.'
            && $value !== '..'
            && strlen($value) >= 1
            && strlen($value) <= $max
            && preg_match('/^[A-Za-z0-9._-]+$/', $value) === 1;
    }
}
