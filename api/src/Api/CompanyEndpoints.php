<?php

declare(strict_types=1);

namespace App\Api;

use App\Api\Shared\ResponseFactory;
use App\Domain\ActorContext;
use App\Domain\HolderException;
use App\Domain\Identity\IdentityService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class CompanyEndpoints
{
    public function __construct(
        private ResponseFactory $responses,
        private IdentityService $identity,
        private ActorContext $actors,
    ) {}

    public function list(): ResponseInterface
    {
        $actor = $this->actors->requireUser();

        return $this->responses->success($this->identity->companiesFor($actor->id));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actors->requireUser();
        $body = $this->body($request);

        return $this->responses->success($this->identity->createCompany(
            $actor->id,
            trim((string) ($body['name'] ?? '')),
            trim((string) ($body['mission'] ?? '')),
        ));
    }

    public function show(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireUser();

        return $this->responses->success($this->identity->requireCompany(
            $actor->id,
            (string) $route->getArgument('companyId'),
        ));
    }

    public function update(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireUser();
        $body = $this->body($request);

        return $this->responses->success($this->identity->updateCompany(
            $actor->id,
            (string) $route->getArgument('companyId'),
            trim((string) ($body['name'] ?? '')),
            trim((string) ($body['mission'] ?? '')),
        ));
    }

    public function saveGithubToken(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireUser();
        $body = $this->body($request);
        if (!array_key_exists('token', $body)) {
            throw new HolderException('missing_field', 'Token is required.', 422);
        }

        return $this->responses->success($this->identity->setGithubToken(
            $actor->id,
            (string) $route->getArgument('companyId'),
            (string) $body['token'],
        ));
    }

    public function invite(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireUser();
        $body = $this->body($request);

        return $this->responses->success($this->identity->invite(
            $actor->id,
            (string) $route->getArgument('companyId'),
            trim((string) ($body['email'] ?? '')),
            trim((string) ($body['role'] ?? 'member')),
        ));
    }

    public function acceptInvite(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $body = $this->body($request);
        $session = $this->identity->acceptInvite(
            (string) $route->getArgument('token'),
            trim((string) ($body['name'] ?? '')),
            (string) ($body['password'] ?? ''),
        );

        return Cookies::withSession($this->responses->success($session), $session['token']);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }
}
