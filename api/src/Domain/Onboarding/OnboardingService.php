<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Domain\CompanyWorkspace;
use App\Domain\HolderException;
use App\Domain\Identity\IdentityService;
use App\Domain\Ids;
use App\Domain\Org\OrgService;
use App\Domain\Work\WorkService;
use App\Infrastructure\Db;

final class OnboardingService
{
    public function __construct(
        private readonly Db $db,
        private readonly IdentityService $identity,
        private readonly OrgService $org,
        private readonly WorkService $work,
        private readonly CompanyWorkspace $workspaces,
        private readonly FirstTaskAssets $assets,
    ) {}

    /**
     * Create an organization, its chief of staff, and the single Paperclip first task.
     * A later call for the same organization returns that task instead of creating another.
     *
     * @param array<string, string> $input
     * @return array<string, mixed>
     */
    public function complete(string $userId, array $input): array
    {
        $companyId = trim($input['companyId'] ?? '');
        if ($companyId === '') {
            $name = trim($input['name'] ?? '');
            if ($name === '') {
                throw new HolderException('missing_field', 'Name is required.', 422);
            }
            $company = $this->identity->createCompany($userId, $name, '');
            $companyId = (string) $company['id'];
        } else {
            $company = $this->identity->requireCompany($userId, $companyId);
        }

        $existingTask = $this->db->one(
            'SELECT id FROM tasks WHERE company_id = :company_id AND onboarding_first = 1',
            ['company_id' => $companyId],
        );
        if ($existingTask !== null) {
            return $this->bundle($userId, $company, (string) $existingTask['id']);
        }

        $taskId = $this->db->transaction(function () use ($userId, $companyId, $company, $input): string {
            $chief = $this->db->one(
                'SELECT id FROM agents WHERE company_id = :company_id AND onboarding_first = 1',
                ['company_id' => $companyId],
            );
            if ($chief === null) {
                $agentName = trim($input['agentName'] ?? '');
                if ($agentName === '') {
                    throw new HolderException('missing_field', 'agentName is required.', 422);
                }
                $created = $this->org->hireOnboardingChief($companyId, [
                    'name' => $agentName,
                    'title' => '',
                    'jobDescription' => $this->assets->instructions($agentName, (string) $company['name']),
                    'managerId' => '',
                    'piBinary' => trim($input['piBinary'] ?? ''),
                    'piProvider' => trim($input['piProvider'] ?? ''),
                    'piModel' => trim($input['piModel'] ?? ''),
                    'piThinking' => trim($input['piThinking'] ?? ''),
                ]);
                $agentId = (string) $created['id'];
            } else {
                $agentId = (string) $chief['id'];
            }

            $project = $this->db->one(
                'SELECT id FROM projects WHERE company_id = :company_id AND name = :name ORDER BY created_at LIMIT 1',
                ['company_id' => $companyId, 'name' => 'Onboarding'],
            );
            if ($project === null) {
                $createdProject = $this->work->createProject(
                    $userId,
                    $companyId,
                    'Onboarding',
                    $this->workspaces->ensure($companyId),
                );
                $projectId = (string) $createdProject['id'];
            } else {
                $projectId = (string) $project['id'];
            }

            return $this->insertFirstTask($companyId, $projectId, $agentId);
        });

        return $this->bundle($userId, $this->identity->requireCompany($userId, $companyId), $taskId);
    }

    private function insertFirstTask(string $companyId, string $projectId, string $agentId): string
    {
        $agent = $this->org->requireRow($companyId, $agentId);
        $id = Ids::uuid();
        $this->db->exec(
            'INSERT INTO tasks (
                id, company_id, project_id, assignee_agent_id, title, description, status,
                onboarding_first, opening_question
            ) VALUES (
                :id, :company_id, :project_id, :assignee_agent_id, :title, :description, :status,
                :onboarding_first, CAST(:opening_question AS jsonb)
            )',
            [
                'id' => $id,
                'company_id' => $companyId,
                'project_id' => $projectId,
                'assignee_agent_id' => $agentId,
                'title' => 'Paperclip onboarding',
                'description' => $this->assets->brief(),
                'status' => 'todo',
                'onboarding_first' => 1,
                'opening_question' => json_encode(
                    $this->assets->openingQuestion(),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ),
            ],
        );
        $this->db->exec(
            'INSERT INTO task_comments (id, company_id, task_id, author_type, author_id, body)
             VALUES (:id, :company_id, :task_id, :author_type, :author_id, :body)',
            [
                'id' => Ids::uuid(),
                'company_id' => $companyId,
                'task_id' => $id,
                'author_type' => 'agent',
                'author_id' => $agentId,
                'body' => $this->assets->greeting((string) $agent['name']),
            ],
        );

        return $id;
    }

    /**
     * @param array<string, mixed> $company
     * @return array<string, mixed>
     */
    private function bundle(string $userId, array $company, string $taskId): array
    {
        $companyId = (string) $company['id'];
        $task = $this->work->getTask($userId, $companyId, $taskId);
        $agentId = (string) $task['assigneeAgentId'];
        $project = $this->db->one(
            'SELECT * FROM projects WHERE id = :id AND company_id = :company_id',
            ['id' => $task['projectId'], 'company_id' => $companyId],
        );
        if ($project === null) {
            throw new HolderException('not_found', 'Project not found.', 404);
        }

        return [
            'company' => $company,
            'agent' => $this->org->getAgent($userId, $companyId, $agentId),
            'project' => [
                'id' => (string) $project['id'],
                'companyId' => (string) $project['company_id'],
                'name' => (string) $project['name'],
                'workspacePath' => (string) $project['workspace_path'],
                'repoUrl' => (string) ($project['repo_url'] ?? ''),
                'defaultBranch' => (string) ($project['default_branch'] ?? ''),
            ],
            'task' => $task,
        ];
    }
}
