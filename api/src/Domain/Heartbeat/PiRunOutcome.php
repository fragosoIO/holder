<?php

declare(strict_types=1);

namespace App\Domain\Heartbeat;

final class PiRunOutcome
{
    /**
     * A model or command failure hidden inside one Pi event, already shortened.
     *
     * @param array<string, mixed> $event
     */
    public function errorMessage(array $event): ?string
    {
        foreach ($this->messages($event) as $message) {
            $role = $message['role'] ?? null;
            if (is_string($role) && $role !== 'assistant') {
                continue;
            }
            $error = $message['errorMessage'] ?? null;
            $stop = $message['stopReason'] ?? null;
            if ($stop === 'error' || (is_string($error) && $error !== '')) {
                return $this->summarize(is_string($error) && $error !== '' ? $error : 'The model returned an error.');
            }
        }

        if (($event['type'] ?? '') === 'response' && ($event['success'] ?? true) === false) {
            $error = $event['error'] ?? 'Pi rejected the command.';

            return $this->summarize(is_string($error) && $error !== '' ? $error : 'Pi rejected the command.');
        }

        return null;
    }

    public function summarize(string $message): string
    {
        if (preg_match('/"error_description"\s*:\s*"([^"]+)"/', $message, $description) === 1) {
            if (preg_match('/\bfor ([A-Za-z0-9._-]+)\b/', $message, $provider) === 1) {
                return $provider[1] . ': ' . $description[1];
            }

            return $description[1];
        }

        $line = preg_split("/\R/", $message)[0] ?? $message;
        $line = explode('; stack=', $line)[0];
        if (strlen($line) > 280) {
            return substr($line, 0, 279) . '…';
        }

        return $line;
    }

    /**
     * @param array<string, mixed> $event
     * @return list<array<string, mixed>>
     */
    private function messages(array $event): array
    {
        $messages = [];
        if (isset($event['message']) && is_array($event['message'])) {
            $messages[] = $event['message'];
        }
        if (isset($event['messages']) && is_array($event['messages'])) {
            foreach ($event['messages'] as $message) {
                if (is_array($message)) {
                    $messages[] = $message;
                }
            }
        }

        return $messages;
    }
}
