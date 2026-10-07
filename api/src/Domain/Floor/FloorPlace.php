<?php

declare(strict_types=1);

namespace App\Domain\Floor;

final class FloorPlace
{
    /**
     * @return array{place: string, step: string|null}
     */
    public static function decide(string $agentStatus, ?string $taskStatus, bool $running): array
    {
        if ($agentStatus === 'paused') {
            return ['place' => 'desk', 'step' => 'Paused'];
        }
        if ($taskStatus === 'blocked' || $taskStatus === 'in_review') {
            return ['place' => 'desk', 'step' => 'Waiting on you'];
        }
        if ($running) {
            return ['place' => 'work', 'step' => null];
        }

        return ['place' => 'balcony', 'step' => 'Smoking'];
    }
}
