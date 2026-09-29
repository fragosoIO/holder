<?php

declare(strict_types=1);

namespace App\Domain\Github;

use App\Domain\HolderConfig;
use App\Domain\HolderException;

final class RepoCheckout
{
    public function __construct(
        private readonly HolderConfig $config,
        private readonly GitClient $git,
    ) {}

    public function directory(string $projectId): string
    {
        return $this->config->dataDir . '/repos/' . $projectId;
    }

    public function deleteRepository(string $projectId): void
    {
        $this->delete($this->directory($projectId));
    }

    public function cloneRepository(string $projectId, string $canonicalUrl, string $token): string
    {
        // $token is an argument. Traces record arguments while this is off.
        $ignoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '1');
        try {
            $this->git->assertGit();
            $dest = $this->directory($projectId);
            $this->delete($dest);
            try {
                $this->git->run(['clone', '--origin', 'origin', $canonicalUrl, $dest], $token);
                $result = $this->git->capture(
                    ['-C', $dest, 'symbolic-ref', '--short', 'refs/remotes/origin/HEAD'],
                    $token,
                );
                if ($result->exit !== 0) {
                    throw new HolderException(
                        'github_clone_failed',
                        'github_clone_failed: ' . $this->firstLine($this->git->redact($result->stderr, $token)),
                        422,
                    );
                }

                return $this->branchName($result->stdout);
            } catch (\Throwable $error) {
                $this->delete($dest);
                throw $error;
            }
        } finally {
            ini_set('zend.exception_ignore_args', $ignoreArgs === false ? '0' : $ignoreArgs);
        }
    }

    private function branchName(string $stdout): string
    {
        $branch = trim($stdout);
        if (str_starts_with($branch, 'origin/')) {
            return substr($branch, strlen('origin/'));
        }

        return $branch;
    }

    private function firstLine(string $text): string
    {
        $lines = preg_split("/\R/", $text, 2);

        return trim(is_array($lines) ? $lines[0] : $text);
    }

    private function delete(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->delete($path . '/' . $item);
        }
        rmdir($path);
    }
}
