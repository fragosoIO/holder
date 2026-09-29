<?php

declare(strict_types=1);

namespace App\Domain\Github;

use App\Domain\HolderConfig;
use App\Domain\HolderException;

final class GitClient
{
    private readonly string $git;

    private readonly string $gh;

    public function __construct(
        private readonly HolderConfig $config,
        ?string $gitBinary = null,
        ?string $ghBinary = null,
    ) {
        $this->git = $gitBinary ?? self::binaryFromEnv('HOLDER_GIT', 'git');
        $this->gh = $ghBinary ?? self::binaryFromEnv('HOLDER_GH', 'gh');
    }

    public function assertGit(): void
    {
        $result = $this->execute($this->git, ['--version'], null, 'git_unavailable');
        if ($result->exit !== 0) {
            throw new HolderException('git_unavailable', 'git_unavailable', 422);
        }
    }

    public function assertGh(): void
    {
        $result = $this->execute($this->gh, ['--version'], null, 'gh_unavailable');
        if ($result->exit !== 0) {
            throw new HolderException('gh_unavailable', 'gh_unavailable', 422);
        }
    }

    /**
     * @param list<string> $args
     */
    public function run(array $args, string $token): GitResult
    {
        // Traces record arguments while this is off, which would keep the token.
        $ignoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '1');
        try {
            $result = $this->execute($this->git, $args, $token, 'git_unavailable');
            if ($result->exit !== 0) {
                throw new HolderException(
                    'github_clone_failed',
                    'github_clone_failed: ' . $this->oneLine($this->redact($result->stderr, $token)),
                    422,
                );
            }

            return $result;
        } finally {
            ini_set('zend.exception_ignore_args', $ignoreArgs === false ? '0' : $ignoreArgs);
        }
    }

    /**
     * @param list<string> $args
     */
    public function capture(array $args, string $token): GitResult
    {
        $ignoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '1');
        try {
            return $this->execute($this->git, $args, $token, 'git_unavailable');
        } finally {
            ini_set('zend.exception_ignore_args', $ignoreArgs === false ? '0' : $ignoreArgs);
        }
    }

    public function redact(string $text, string $token): string
    {
        if ($token !== '') {
            $text = str_replace($token, '[redacted]', $text);
        }
        $stripped = preg_replace('#https://[^/\s@]+@#', 'https://', $text);

        return is_string($stripped) ? $stripped : $text;
    }

    /**
     * @param list<string> $args
     */
    private function execute(string $binary, array $args, ?string $token, string $unavailable): GitResult
    {
        $command = [$binary, ...$args];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        set_error_handler(static fn(int $severity, string $message): bool => true);
        try {
            $process = proc_open(
                $command,
                $descriptors,
                $pipes,
                null,
                $token === null ? null : $this->environment($token),
                ['bypass_shell' => true],
            );
        } catch (\Throwable) {
            $process = false;
        } finally {
            restore_error_handler();
        }
        if (!is_resource($process)) {
            throw new HolderException($unavailable, $unavailable, 422);
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return new GitResult(
            proc_close($process),
            is_string($stdout) ? $stdout : '',
            is_string($stderr) ? $stderr : '',
        );
    }

    /**
     * @return array<string, string>
     */
    private function environment(string $token): array
    {
        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        $stringEnv = [];
        foreach ($env as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $stringEnv[$key] = $value;
            }
        }
        $stringEnv['GH_TOKEN'] = $token;
        $stringEnv['GIT_ASKPASS'] = dirname($this->config->binPath) . '/holder-git-askpass';
        $stringEnv['GIT_TERMINAL_PROMPT'] = '0';

        return $stringEnv;
    }

    private function oneLine(string $text): string
    {
        $lines = preg_split("/\R/", $text, 2);

        return trim(is_array($lines) ? $lines[0] : $text);
    }

    private static function binaryFromEnv(string $key, string $fallback): string
    {
        $value = getenv($key);

        return is_string($value) && $value !== '' ? $value : $fallback;
    }
}
