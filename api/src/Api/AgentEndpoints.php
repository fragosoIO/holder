<?php

declare(strict_types=1);

namespace App\Api;

use App\Api\Shared\ResponseFactory;
use App\Domain\ActorContext;
use App\Domain\HolderException;
use App\Domain\Org\OrgService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class AgentEndpoints
{
    public function __construct(
        private ResponseFactory $responses,
        private OrgService $org,
        private ActorContext $actors,
    ) {}

    public function catalog(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireUser();
        $binary = trim((string) ($request->getQueryParams()['binary'] ?? ''));

        return $this->responses->success($this->org->piCatalog($actor->id, $this->company($route), $binary));
    }

    public function list(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireUser();

        return $this->responses->success($this->org->listAgents($actor->id, $this->company($route)));
    }

    public function show(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireUser();

        return $this->responses->success($this->org->getAgent(
            $actor->id,
            $this->company($route),
            (string) $route->getArgument('agentId'),
        ));
    }

    public function create(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $companyId = $this->company($route);
        $actor = $this->actors->get();
        if ($actor !== null && $actor->isRun()) {
            if ($actor->companyId !== $companyId) {
                throw new HolderException('forbidden', 'This run cannot hire in another organization.', 403);
            }

            return $this->responses->success($this->org->hireByOnboardingAgent(
                $companyId,
                $actor->id,
                $this->input($request),
            ));
        }

        $user = $this->actors->requireUser();

        return $this->responses->success($this->org->hire(
            $user->id,
            $companyId,
            $this->input($request),
        ));
    }

    public function update(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireUser();

        return $this->responses->success($this->org->update(
            $actor->id,
            $this->company($route),
            (string) $route->getArgument('agentId'),
            $this->input($request),
        ));
    }

    public function pause(CurrentRoute $route): ResponseInterface
    {
        return $this->status($route, 'paused');
    }

    public function resume(CurrentRoute $route): ResponseInterface
    {
        return $this->status($route, 'active');
    }

    public function terminate(CurrentRoute $route): ResponseInterface
    {
        return $this->status($route, 'terminated');
    }

    private function status(CurrentRoute $route, string $status): ResponseInterface
    {
        $actor = $this->actors->requireUser();

        return $this->responses->success($this->org->setStatus(
            $actor->id,
            $this->company($route),
            (string) $route->getArgument('agentId'),
            $status,
        ));
    }

    private function company(CurrentRoute $route): string
    {
        return (string) $route->getArgument('companyId');
    }

    /**
     * @return array<string, string>
     */
    private function input(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        $body = is_array($parsed) ? $parsed : [];
        $string = static fn (string $key): string => trim((string) ($body[$key] ?? ''));

        return [
            'name' => $string('name'),
            'title' => $string('title'),
            'jobDescription' => $string('jobDescription'),
            'managerId' => $string('managerId'),
            'piBinary' => $string('piBinary'),
            'piProvider' => $string('piProvider'),
            'piModel' => $string('piModel'),
            'piThinking' => $string('piThinking'),
        ];
    }
}
