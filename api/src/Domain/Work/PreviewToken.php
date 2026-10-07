<?php

declare(strict_types=1);

namespace App\Domain\Work;

final readonly class PreviewToken
{
    public function __construct(
        private string $secretsKey,
    ) {}

    /**
     * @return array{token: string, expiresAt: int}
     */
    public function issue(string $companyId, string $taskId, ?int $now = null): array
    {
        $expiresAt = ($now ?? time()) + 43_200;

        return [
            'token' => $expiresAt . '.' . $this->mac($companyId, $taskId, (string) $expiresAt),
            'expiresAt' => $expiresAt,
        ];
    }

    public function verify(string $token, string $companyId, string $taskId, ?int $now = null): bool
    {
        $dot = strpos($token, '.');
        if ($dot === false) {
            return false;
        }
        $expiry = substr($token, 0, $dot);
        $mac = substr($token, $dot + 1);
        if (preg_match('/^[0-9]+$/', $expiry) !== 1) {
            return false;
        }
        if (($now ?? time()) >= (int) $expiry) {
            return false;
        }
        $expected = $this->mac($companyId, $taskId, $expiry);

        return hash_equals($expected, $mac);
    }

    private function mac(string $companyId, string $taskId, string $expiry): string
    {
        return hash_hmac('sha256', $companyId . "\n" . $taskId . "\n" . $expiry, $this->secretsKey);
    }
}
