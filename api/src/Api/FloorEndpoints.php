<?php

declare(strict_types=1);

namespace App\Api;

use App\Api\Shared\ResponseFactory;
use App\Domain\ActorContext;
use App\Domain\Floor\FloorService;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class FloorEndpoints
{
    public function __construct(
        private ResponseFactory $responses,
        private FloorService $floor,
        private ActorContext $actors,
    ) {}

    public function show(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireUser();

        return $this->responses->success($this->floor->snapshot(
            $actor->id,
            (string) $route->getArgument('companyId'),
        ));
    }
}
