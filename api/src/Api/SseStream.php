<?php

declare(strict_types=1);

namespace App\Api;

use App\Domain\Work\WorkService;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class SseStream implements StreamInterface
{
    private string $pending = '';

    private bool $closed = false;

    private int $after = 0;

    private bool $primed = false;

    private float $started;

    public function __construct(
        private readonly WorkService $work,
        private readonly string $companyId,
        private readonly string $taskId,
    ) {
        $this->started = microtime(true);
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function close(): void
    {
        $this->closed = true;
        $this->pending = '';
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        throw new RuntimeException('SSE stream is not seekable.');
    }

    public function eof(): bool
    {
        return $this->closed && $this->pending === '';
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('SSE stream is not seekable.');
    }

    public function rewind(): void
    {
        throw new RuntimeException('SSE stream is not seekable.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('SSE stream is not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        if ($this->pending !== '') {
            $chunk = substr($this->pending, 0, $length);
            $this->pending = substr($this->pending, $length);

            return $chunk;
        }
        if ($this->closed) {
            return '';
        }

        if (!$this->primed) {
            $this->after = $this->work->latestEventId($this->companyId, $this->taskId);
            $this->primed = true;
        }
        $events = $this->work->eventsForTask($this->companyId, $this->taskId, $this->after);
        if ($events !== []) {
            $frame = '';
            foreach ($events as $event) {
                $this->after = (int) $event['id'];
                $payload = $event['payload'];
                if (is_string($payload)) {
                    $decoded = json_decode($payload, true);
                    $payload = is_array($decoded) ? $decoded : ['raw' => $payload];
                }
                $frame .= 'data: ' . json_encode([
                    'id' => (int) $event['id'],
                    'type' => (string) $event['event_type'],
                    'payload' => $payload,
                ]) . "\n\n";
            }
            $this->pending = $frame;
            $this->closed = true;

            return $this->read($length);
        }

        $quiet = $this->work->taskIsQuiet($this->companyId, $this->taskId);
        if ($quiet || (microtime(true) - $this->started) > 1) {
            $this->pending = "retry: 1000\ndata: {\"type\":\"holder.stream_end\"}\n\n";
            $this->closed = true;

            return $this->read($length);
        }

        usleep(300000);
        $this->pending = ": ping\n\n";

        return $this->read($length);
    }

    public function getContents(): string
    {
        $contents = '';
        while (!$this->eof()) {
            $contents .= $this->read(8192);
        }

        return $contents;
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
