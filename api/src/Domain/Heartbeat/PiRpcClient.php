<?php

declare(strict_types=1);

namespace App\Domain\Heartbeat;

final class PiRpcClient
{
    /** @var resource|null */
    private $process = null;

    /** @var resource|null */
    private $stdin = null;

    /** @var resource|null */
    private $stdout = null;

    /** @var resource|null */
    private $stderr = null;

    private string $buffer = '';

    private int $pid = 0;

    public string $stderrLog = '';

    /**
     * @param list<string> $args
     * @param array<string, string> $env
     */
    public function start(string $binary, array $args, string $cwd, array $env): void
    {
        $command = $args;
        array_unshift($command, $binary);
        if (is_executable('/usr/bin/setsid')) {
            array_unshift($command, '/usr/bin/setsid');
        }

        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $spec, $pipes, $cwd, $env, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start Pi.');
        }
        $this->process = $process;
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2];
        stream_set_blocking($this->stdin, false);
        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);
        $status = proc_get_status($process);
        $this->pid = (int) ($status['pid'] ?? 0);
    }

    public function pid(): int
    {
        return $this->pid;
    }

    public function prompt(string $id, string $message): void
    {
        $this->send(['id' => $id, 'type' => 'prompt', 'message' => $message]);
    }

    public function steer(string $id, string $message): void
    {
        $this->send(['id' => $id, 'type' => 'steer', 'message' => $message]);
    }

    public function abort(string $id): void
    {
        $this->send(['id' => $id, 'type' => 'abort']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readEvent(float $timeoutSeconds): ?array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $line = $this->pullLine();
            if ($line !== null) {
                $decoded = json_decode($line, true);

                return is_array($decoded) ? $decoded : ['type' => 'invalid', 'raw' => $line];
            }
            $this->pump(0.2);
        }

        return null;
    }

    public function stop(): void
    {
        if (is_resource($this->stdin)) {
            fclose($this->stdin);
            $this->stdin = null;
        }
        if ($this->pid > 0 && function_exists('posix_kill')) {
            posix_kill(-$this->pid, \SIGTERM);
            posix_kill($this->pid, \SIGTERM);
        } elseif (is_resource($this->process)) {
            proc_terminate($this->process);
        }
        if (is_resource($this->stdout)) {
            fclose($this->stdout);
            $this->stdout = null;
        }
        if (is_resource($this->stderr)) {
            fclose($this->stderr);
            $this->stderr = null;
        }
        if (is_resource($this->process)) {
            proc_close($this->process);
            $this->process = null;
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function send(array $record): void
    {
        if (!is_resource($this->stdin)) {
            return;
        }
        $payload = json_encode($record) . "\n";
        $written = 0;
        $length = strlen($payload);
        while ($written < $length) {
            $result = fwrite($this->stdin, substr($payload, $written));
            if ($result === false) {
                break;
            }
            $written += $result;
            if ($result === 0) {
                $this->pump(0.05);
            }
        }
        fflush($this->stdin);
    }

    private function pullLine(): ?string
    {
        $break = strpos($this->buffer, "\n");
        if ($break === false) {
            return null;
        }
        $line = substr($this->buffer, 0, $break);
        $this->buffer = substr($this->buffer, $break + 1);
        if (str_ends_with($line, "\r")) {
            $line = substr($line, 0, -1);
        }

        return $line;
    }

    private function pump(float $timeout): void
    {
        if (!is_resource($this->stdout)) {
            return;
        }
        $read = [$this->stdout];
        if (is_resource($this->stderr)) {
            $read[] = $this->stderr;
        }
        $write = [];
        $except = [];
        $seconds = (int) $timeout;
        $micros = (int) (($timeout - $seconds) * 1_000_000);
        @stream_select($read, $write, $except, $seconds, $micros);
        foreach ($read as $stream) {
            $chunk = stream_get_contents($stream);
            if (!is_string($chunk) || $chunk === '') {
                continue;
            }
            if ($stream === $this->stdout) {
                $this->buffer .= $chunk;
            } else {
                $this->stderrLog .= $chunk;
            }
        }
    }
}
