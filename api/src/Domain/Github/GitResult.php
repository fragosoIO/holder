<?php

declare(strict_types=1);

namespace App\Domain\Github;

final readonly class GitResult
{
    public function __construct(
        public int $exit,
        public string $stdout,
        public string $stderr,
    ) {}
}
