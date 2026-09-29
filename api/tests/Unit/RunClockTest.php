<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Heartbeat\RunClock;
use Codeception\Test\Unit;

final class RunClockTest extends Unit
{
    public function testActivityInsideTheIdleWindowIsNotATimeout(): void
    {
        $clock = new RunClock(idleSeconds: 600, limitSeconds: 7200);

        $this->assertNull($clock->reason(startedAt: 0, lastActivityAt: 590, now: 590));
    }

    public function testSilenceForTheIdleWindowTimesOut(): void
    {
        $clock = new RunClock(idleSeconds: 600, limitSeconds: 7200);

        $this->assertSame('idle', $clock->reason(startedAt: 0, lastActivityAt: 10, now: 610));
    }

    public function testTheAbsoluteLimitStopsARunThatIsStillActive(): void
    {
        $clock = new RunClock(idleSeconds: 600, limitSeconds: 7200);

        $this->assertSame('limit', $clock->reason(startedAt: 0, lastActivityAt: 7199, now: 7200));
    }
}
