<?php

declare(strict_types=1);

namespace App\Domain\Work;

use App\Domain\CompanyWorkspace;
use App\Domain\Github\RepoCheckout;
use App\Domain\HolderConfig;
use App\Domain\HolderException;
use App\Domain\Identity\IdentityService;
use App\Infrastructure\Db;

final class PreviewService
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private readonly PreviewSite $site;

    private readonly PreviewToken $tokens;

    public function __construct(
        private readonly Db $db,
        private readonly IdentityService $identity,
        private readonly HolderConfig $config,
        private readonly CompanyWorkspace $workspaces,
        private readonly RepoCheckout $checkout,
    ) {
        $this->site = new PreviewSite();
        $this->tokens = new PreviewToken($config->secretsKey);
    }

    /**
     * @return array{state: string, revision: string, url: string|null, expiresAt: int}
     */
    public function describe(string $userId, string $companyId, string $taskId): array
    {
        $this->assertIds($companyId, $taskId);
        $this->identity->requireMembership($userId, $companyId);
        $task = $this->task($companyId, $taskId);
        if ($task === null) {
            throw new HolderException('not_found', 'Task not found.', 404);
        }

        return $this->present($companyId, $taskId, $this->locate($task));
    }

    /**
     * @return array{path: string, type: string}|null
     */
    public function open(string $companyId, string $taskId, string $token, string $path): ?array
    {
        if (preg_match(self::UUID, $companyId) !== 1 || preg_match(self::UUID, $taskId) !== 1) {
            return null;
        }
        if (!$this->tokens->verify($token, $companyId, $taskId)) {
            return null;
        }
        $task = $this->task($companyId, $taskId);
        if ($task === null) {
            return null;
        }
        $located = $this->locate($task);
        if ($located['root'] === null) {
            return null;
        }
        $relative = rawurldecode($path);
        $file = $this->site->file($located['root'], $relative);
        $type = $this->site->contentType($relative);
        if ($file === null || $type === null) {
            return null;
        }

        return ['path' => $file, 'type' => $type];
    }

    private function assertIds(string $companyId, string $taskId): void
    {
        if (preg_match(self::UUID, $companyId) !== 1 || preg_match(self::UUID, $taskId) !== 1) {
            throw new HolderException('not_found', 'Task not found.', 404);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function task(string $companyId, string $taskId): ?array
    {
        return $this->db->one(
            'SELECT * FROM tasks WHERE id = :id AND company_id = :company_id',
            ['id' => $taskId, 'company_id' => $companyId],
        );
    }

    /**
     * @param array<string, mixed> $task
     * @return array{state: string, root: string|null}
     */
    private function locate(array $task): array
    {
        $companyId = (string) $task['company_id'];
        $taskId = (string) $task['id'];
        if ($task['project_id'] !== null) {
            $project = $this->db->one(
                'SELECT repo_url FROM projects WHERE id = :id AND company_id = :company_id',
                ['id' => $task['project_id'], 'company_id' => $companyId],
            );
            if ($project !== null && (string) $project['repo_url'] !== '') {
                $directory = $this->checkout->worktreeDirectory($taskId);
                $real = realpath($directory);
                $worktrees = realpath($this->config->dataDir . '/worktrees');
                if (
                    $real === false
                    || !is_dir($real)
                    || $worktrees === false
                    || !str_starts_with($real, $worktrees . '/')
                ) {
                    return ['state' => 'no_checkout', 'root' => null];
                }
                $root = $this->site->root($real);

                return $root === null
                    ? ['state' => 'no_page', 'root' => null]
                    : ['state' => 'ready', 'root' => $root];
            }
        }

        $workspace = $this->workspaces->ensure($companyId);
        $root = $this->site->root($workspace);

        return $root === null
            ? ['state' => 'no_page', 'root' => null]
            : ['state' => 'ready', 'root' => $root];
    }

    /**
     * @param array{state: string, root: string|null} $located
     * @return array{state: string, revision: string, url: string|null, expiresAt: int}
     */
    private function present(string $companyId, string $taskId, array $located): array
    {
        if ($located['root'] === null) {
            return [
                'state' => $located['state'],
                'revision' => '',
                'url' => null,
                'expiresAt' => 0,
            ];
        }
        $issued = $this->tokens->issue($companyId, $taskId);

        return [
            'state' => 'ready',
            'revision' => $this->site->revision($located['root']),
            'url' => '/api/v1/companies/' . $companyId . '/tasks/' . $taskId . '/preview/' . $issued['token'] . '/index.html',
            'expiresAt' => $issued['expiresAt'],
        ];
    }
}
