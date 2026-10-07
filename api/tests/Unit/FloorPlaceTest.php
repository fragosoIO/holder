<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Floor\FloorPlace;
use Codeception\Test\Unit;

final class FloorPlaceTest extends Unit
{
    public function testIdleAgentSmokesOnTheBalcony(): void
    {
        $this->assertSame(
            ['place' => 'balcony', 'step' => 'Smoking'],
            FloorPlace::decide('active', null, false),
        );
        $this->assertSame(
            ['place' => 'balcony', 'step' => 'Smoking'],
            FloorPlace::decide('active', 'in_progress', false),
        );
    }

    public function testRunningAgentGoesToWork(): void
    {
        $this->assertSame(
            ['place' => 'work', 'step' => null],
            FloorPlace::decide('active', 'in_progress', true),
        );
    }

    public function testPausedAgentStaysSeatedWhileARunIsGoing(): void
    {
        $this->assertSame(
            ['place' => 'desk', 'step' => 'Paused'],
            FloorPlace::decide('paused', 'in_progress', true),
        );
    }

    public function testBlockedAndReviewSendTheAgentBack(): void
    {
        $this->assertSame(
            ['place' => 'desk', 'step' => 'Waiting on you'],
            FloorPlace::decide('active', 'blocked', true),
        );
        $this->assertSame(
            ['place' => 'desk', 'step' => 'Waiting on you'],
            FloorPlace::decide('active', 'in_review', true),
        );
    }

    public function testPausedBeatsWaiting(): void
    {
        $this->assertSame(
            ['place' => 'desk', 'step' => 'Paused'],
            FloorPlace::decide('paused', 'blocked', true),
        );
    }
}
