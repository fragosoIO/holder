<?php

declare(strict_types=1);

namespace App\Domain\Github;

use App\Domain\HolderConfig;
use RuntimeException;

final class TokenCipher
{
    public function __construct(
        private readonly HolderConfig $config,
    ) {}

    public function seal(string $token): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($token, $nonce, $this->key());

        return base64_encode($nonce . $cipher);
    }

    public function open(string $payload): string
    {
        $raw = base64_decode($payload, true);
        $nonceBytes = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if ($raw === false || strlen($raw) <= $nonceBytes) {
            throw new RuntimeException('GitHub token payload is unreadable.');
        }
        $opened = sodium_crypto_secretbox_open(
            substr($raw, $nonceBytes),
            substr($raw, 0, $nonceBytes),
            $this->key(),
        );
        if ($opened === false) {
            throw new RuntimeException('GitHub token payload is unreadable.');
        }

        return $opened;
    }

    private function key(): string
    {
        return hash('sha256', $this->config->secretsKey, true);
    }
}
