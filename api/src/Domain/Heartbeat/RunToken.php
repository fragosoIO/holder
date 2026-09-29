<?php

declare(strict_types=1);

namespace App\Domain\Heartbeat;

use App\Domain\HolderConfig;

final class RunToken
{
    public function __construct(
        private readonly HolderConfig $config,
    ) {}

    public function issue(string $companyId, string $agentId, string $runId, ?string $taskId): string
    {
        $payload = $this->encode([
            'companyId' => $companyId,
            'agentId' => $agentId,
            'runId' => $runId,
            'taskId' => $taskId,
            'exp' => time() + 60 * 60 * 6,
        ]);
        $sig = hash_hmac('sha256', $payload, $this->config->secretsKey);

        return $payload . '.' . $sig;
    }

    /**
     * @return array{companyId: string, agentId: string, runId: string, taskId: ?string}|null
     */
    public function parse(string $token): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }
        $expected = hash_hmac('sha256', $parts[0], $this->config->secretsKey);
        if (!hash_equals($expected, $parts[1])) {
            return null;
        }
        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || (int) ($data['exp'] ?? 0) < time()) {
            return null;
        }

        return [
            'companyId' => (string) $data['companyId'],
            'agentId' => (string) $data['agentId'],
            'runId' => (string) $data['runId'],
            'taskId' => isset($data['taskId']) && is_string($data['taskId']) && $data['taskId'] !== '' ? $data['taskId'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');
    }
}
