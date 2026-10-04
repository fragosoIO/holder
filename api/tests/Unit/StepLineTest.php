<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Floor\StepLine;
use Codeception\Test\Unit;

final class StepLineTest extends Unit
{
    public function testEditAndWriteCopyThePath(): void
    {
        $this->assertSame('Editing src/Login.php', StepLine::fromEvent($this->tool('edit', ['path' => 'src/Login.php'])));
        $this->assertSame('Editing src/Login.php', StepLine::fromEvent($this->tool('write', ['path' => 'src/Login.php'])));
    }

    public function testReadCopiesThePath(): void
    {
        $this->assertSame('Reading src/Login.php', StepLine::fromEvent($this->tool('read', ['path' => 'src/Login.php'])));
    }

    public function testBashCollapsesWhitespace(): void
    {
        $this->assertSame('Running npm test', StepLine::fromEvent($this->tool('bash', ['command' => "npm\n\ttest"])));
    }

    public function testBashKeepsEightyCharacters(): void
    {
        $command = str_repeat('a', 80);

        $this->assertSame('Running ' . $command, StepLine::fromEvent($this->tool('bash', ['command' => $command])));
    }

    public function testBashCutsAtEightyCharacters(): void
    {
        $command = str_repeat('a', 81);

        $this->assertSame('Running ' . str_repeat('a', 79) . '…', StepLine::fromEvent($this->tool('bash', ['command' => $command])));
    }

    public function testBashWithoutACommand(): void
    {
        $this->assertSame('Running a command', StepLine::fromEvent($this->tool('bash', [])));
        $this->assertSame('Running a command', StepLine::fromEvent($this->tool('bash', ['command' => '   '])));
    }

    public function testEditWithoutAPathUsesTheToolName(): void
    {
        $this->assertSame('edit', StepLine::fromEvent($this->tool('edit', [])));
    }

    public function testToolNameIsCaseSensitive(): void
    {
        $this->assertSame('Edit', StepLine::fromEvent($this->tool('Edit', ['path' => 'src/Login.php'])));
    }

    public function testEmptyToolNameIsWorking(): void
    {
        $this->assertSame('Working', StepLine::fromEvent($this->tool('', ['path' => 'src/Login.php'])));
    }

    public function testNonObjectPayloadIsWorking(): void
    {
        $this->assertSame('Working', StepLine::fromEvent([
            'event_type' => 'tool_execution_start',
            'payload' => 'edit',
        ]));
    }

    public function testHolderErrorIgnoresTheBody(): void
    {
        $this->assertSame('Hit an error', StepLine::fromEvent([
            'event_type' => 'holder.error',
            'payload' => ['message' => 'boom'],
        ]));
    }

    /**
     * @param array<string, mixed> $args
     * @return array{event_type: string, payload: array<string, mixed>}
     */
    private function tool(string $name, array $args): array
    {
        return [
            'event_type' => 'tool_execution_start',
            'payload' => ['toolName' => $name, 'args' => $args],
        ];
    }
}
