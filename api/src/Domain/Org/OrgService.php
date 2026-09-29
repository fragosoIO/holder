<?php

declare(strict_types=1);

namespace App\Domain\Org;

use App\Domain\CompanyWorkspace;
use App\Domain\HolderException;
use App\Domain\Identity\IdentityService;
use App\Domain\Ids;
use App\Infrastructure\Db;

final class OrgService
{
    public function __construct(
        private readonly Db $db,
        private readonly IdentityService $identity,
        private readonly PiProbe $probe,
        private readonly CompanyWorkspace $workspaces,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listAgents(string $userId, string $companyId): array
    {
        $this->identity->requireMembership($userId, $companyId);
        $rows = $this->db->all(
            'SELECT * FROM agents WHERE company_id = :company_id ORDER BY created_at',
            ['company_id' => $companyId],
        );

        return array_map($this->resource(...), $rows);
    }

    /**
     * @return array{
     *     provider: string,
     *     model: string,
     *     thinking: string,
     *     models: list<array{provider: string, id: string, name: string, thinking: list<string>}>
     * }
     */
    public function piCatalog(string $userId, string $companyId, string $binary): array
    {
        $this->identity->requireMembership($userId, $companyId);

        return $this->probe->catalog($binary !== '' ? $binary : 'pi');
    }

    /**
     * @return array<string, mixed>
     */
    public function getAgent(string $userId, string $companyId, string $agentId): array
    {
        $this->identity->requireMembership($userId, $companyId);

        return $this->resource($this->requireRow($companyId, $agentId));
    }

    /**
     * @param array<string, string> $input
     * @return array<string, mixed>
     */
    public function hire(string $userId, string $companyId, array $input): array
    {
        $membership = $this->identity->requireMembership($userId, $companyId);
        $this->identity->assertCanManage((string) $membership['role']);

        return $this->insertAgent($companyId, $input, false);
    }

    /**
     * The first agent of an onboarded organization: chief of staff, nobody above them.
     *
     * @param array<string, string> $input
     * @return array<string, mixed>
     */
    public function hireOnboardingChief(string $companyId, array $input): array
    {
        $input['title'] = 'Chief of Staff';
        $input['managerId'] = '';

        return $this->insertAgent($companyId, $input, true);
    }

    /**
     * The onboarding chief of staff hires an agent who reports to them.
     *
     * @param array<string, string> $input
     * @return array<string, mixed>
     */
    public function hireByOnboardingAgent(string $companyId, string $chiefId, array $input): array
    {
        $chief = $this->requireRow($companyId, $chiefId);
        if ((int) $chief['onboarding_first'] !== 1) {
            throw new HolderException('forbidden', 'Only the onboarding chief of staff can create agents.', 403);
        }
        $input['managerId'] = $chiefId;

        return $this->insertAgent($companyId, $input, false);
    }

    /**
     * @param array<string, string> $input
     * @return array<string, mixed>
     */
    private function insertAgent(string $companyId, array $input, bool $onboardingFirst): array
    {
        $name = $this->required($input, 'name');
        $workspace = $this->workspaces->ensure($companyId);
        $binary = $input['piBinary'] !== '' ? $input['piBinary'] : 'pi';
        $version = $this->probe->version($binary);
        $managerId = $input['managerId'] !== '' ? $input['managerId'] : null;
        if ($managerId !== null) {
            $this->requireRow($companyId, $managerId);
        }

        $id = Ids::uuid();
        $this->db->exec(
            'INSERT INTO agents (
                id, company_id, name, title, job_description, manager_id, status,
                pi_binary, pi_provider, pi_model, pi_thinking, workspace_path, pi_version,
                onboarding_first
            ) VALUES (
                :id, :company_id, :name, :title, :job_description, :manager_id, :status,
                :pi_binary, :pi_provider, :pi_model, :pi_thinking, :workspace_path, :pi_version,
                :onboarding_first
            )',
            [
                'id' => $id,
                'company_id' => $companyId,
                'name' => $name,
                'title' => $input['title'],
                'job_description' => $input['jobDescription'],
                'manager_id' => $managerId,
                'status' => 'active',
                'pi_binary' => $binary,
                'pi_provider' => $input['piProvider'],
                'pi_model' => $input['piModel'],
                'pi_thinking' => $input['piThinking'],
                'workspace_path' => $workspace,
                'pi_version' => $version,
                'onboarding_first' => $onboardingFirst ? 1 : 0,
            ],
        );

        return $this->resource($this->requireRow($companyId, $id));
    }

