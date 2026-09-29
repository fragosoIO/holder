<?php

declare(strict_types=1);

namespace App\Api;

use App\Api\Shared\ResponseFactory;
use App\Domain\ActorContext;
use App\Domain\HolderException;
use App\Domain\Work\WorkService;
use HttpSoft\Message\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class WorkEndpoints
{
    public function __construct(
        private ResponseFactory $responses,
        private WorkService $work,
        private ActorContext $actors,
    ) {}

    public function listGoals(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireUser();

        return $this->responses->success($this->work->listGoals($actor->id, $this->company($route)));
    }

    public function createGoal(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireUser();
        $body = $this->body($request);
        $parent = trim((string) ($body['parentId'] ?? ''));

        return $this->responses->success($this->work->createGoal(
            $actor->id,
            $this->company($route),
            trim((string) ($body['title'] ?? '')),
            trim((string) ($body['description'] ?? '')),
            $parent === '' ? null : $parent,
        ));
    }

    public function showGoal(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireUser();

        return $this->responses->success($this->work->getGoal(
            $actor->id,
            $this->company($route),
            (string) $route->getArgument('goalId'),
        ));
    }

    public function listProjects(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireUser();

        return $this->responses->success($this->work->listProjects($actor->id, $this->company($route)));
    }

    public function createProject(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireUser();
        $body = $this->body($request);

        return $this->responses->success($this->work->createProject(
            $actor->id,
            $this->company($route),
            trim((string) ($body['name'] ?? '')),
            trim((string) ($body['workspacePath'] ?? '')),
        ));
    }

    public function listTasks(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireUser();
        $query = $request->getQueryParams();
        $assignee = trim((string) ($query['assigneeAgentId'] ?? ''));
        $goal = trim((string) ($query['goalId'] ?? ''));

        return $this->responses->success($this->work->listTasks(
            $actor->id,
            $this->company($route),
            $assignee === '' ? null : $assignee,
            $goal === '' ? null : $goal,
        ));
    }

    public function createTask(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireUser();

        return $this->responses->success($this->work->createTask(
            $actor->id,
            $this->company($route),
            $this->body($request),
        ));
    }

    public function showTask(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireUser();

        return $this->responses->success($this->work->getTask(
            $actor->id,
            $this->company($route),
            (string) $route->getArgument('taskId'),
        ));
    }

    public function updateTask(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireUser();

        return $this->responses->success($this->work->updateTask(
            $actor->id,
            $this->company($route),
            (string) $route->getArgument('taskId'),
            $this->body($request),
        ));
    }

    public function comment(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireUser();
        $body = $this->body($request);

        return $this->responses->success($this->work->comment(
            $actor->id,
            $this->company($route),
            (string) $route->getArgument('taskId'),
            (string) ($body['body'] ?? ''),
        ));
    }

    public function answerOpening(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireUser();
        $body = $this->body($request);

        return $this->responses->success($this->work->answerOpening(
            $actor->id,
            $this->company($route),
            (string) $route->getArgument('taskId'),
            trim((string) ($body['optionId'] ?? '')),
            trim((string) ($body['text'] ?? '')),
        ));
    }

    public function answerQuestions(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireUser();
        $body = $this->body($request);
        $commentId = $body['commentId'] ?? null;
        $answers = $body['answers'] ?? [];

        return $this->responses->success($this->work->answerQuestions(
            $actor->id,
            $this->company($route),
            (string) $route->getArgument('taskId'),
            is_string($commentId) && trim($commentId) !== '' ? trim($commentId) : null,
            is_array($answers) ? array_values($answers) : [],
        ));
    }

    public function cancel(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireUser();
        $this->work->requestCancel($actor->id, $this->company($route), (string) $route->getArgument('taskId'));

        return $this->responses->success(['ok' => true]);
    }

    public function stream(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireUser();
        $companyId = $this->company($route);
        $taskId = (string) $route->getArgument('taskId');
        $this->work->getTask($actor->id, $companyId, $taskId);

        return new Response(
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ],
            new SseStream($this->work, $companyId, $taskId),
        );
    }

    public function agentComment(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireRun();
        $taskId = (string) $route->getArgument('taskId');
        if ($actor->taskId !== null && $actor->taskId !== $taskId) {
            throw new HolderException('forbidden', 'This run cannot comment on that task.', 403);
        }
        $body = $this->body($request);

        return $this->responses->success($this->work->agentComment(
            (string) $actor->companyId,
            $actor->id,
            $taskId,
            (string) ($body['body'] ?? ''),
        ));
    }

    public function askQuestions(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireRun();
        $taskId = (string) $route->getArgument('taskId');
        if ($actor->taskId !== null && $actor->taskId !== $taskId) {
            throw new HolderException('forbidden', 'This run cannot ask questions on that task.', 403);
        }

        return $this->responses->success($this->work->askQuestions(
            (string) $actor->companyId,
            $actor->id,
            $taskId,
            $this->body($request),
        ));
    }

    public function agentStatus(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireRun();
        $taskId = (string) $route->getArgument('taskId');
        if ($actor->taskId !== null && $actor->taskId !== $taskId) {
            throw new HolderException('forbidden', 'This run cannot update that task.', 403);
        }
        $body = $this->body($request);
        $blockerIds = null;
        if (array_key_exists('blockerIds', $body) && is_array($body['blockerIds'])) {
            $blockerIds = $body['blockerIds'];
        }

        return $this->responses->success($this->work->agentStatus(
            (string) $actor->companyId,
            $taskId,
            trim((string) ($body['status'] ?? '')),
            $blockerIds,
        ));
    }

    public function agentAssign(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireRun();
        $taskId = (string) $route->getArgument('taskId');
        if ($actor->taskId !== null && $actor->taskId !== $taskId) {
            throw new HolderException('forbidden', 'This run cannot assign that task.', 403);
        }
        $body = $this->body($request);

        return $this->responses->success($this->work->agentAssign(
            (string) $actor->companyId,
            $actor->id,
            $taskId,
            trim((string) ($body['assigneeAgentId'] ?? '')),
            $actor->runId,
        ));
    }

    private function company(CurrentRoute $route): string
    {
        return (string) $route->getArgument('companyId');
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
