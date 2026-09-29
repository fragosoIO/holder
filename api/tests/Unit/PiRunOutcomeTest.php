<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Heartbeat\PiRunOutcome;
use Codeception\Test\Unit;

final class PiRunOutcomeTest extends Unit
{
    public function testOauthFailureBecomesAShortModelError(): void
    {
        $outcome = new PiRunOutcome();
        $message = $outcome->errorMessage([
            'type' => 'message_end',
            'message' => [
                'role' => 'assistant',
                'content' => [],
                'stopReason' => 'error',
                'errorMessage' => 'OAuth refresh failed for anthropic: token refresh failed. body={"error":"invalid_grant","error_description":"Refresh token expired"}; stack=Error: hidden',
            ],
        ]);

        $this->assertSame('anthropic: Refresh token expired', $message);
    }

    public function testAFinishedAssistantMessageIsNotAnError(): void
    {
        $outcome = new PiRunOutcome();

        $this->assertNull($outcome->errorMessage([
            'type' => 'message_end',
            'message' => [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'Done.']],
                'stopReason' => 'stop',
            ],
        ]));
    }

    public function testAgentEndCarriesTheAssistantError(): void
    {
        $outcome = new PiRunOutcome();
        $message = $outcome->errorMessage([
            'type' => 'agent_end',
            'messages' => [
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Do the task']]],
                ['role' => 'assistant', 'content' => [], 'stopReason' => 'error', 'errorMessage' => 'connection refused'],
            ],
        ]);

        $this->assertSame('connection refused', $message);
    }
}
