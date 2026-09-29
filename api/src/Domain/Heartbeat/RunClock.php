<?php

declare(strict_types=1);

namespace App\Domain\Heartbeat;

/**
 * A run stays alive while Pi is still sending events.
 * Silence for the idle window is a timeout. The absolute limit stops a run that never settles.
 */
final class RunClock
{
    public function __construct(
        private readonly int $idleSeconds,
        private readonly int $limitSeconds,
    ) {}

    public function reason(float $startedAt, float $lastActivityAt, float $now): ?string
    {
        $idle = max(1, $this->idleSeconds);
        $limit = max($idle, $this->limitSeconds);
        if (($now - $startedAt) >= $limit) {
            return 'limit';
        }
        if (($now - $lastActivityAt) >= $idle) {
            return 'idle';
        }

        return null;
    }
}
