<?php

declare(strict_types=1);

namespace App\Api;

use App\Api\Shared\ResponseFactory;
use App\Domain\ActorContext;
use App\Domain\Onboarding\OnboardingService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class OnboardingEndpoints
{
    public function __construct(
        private ResponseFactory $responses,
        private OnboardingService $onboarding,
        private ActorContext $actors,
    ) {}

    public function complete(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actors->requireUser();
        $parsed = $request->getParsedBody();
        $body = is_array($parsed) ? $parsed : [];
        $string = static fn (string $key): string => trim((string) ($body[$key] ?? ''));

        return $this->responses->success($this->onboarding->complete($actor->id, [
            'name' => $string('name'),
            'companyId' => $string('companyId'),
            'agentName' => $string('agentName'),
            'piBinary' => $string('piBinary'),
            'piProvider' => $string('piProvider'),
            'piModel' => $string('piModel'),
            'piThinking' => $string('piThinking'),
        ]));
    }
}
