<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Org\ThinkingLevels;
use Codeception\Test\Unit;

final class ThinkingLevelsTest extends Unit
{
    public function testAModelWithoutReasoningOnlyOffersOff(): void
    {
        $levels = ThinkingLevels::forModel([
            'reasoning' => false,
            'thinkingLevelMap' => [
                'off' => 'none',
                'low' => null,
                'high' => null,
            ],
        ]);

        $this->assertSame(['off'], $levels);
    }

    public function testReasoningWithoutAMapStopsBeforeXhigh(): void
    {
        $levels = ThinkingLevels::forModel(['reasoning' => true]);

        $this->assertSame(['off', 'minimal', 'low', 'medium', 'high'], $levels);
    }

    public function testANullMapEntryIsUnsupportedAndXhighRequiresAMapping(): void
    {
        $levels = ThinkingLevels::forModel([
            'reasoning' => true,
            'thinkingLevelMap' => [
                'off' => 'off',
                'minimal' => null,
                'low' => 'low',
                'medium' => 'medium',
                'high' => null,
                'xhigh' => 'xhigh',
                'max' => null,
            ],
        ]);

        $this->assertSame(['off', 'low', 'medium', 'xhigh'], $levels);
    }

    public function testOffCanBeRemovedWhenTheModelMapsItToNull(): void
    {
        $levels = ThinkingLevels::forModel([
            'reasoning' => true,
            'thinkingLevelMap' => [
                'off' => null,
                'xhigh' => 'xhigh',
                'max' => 'max',
            ],
        ]);

        $this->assertSame(['minimal', 'low', 'medium', 'high', 'xhigh', 'max'], $levels);
    }

    public function testClampWalksTowardASupportedLevel(): void
    {
        $available = ['off', 'low', 'medium', 'xhigh'];

        $this->assertSame('medium', ThinkingLevels::clamp('medium', $available));
        $this->assertSame('xhigh', ThinkingLevels::clamp('high', $available));
        $this->assertSame('low', ThinkingLevels::clamp('minimal', $available));
        $this->assertSame('off', ThinkingLevels::clamp('nope', $available));
    }
}
