<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class HolderConfig
{
    public function __construct(
        public string $mode,
        public string $secretsKey,
        public string $apiUrl,
        public string $dataDir,
        public string $binPath,
        public int $runTimeoutSeconds,
        public int $runLimitSeconds,
    ) {}

    public function isLocal(): bool
    {
        return $this->mode === 'local';
    }
}
