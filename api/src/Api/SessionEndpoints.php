<?php

declare(strict_types=1);

namespace App\Api;

use App\Api\Shared\ResponseFactory;
use App\Domain\ActorContext;
use App\Domain\HolderConfig;
use App\Domain\HolderException;
use App\Domain\Identity\IdentityService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class SessionEndpoints
{
    public function __construct(
        private ResponseFactory $responses,
        private IdentityService $identity,
        private ActorContext $actors,
        private HolderConfig $config,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actors->get();
        if ($actor === null || !$actor->isUser()) {
            throw new HolderException('unauthenticated', 'Sign in to continue.', 401);
        }
        $token = $request->getAttribute('holder.issuedToken');
        if (!is_string($token) || $token === '') {
            $token = $request->getCookieParams()['holder_session'] ?? null;
        }
        if (!is_string($token) || $token === '') {
            $header = $request->getHeaderLine('Authorization');
            if (preg_match('/^Bearer\s+(\S+)$/i', $header, $match) === 1) {
                $token = $match[1];
            }
        }
        $session = is_string($token) ? $this->identity->session($token) : null;
        if ($session === null) {
            $session = [
                'user' => ['id' => $actor->id],
                'companies' => $this->identity->companiesFor($actor->id),
            ];
        }
        $response = $this->responses->success([
            'mode' => $this->config->mode,
            'user' => $session['user'],
            'companies' => $session['companies'],
            'token' => is_string($token) ? $token : null,
        ]);
        if (is_string($token) && $token !== '') {
            return Cookies::withSession($response, $token);
        }

        return $response;
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        $session = $this->identity->login(
            trim((string) ($body['email'] ?? '')),
            (string) ($body['password'] ?? ''),
        );
        $response = $this->responses->success([
            'mode' => 'authenticated',
            'token' => $session['token'],
            'user' => $session['user'],
            'companies' => $session['companies'],
        ]);

        return Cookies::withSession($response, $session['token']);
    }

    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $token = $request->getCookieParams()['holder_session'] ?? null;
        if (!is_string($token) || $token === '') {
            $header = $request->getHeaderLine('Authorization');
            if (preg_match('/^Bearer\s+(\S+)$/i', $header, $match) === 1) {
                $token = $match[1];
            }
        }
        if (is_string($token) && $token !== '') {
            $this->identity->logout($token);
        }

        return Cookies::clearSession($this->responses->success(['ok' => true]));
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
