<?php

declare(strict_types=1);

namespace App\Api;

use App\Api\Shared\ResponseFactory;
use App\Domain\ActorContext;
use App\Domain\Work\PreviewService;
use HttpSoft\Message\Response;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class PreviewEndpoints
{
    public function __construct(
        private ResponseFactory $responses,
        private PreviewService $preview,
        private ActorContext $actors,
    ) {}

    public function describe(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireUser();

        return $this->responses->success($this->preview->describe(
            $actor->id,
            (string) $route->getArgument('companyId'),
            (string) $route->getArgument('taskId'),
        ));
    }

    public function file(CurrentRoute $route): ResponseInterface
    {
        $opened = $this->preview->open(
            (string) $route->getArgument('companyId'),
            (string) $route->getArgument('taskId'),
            (string) $route->getArgument('token'),
            (string) $route->getArgument('path'),
        );
        if ($opened === null) {
            return $this->missing();
        }
        $body = file_get_contents($opened['path']);
        if ($body === false) {
            return $this->missing();
        }

        return $this->bytes(200, $body, [
            'Content-Type' => $opened['type'],
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "frame-ancestors 'self'",
        ]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function bytes(int $status, string $body, array $headers): ResponseInterface
    {
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('The preview response could not be built.');
        }
        fwrite($stream, $body);
        rewind($stream);

        return new Response($status, $headers, $stream);
    }

    private function missing(): ResponseInterface
    {
        return $this->bytes(404, 'Not found.', ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
