<?php

declare(strict_types=1);

namespace App\Api;

use App\Api\Shared\ResponseFactory;
use App\Domain\HolderException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class HolderExceptionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ResponseFactory $responses,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (HolderException $exception) {
            return $this->responses->fail(
                $exception->getMessage(),
                ['code' => $exception->errorCode],
                httpCode: $exception->status,
            );
        }
    }
}
