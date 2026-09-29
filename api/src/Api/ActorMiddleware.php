<?php

declare(strict_types=1);

namespace App\Api;

use App\Domain\Actor;
use App\Domain\ActorContext;
use App\Domain\Heartbeat\RunToken;
use App\Domain\HolderConfig;
use App\Domain\Identity\IdentityService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class ActorMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ActorContext $actors,
        private IdentityService $identity,
        private RunToken $runToken,
        private HolderConfig $config,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->actors->clear();
        $bearer = $this->bearer($request);
        if ($bearer !== null && str_contains($bearer, '.')) {
            $parsed = $this->runToken->parse($bearer);
            if ($parsed !== null) {
                $this->actors->set(new Actor(
                    'run',
                    $parsed['agentId'],
                    $parsed['companyId'],
                    $parsed['runId'],
                    $parsed['taskId'],
                    'agent',
                ));
            }
        } elseif ($bearer !== null) {
            $this->setSession($bearer);
        } else {
            $cookie = $request->getCookieParams()['holder_session'] ?? null;
            if (is_string($cookie) && $cookie !== '') {
                $this->setSession($cookie);
            }
        }

        if ($this->actors->get() === null && $this->config->isLocal() && $this->isLocalRequest($request)) {
            $session = $this->identity->ensureLocalOwner();
            $this->actors->set(new Actor('user', (string) $session['user']['id'], null, null, null, 'owner'));
            $request = $request->withAttribute('holder.issuedToken', $session['token']);
        }

        return $handler->handle($request);
    }

    private function setSession(string $token): void
    {
        $session = $this->identity->session($token);
        if ($session === null) {
            return;
        }
        $this->actors->set(new Actor(
            'user',
            (string) $session['user']['id'],
            null,
            null,
            null,
            'member',
        ));
    }

    private function bearer(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    private function isLocalRequest(ServerRequestInterface $request): bool
    {
        return LocalRequest::trusted(
            (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''),
            $request->getUri()->getHost(),
        );
    }
}
