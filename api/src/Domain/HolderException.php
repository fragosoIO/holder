<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

final class HolderException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
    ) {
        parent::__construct($message);
    }
}
