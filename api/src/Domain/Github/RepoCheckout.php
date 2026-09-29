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
                try {
                    $this->delete($dest);
                } catch (\Throwable) {
                    // Cleanup must not replace the git failure.
                }
                throw $error;
            }
        } finally {
            ini_set('zend.exception_ignore_args', $ignoreArgs === false ? '0' : $ignoreArgs);
        }
    }

    /**
     * @return array{worktree: string, defaultBranch: string}
     */
    public function prepare(
        string $projectId,
        string $taskId,
        string $repoUrl,
        string $defaultBranch,
        string $token,
    ): array {
        // $token is an argument. Traces record arguments while this is off.
        $ignoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '1');
        try {
            if ($token === '') {
                throw new HolderException('github_token_missing', 'github_token_missing', 422);
            }

            $lock = $this->openLock($projectId);
            try {
                $clone = $this->directory($projectId);
                $branch = $defaultBranch;
                if (!is_dir($clone)) {
                    $branch = $this->cloneRepository($projectId, $repoUrl, $token);
                }
                $this->git->run(['-C', $clone, 'fetch', 'origin'], $token);

                $worktree = $this->worktreePath($taskId);
                $listed = $this->git->capture(['-C', $clone, 'worktree', 'list', '--porcelain'], $token);
                if ($listed->exit !== 0) {
                    throw new HolderException(
                        'github_clone_failed',
                        'github_clone_failed: ' . $this->firstLine($this->git->redact($listed->stderr, $token)),
                        422,
                    );
                }
                if ($this->listsWorktree($listed->stdout, $worktree)) {
                    return ['worktree' => $worktree, 'defaultBranch' => $branch];
                }
                if (is_link($worktree) || file_exists($worktree)) {
                    $this->delete($worktree);
                }

                $name = 'holder/' . $taskId;
                $local = $this->git->capture(
                    ['-C', $clone, 'rev-parse', '--verify', '--quiet', 'refs/heads/' . $name],
                    $token,
                );
                if ($local->exit === 0) {
                    $this->git->run(['-C', $clone, 'worktree', 'add', $worktree, $name], $token);

                    return ['worktree' => $worktree, 'defaultBranch' => $branch];
                }

                $remote = $this->git->capture(
                    ['-C', $clone, 'rev-parse', '--verify', '--quiet', 'refs/remotes/origin/' . $name],
                    $token,
                );
                if ($remote->exit === 0) {
                    $this->git->run(
                        ['-C', $clone, 'worktree', 'add', '-b', $name, $worktree, 'origin/' . $name],
                        $token,
                    );

                    return ['worktree' => $worktree, 'defaultBranch' => $branch];
                }

                $this->git->run(
                    ['-C', $clone, 'worktree', 'add', '-b', $name, $worktree, 'origin/' . $branch],
                    $token,
                );

                return ['worktree' => $worktree, 'defaultBranch' => $branch];
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } finally {
            ini_set('zend.exception_ignore_args', $ignoreArgs === false ? '0' : $ignoreArgs);
        }
    }

    public function remove(string $projectId, string $taskId): void
    {
        $lock = $this->openLock($projectId);
        try {
            $clone = $this->directory($projectId);
            $this->runQuiet(['-C', $clone, 'worktree', 'remove', '--force', $this->worktreePath($taskId)]);
            $this->runQuiet(['-C', $clone, 'branch', '-D', 'holder/' . $taskId]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function worktreePath(string $taskId): string
    {
        return $this->config->dataDir . '/worktrees/' . $taskId;
    }

    private function listsWorktree(string $stdout, string $worktree): bool
    {
        return preg_match('/^worktree ' . preg_quote($worktree, '/') . '$/m', $stdout) === 1;
    }

    /**
     * @return resource
     */
    private function openLock(string $projectId)
    {
        $directory = $this->config->dataDir . '/repos';
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create the repository directory.');
        }
        $handle = fopen($directory . '/' . $projectId . '.lock', 'c');
        if ($handle === false) {
            throw new \RuntimeException('Could not open the repository lock.');
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new \RuntimeException('Could not lock the repository.');
        }

        return $handle;
    }

    /**
     * @param list<string> $args
     */
    private function runQuiet(array $args): void
    {
        try {
            $this->git->run($args, '');
        } catch (HolderException) {
            // Removal is best-effort: the worktree or branch may already be gone.
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
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $items = @scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->delete($path . '/' . $item);
        }
        @rmdir($path);
    }
}