    /**
     * @param array<string, string> $input
     * @return array<string, mixed>
     */
    public function update(string $userId, string $companyId, string $agentId, array $input): array
    {
        $membership = $this->identity->requireMembership($userId, $companyId);
        $this->identity->assertCanManage((string) $membership['role']);
        $row = $this->requireRow($companyId, $agentId);
        if ((string) $row['status'] === 'terminated') {
            throw new HolderException('terminated', 'A terminated agent cannot be changed.', 409);
        }

        $binary = $input['piBinary'] !== '' ? $input['piBinary'] : (string) $row['pi_binary'];
        $version = $binary === (string) $row['pi_binary']
            ? (string) $row['pi_version']
            : $this->probe->version($binary);
        $workspace = $this->workspaces->ensure($companyId);
        $managerId = $input['managerId'] !== '' ? $input['managerId'] : null;
        if ($managerId === $agentId) {
            throw new HolderException('invalid_manager', 'An agent cannot report to itself.', 422);
        }
        if ($managerId !== null) {
            $this->requireRow($companyId, $managerId);
        }

        $this->db->exec(
            'UPDATE agents SET
                name = :name,
                title = :title,
                job_description = :job_description,
                manager_id = :manager_id,
                pi_binary = :pi_binary,
                pi_provider = :pi_provider,
                pi_model = :pi_model,
                pi_thinking = :pi_thinking,
                workspace_path = :workspace_path,
                pi_version = :pi_version
             WHERE id = :id AND company_id = :company_id',
            [
                'name' => $input['name'] !== '' ? $input['name'] : (string) $row['name'],
                'title' => $input['title'],
                'job_description' => $input['jobDescription'],
                'manager_id' => $managerId,
                'pi_binary' => $binary,
                'pi_provider' => $input['piProvider'],
                'pi_model' => $input['piModel'],
                'pi_thinking' => $input['piThinking'],
                'workspace_path' => $workspace,
                'pi_version' => $version,
                'id' => $agentId,
                'company_id' => $companyId,
            ],
        );

        return $this->resource($this->requireRow($companyId, $agentId));
    }

    /**
     * @return array<string, mixed>
     */
    public function setStatus(string $userId, string $companyId, string $agentId, string $status): array
    {
        $membership = $this->identity->requireMembership($userId, $companyId);
        $this->identity->assertCanManage((string) $membership['role']);
        $row = $this->requireRow($companyId, $agentId);
        $current = (string) $row['status'];
        if ($current === 'terminated') {
            throw new HolderException('terminated', 'A terminated agent stays terminated.', 409);
        }
        if ($status === 'active' && $current !== 'paused') {
            throw new HolderException('invalid_status', 'Only a paused agent can be resumed.', 409);
        }
        if ($status === 'paused' && $current !== 'active') {
            throw new HolderException('invalid_status', 'Only an active agent can be paused.', 409);
        }

        $this->db->exec(
            'UPDATE agents SET status = :status WHERE id = :id AND company_id = :company_id',
            ['status' => $status, 'id' => $agentId, 'company_id' => $companyId],
        );

        return $this->resource($this->requireRow($companyId, $agentId));
    }

    /**
     * @return array<string, mixed>
     */
    public function requireRow(string $companyId, string $agentId): array
    {
        $row = $this->db->one(
            'SELECT * FROM agents WHERE id = :id AND company_id = :company_id',
            ['id' => $agentId, 'company_id' => $companyId],
        );
        if ($row === null) {
            throw new HolderException('not_found', 'Agent not found.', 404);
        }

        return $row;
    }

    /**
     * @param array<string, string> $input
     */
    private function required(array $input, string $key): string
    {
        $value = trim($input[$key] ?? '');
        if ($value === '') {
            throw new HolderException('missing_field', $key . ' is required.', 422);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function resource(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'companyId' => (string) $row['company_id'],
            'name' => (string) $row['name'],
            'title' => (string) $row['title'],
            'jobDescription' => (string) $row['job_description'],
            'managerId' => $row['manager_id'] !== null ? (string) $row['manager_id'] : null,
            'status' => (string) $row['status'],
            'piBinary' => (string) $row['pi_binary'],
            'piProvider' => (string) $row['pi_provider'],
            'piModel' => (string) $row['pi_model'],
            'piThinking' => (string) $row['pi_thinking'],
            'workspacePath' => $this->workspaces->ensure((string) $row['company_id']),
            'sessionId' => $row['session_id'] !== null ? (string) $row['session_id'] : null,
            'piVersion' => $row['pi_version'] !== null ? (string) $row['pi_version'] : null,
            'monthlyBudgetCents' => $row['monthly_budget_cents'] !== null ? (int) $row['monthly_budget_cents'] : null,
            'spentCents' => (int) $row['spent_cents'],
        ];
    }
}
