<?php

declare(strict_types=1);

namespace App\Api;

use App\Api\Shared\ResponseFactory;
use Psr\Http\Message\ResponseInterface;

final readonly class HealthAction
{
    public function __invoke(ResponseFactory $responses): ResponseInterface
    {
        return $responses->success(['ok' => true]);
    }
}
