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
        [$stdout, $stderr] = $this->readPipes($pipes[1], $pipes[2]);

        return new GitResult(proc_close($process), $stdout, $stderr);
    }

    /**
     * Read stdout and stderr together until both reach EOF.
     *
     * A child that fills the stderr pipe before it exits never closes stdout.
     * Reading one pipe to completion first deadlocks, including bytes still
     * buffered after the process has gone.
     *
     * @param resource $stdoutPipe
     * @param resource $stderrPipe
     *
     * @return array{string, string}
     */
    private function readPipes($stdoutPipe, $stderrPipe): array
    {
        stream_set_blocking($stdoutPipe, false);
        stream_set_blocking($stderrPipe, false);
        stream_set_read_buffer($stdoutPipe, 0);
        stream_set_read_buffer($stderrPipe, 0);

        $stdout = '';
        $stderr = '';
        /** @var array<int, resource> $open */
        $open = [1 => $stdoutPipe, 2 => $stderrPipe];
        while ($open !== []) {
            $watch = array_values($open);
            $write = null;
            $except = null;
            $ready = @stream_select($watch, $write, $except, 0, 200000);
            $streams = is_int($ready) && $ready > 0 ? $watch : array_values($open);
            $pulled = false;
            foreach ($streams as $stream) {
                $index = $stream === $stdoutPipe ? 1 : 2;
                if (!isset($open[$index])) {
                    continue;
                }
                $chunk = fread($stream, 65536);
                if (is_string($chunk) && $chunk !== '') {
                    $pulled = true;
                    if ($index === 1) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }
                if (feof($stream)) {
                    fclose($stream);
                    unset($open[$index]);
                }
            }
            if (!$pulled && $ready !== 0) {
                usleep(1000);
            }
        }

        return [$stdout, $stderr];
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
