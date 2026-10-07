<?php

declare(strict_types=1);

namespace App\Domain\Work;

final class PreviewSite
{
    private const ROOTS = ['index.html', 'site/index.html', 'public/index.html', 'dist/index.html'];

    private const TYPES = [
        'html' => 'text/html; charset=utf-8',
        'htm' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'json' => 'application/json',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'txt' => 'text/plain; charset=utf-8',
        'map' => 'application/json',
        'webmanifest' => 'application/manifest+json',
    ];

    public function root(string $directory): ?string
    {
        $base = realpath($directory);
        if ($base === false || !is_dir($base)) {
            return null;
        }
        foreach (self::ROOTS as $relative) {
            $file = $this->file($base, $relative);
            if ($file !== null) {
                return dirname($file);
            }
        }

        return null;
    }

    public function file(string $siteRoot, string $relative): ?string
    {
        $root = realpath($siteRoot);
        if ($root === false || !is_dir($root) || !$this->segments($relative)) {
            return null;
        }
        if ($this->contentType($relative) === null) {
            return null;
        }
        $candidate = $root . '/' . $relative;
        if (is_link($candidate)) {
            $target = realpath($candidate);
            if ($target === false || !$this->inside($root, $target)) {
                return null;
            }
        }
        if (!is_file($candidate)) {
            return null;
        }
        $real = realpath($candidate);
        if ($real === false || !is_file($real) || !$this->inside($root, $real)) {
            return null;
        }

        return $real;
    }

    public function revision(string $siteRoot): string
    {
        $root = realpath($siteRoot);
        if ($root === false || !is_dir($root)) {
            return '';
        }
        $records = [];
        $this->collect($root, '', $records);
        sort($records, SORT_STRING);

        return hash('sha256', implode('', $records));
    }

    public function contentType(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::TYPES[$extension] ?? null;
    }

    private function segments(string $relative): bool
    {
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '\\')) {
            return false;
        }
        foreach (explode('/', $relative) as $segment) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || $segment === '.git'
                || $segment === 'node_modules'
                || str_starts_with($segment, '.')
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $records
     */
    private function collect(string $directory, string $relative, array &$records): void
    {
        $handle = opendir($directory);
        if ($handle === false) {
            return;
        }
        while (($name = readdir($handle)) !== false) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.') || $name === 'node_modules') {
                continue;
            }
            $full = $directory . '/' . $name;
            if (is_link($full)) {
                continue;
            }
            $child = $relative === '' ? $name : $relative . '/' . $name;
            if (is_dir($full)) {
                $this->collect($full, $child, $records);
                continue;
            }
            if (!is_file($full) || $this->contentType($name) === null) {
                continue;
            }
            $records[] = $child . "\n" . filemtime($full) . "\n" . filesize($full) . "\n";
        }
        closedir($handle);
    }

    private function inside(string $root, string $path): bool
    {
        return $path === $root || str_starts_with($path, $root . '/');
    }
}
