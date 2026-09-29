<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class Actor
{
    public function __construct(
        public string $kind,
        public string $id,
        public ?string $companyId,
        public ?string $runId,
        public ?string $taskId,
        public string $role,
    ) {}

    public function isUser(): bool
    {
        return $this->kind === 'user';
    }

    public function isRun(): bool
    {
        return $this->kind === 'run';
    }
}
