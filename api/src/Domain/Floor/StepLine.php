<?php

declare(strict_types=1);

namespace App\Domain\Floor;

final class StepLine
{
    /**
     * @param array{event_type?: mixed, payload?: mixed} $event
     */
    public static function fromEvent(array $event): string
    {
        $type = (string) ($event['event_type'] ?? '');
        if ($type === 'holder.error') {
            return 'Hit an error';
        }
        $payload = $event['payload'] ?? null;
        if ($type !== 'tool_execution_start' || !is_array($payload)) {
            return 'Working';
        }
        $name = $payload['toolName'] ?? '';
        if (!is_string($name) || $name === '') {
            return 'Working';
        }
        $args = $payload['args'] ?? null;
        $path = is_array($args) && isset($args['path']) && is_string($args['path']) ? $args['path'] : '';
        $command = is_array($args) && isset($args['command']) && is_string($args['command']) ? $args['command'] : '';
        if (($name === 'edit' || $name === 'write') && $path !== '') {
            return 'Editing ' . $path;
        }
        if ($name === 'read' && $path !== '') {
            return 'Reading ' . $path;
        }
        if ($name === 'bash') {
            $command = trim((string) preg_replace('/\s+/', ' ', $command));
            if ($command === '') {
                return 'Running a command';
            }
            if (mb_strlen($command) > 80) {
                $command = mb_substr($command, 0, 79) . '…';
            }

            return 'Running ' . $command;
        }

        return $name;
    }
}
