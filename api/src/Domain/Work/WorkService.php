<?php

declare(strict_types=1);

namespace App\Domain\Work;

use App\Domain\Github\GithubUrl;
use App\Domain\Github\RepoCheckout;
use App\Domain\Github\TokenCipher;
use App\Domain\HolderException;
use App\Domain\Identity\IdentityService;
use App\Domain\Ids;
use App\Domain\Org\OrgService;
use App\Infrastructure\Db;

final class WorkService
{
    private const TASK_STATUSES = ['todo', 'in_progress', 'blocked', 'in_review', 'done', 'cancelled'];

    private readonly AgentQuestions $agentQuestions;

    public function __construct(
        private readonly Db $db,
        private readonly IdentityService $identity,
        private readonly OrgService $org,
        private readonly RepoCheckout $checkout,
        private readonly TokenCipher $cipher,
    ) {
        $this->agentQuestions = new AgentQuestions();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listGoals(string $userId, string $companyId): array
    {
        $this->identity->requireMembership($userId, $companyId);
        $rows = $this->db->all(
            'SELECT * FROM goals WHERE company_id = :company_id ORDER BY created_at',
            ['company_id' => $companyId],
        );

        return array_map($this->goalResource(...), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function createGoal(string $userId, string $companyId, string $title, string $description, ?string $parentId): array
    {
        $membership = $this->identity->requireMembership($userId, $companyId);
        $this->identity->assertCanWrite((string) $membership['role']);
        if ($title === '') {
            throw new HolderException('missing_field', 'Title is required.', 422);
        }
        if ($parentId !== null) {
            $this->requireGoal($companyId, $parentId);
        }
        $id = Ids::uuid();
        $this->db->exec(
            'INSERT INTO goals (id, company_id, parent_id, title, description) VALUES (:id, :company_id, :parent_id, :title, :description)',
            [
                'id' => $id,
                'company_id' => $companyId,
                'parent_id' => $parentId,
                'title' => $title,
                'description' => $description,
            ],
        );

        return $this->goalResource($this->requireGoal($companyId, $id));
    }

    /**
     * @return array<string, mixed>
     */
    public function getGoal(string $userId, string $companyId, string $goalId): array
    {
        $this->identity->requireMembership($userId, $companyId);

        return $this->goalResource($this->requireGoal($companyId, $goalId));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listProjects(string $userId, string $companyId): array
    {
        $this->identity->requireMembership($userId, $companyId);
        $rows = $this->db->all(
            'SELECT * FROM projects WHERE company_id = :company_id ORDER BY created_at',
            ['company_id' => $companyId],
        );

        return array_map(fn (array $row): array => [
            'id' => (string) $row['id'],
            'companyId' => (string) $row['company_id'],
            'name' => (string) $row['name'],
            'workspacePath' => (string) $row['workspace_path'],
            'repoUrl' => (string) ($row['repo_url'] ?? ''),
            'defaultBranch' => (string) ($row['default_branch'] ?? ''),
        ], $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function createProject(
        string $userId,
        string $companyId,
        string $name,
        string $workspacePath,
        string $repoUrl = '',
    ): array {
        $membership = $this->identity->requireMembership($userId, $companyId);
        $this->identity->assertCanWrite((string) $membership['role']);
        if ($name === '') {
            throw new HolderException('missing_field', 'Name is required.', 422);
        }
        $id = Ids::uuid();
        $repoUrl = trim($repoUrl);
        $storedRepo = '';
        $branch = '';
        if ($repoUrl !== '') {
            $storedRepo = GithubUrl::canonicalize($repoUrl);
            $company = $this->db->one(
                'SELECT github_token FROM companies WHERE id = :id',
                ['id' => $companyId],
            );
            $stored = $company === null ? null : ($company['github_token'] ?? null);
            if (!is_string($stored) || $stored === '') {
                throw new HolderException('github_token_missing', 'github_token_missing', 422);
            }
            $branch = $this->checkout->cloneRepository($id, $storedRepo, $this->cipher->open($stored));
            $workspacePath = $this->checkout->directory($id);
        }
        $this->db->exec(
            'INSERT INTO projects (id, company_id, name, workspace_path, repo_url, default_branch)
             VALUES (:id, :company_id, :name, :workspace_path, :repo_url, :default_branch)',
            [
                'id' => $id,
                'company_id' => $companyId,
                'name' => $name,
                'workspace_path' => $workspacePath,
                'repo_url' => $storedRepo,
                'default_branch' => $branch,
            ],
        );

        return [
            'id' => $id,
            'companyId' => $companyId,
            'name' => $name,
            'workspacePath' => $workspacePath,
            'repoUrl' => $storedRepo,
            'defaultBranch' => $branch,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTasks(string $userId, string $companyId, ?string $assigneeId, ?string $goalId): array
    {
        $this->identity->requireMembership($userId, $companyId);
        $sql = 'SELECT * FROM tasks WHERE company_id = :company_id';
        $params = ['company_id' => $companyId];
        if ($assigneeId !== null) {
            $sql .= ' AND assignee_agent_id = :assignee_id';
            $params['assignee_id'] = $assigneeId;
        }
        if ($goalId !== null) {
            $sql .= ' AND goal_id = :goal_id';
            $params['goal_id'] = $goalId;
        }
        $sql .= ' ORDER BY updated_at DESC';

        return array_map(fn (array $row): array => $this->taskResource($row, false), $this->db->all($sql, $params));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createTask(string $userId, string $companyId, array $input): array
    {
        $membership = $this->identity->requireMembership($userId, $companyId);
        $this->identity->assertCanWrite((string) $membership['role']);
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new HolderException('missing_field', 'Title is required.', 422);
        }
        $status = (string) ($input['status'] ?? 'todo');
        $this->assertStatus($status);
        $goalId = $this->nullableId($input, 'goalId');
        $projectId = $this->nullableId($input, 'projectId');
        $parentId = $this->nullableId($input, 'parentId');
        $assigneeId = $this->nullableId($input, 'assigneeAgentId');
        if ($goalId !== null) {
            $this->requireGoal($companyId, $goalId);
        }
        if ($projectId !== null) {
            $this->requireProject($companyId, $projectId);
        }
        if ($parentId !== null) {
            $this->requireTaskRow($companyId, $parentId);
        }
        if ($assigneeId !== null) {
            $this->org->requireRow($companyId, $assigneeId);
        }

        $id = Ids::uuid();
        $this->db->transaction(function () use ($id, $companyId, $goalId, $projectId, $parentId, $assigneeId, $title, $input, $status): void {
            $this->db->exec(
                'INSERT INTO tasks (
                    id, company_id, goal_id, project_id, parent_id, assignee_agent_id, title, description, status
                ) VALUES (
                    :id, :company_id, :goal_id, :project_id, :parent_id, :assignee_agent_id, :title, :description, :status
                )',
                [
                    'id' => $id,
                    'company_id' => $companyId,
                    'goal_id' => $goalId,
                    'project_id' => $projectId,
                    'parent_id' => $parentId,
                    'assignee_agent_id' => $assigneeId,
                    'title' => $title,
                    'description' => (string) ($input['description'] ?? ''),
                    'status' => $status,
                ],
            );
            $this->replaceLabels($companyId, $id, $input['labels'] ?? []);
            $this->replaceBlockers($companyId, $id, $input['blockerIds'] ?? []);
            if ($status === 'blocked') {
                $this->promoteReadyBlockedTask($companyId, $id);
            }
            $this->wakeAssignee($companyId, $id, null, $status, $status);
        });

        return $this->getTask($userId, $companyId, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function getTask(string $userId, string $companyId, string $taskId): array
    {
        $this->identity->requireMembership($userId, $companyId);

        return $this->taskResource($this->requireTaskRow($companyId, $taskId), true);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateTask(string $userId, string $companyId, string $taskId, array $input): array
    {
        $membership = $this->identity->requireMembership($userId, $companyId);
        $this->identity->assertCanWrite((string) $membership['role']);
        $row = $this->requireTaskRow($companyId, $taskId);
        $status = array_key_exists('status', $input) ? (string) $input['status'] : (string) $row['status'];
        $this->assertStatus($status);
        $title = array_key_exists('title', $input) ? trim((string) $input['title']) : (string) $row['title'];
        if ($title === '') {
            throw new HolderException('missing_field', 'Title is required.', 422);
        }
        $assigneeId = array_key_exists('assigneeAgentId', $input)
            ? $this->emptyToNull($input['assigneeAgentId'])
            : ($row['assignee_agent_id'] !== null ? (string) $row['assignee_agent_id'] : null);
        if ($assigneeId !== null) {
            $this->org->requireRow($companyId, $assigneeId);
        }
        $goalId = array_key_exists('goalId', $input)
            ? $this->emptyToNull($input['goalId'])
            : ($row['goal_id'] !== null ? (string) $row['goal_id'] : null);
        if ($goalId !== null) {
            $this->requireGoal($companyId, $goalId);
        }

        $previousAssignee = $row['assignee_agent_id'] !== null ? (string) $row['assignee_agent_id'] : null;
        $previousStatus = (string) $row['status'];
        $this->db->transaction(function () use ($companyId, $taskId, $title, $input, $row, $status, $assigneeId, $goalId, $previousAssignee, $previousStatus): void {
            $this->db->exec(
                'UPDATE tasks SET
                    title = :title,
                    description = :description,
                    status = :status,
                    assignee_agent_id = :assignee_agent_id,
                    goal_id = :goal_id,
                    updated_at = NOW()
                 WHERE id = :id AND company_id = :company_id',
                [
                    'title' => $title,
                    'description' => array_key_exists('description', $input) ? (string) $input['description'] : (string) $row['description'],
                    'status' => $status,
                    'assignee_agent_id' => $assigneeId,
                    'goal_id' => $goalId,
                    'id' => $taskId,
                    'company_id' => $companyId,
                ],
            );
            if (isset($input['labels']) && is_array($input['labels'])) {
                $this->replaceLabels($companyId, $taskId, $input['labels']);
            }
            if (isset($input['blockerIds']) && is_array($input['blockerIds'])) {
                $this->replaceBlockers($companyId, $taskId, $input['blockerIds']);
                if ($status === 'blocked') {
                    $this->promoteReadyBlockedTask($companyId, $taskId);
                }
            }
            $this->deliverSubtaskReport($companyId, $taskId, $previousStatus, $status);
            $this->settleBlockers($companyId, $taskId, $previousStatus, $status);
            $this->wakeAssignee($companyId, $taskId, $previousAssignee, $previousStatus, $status);
        });

        return $this->getTask($userId, $companyId, $taskId);
    }

    /**
     * @return array<string, mixed>
     */
    public function comment(string $userId, string $companyId, string $taskId, string $body): array
    {
        $membership = $this->identity->requireMembership($userId, $companyId);
        $this->identity->assertCanWrite((string) $membership['role']);
        $task = $this->requireTaskRow($companyId, $taskId);

        return $this->addComment($companyId, $task, 'user', $userId, $body, true);
    }

    /**
     * Record the board's choice on the onboarding opening question and wake the assignee.
     *
     * @return array<string, mixed>
     */
    public function answerOpening(string $userId, string $companyId, string $taskId, string $optionId, string $text): array
    {
        $membership = $this->identity->requireMembership($userId, $companyId);
        $this->identity->assertCanWrite((string) $membership['role']);
        $task = $this->requireTaskRow($companyId, $taskId);
        $question = $this->openingQuestion($task);
        $options = is_array($question) ? ($question['options'] ?? null) : null;
        if (!is_array($question) || !is_array($options)) {
            throw new HolderException('no_opening_question', 'This task has no opening question.', 422);
        }

        $chosen = null;
        foreach ($options as $option) {
            if (is_array($option) && (string) ($option['id'] ?? '') === $optionId && $optionId !== '') {
                $chosen = $option;
                break;
            }
        }
        if ($chosen === null) {
            throw new HolderException('invalid_option', 'Choose one of the opening options.', 422);
        }

        $freeText = ($chosen['freeText'] ?? false) === true;
        if ($freeText && $text === '') {
            throw new HolderException('missing_field', 'Describe the task.', 422);
        }

        $prompt = trim((string) ($question['prompt'] ?? ''));
        $label = trim((string) ($chosen['label'] ?? ''));
        $lines = [];
        if ($prompt !== '') {
            $lines[] = $prompt;
        }
        $lines[] = $label;
        if ($freeText) {
            $lines[] = $text;
        }
        $this->addComment($companyId, $task, 'user', $userId, implode("\n", $lines), true, true, 'opening_answer');

        return $this->getTask($userId, $companyId, $taskId);
    }

    /**
     * @return array<string, mixed>
     */
    public function agentComment(string $companyId, string $agentId, string $taskId, string $body): array
    {
        $task = $this->requireTaskRow($companyId, $taskId);

        return $this->addComment($companyId, $task, 'agent', $agentId, $body, false);
    }

    /**
     * Save a question card. The board answers it with the same select as the onboarding opening question.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function askQuestions(string $companyId, string $agentId, string $taskId, array $input): array
    {
        $task = $this->requireTaskRow($companyId, $taskId);
        $normalized = $this->agentQuestions->normalize($input);
        if ($normalized['intro'] !== '') {
            $this->addComment($companyId, $task, 'agent', $agentId, $normalized['intro'], false);
        }
        $stored = ['intro' => '', 'questions' => $normalized['questions']];
        $this->db->exec(
            'UPDATE tasks SET agent_questions = CAST(:agent_questions AS jsonb), updated_at = NOW() WHERE id = :id AND company_id = :company_id',
            [
                'agent_questions' => json_encode($stored, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'id' => $taskId,
                'company_id' => $companyId,
            ],
        );
        $card = $this->agentQuestions->open($stored, []);
        if ($card === null) {
            throw new HolderException('invalid_question', 'The question card could not be saved.', 422);
        }

        return $card;
    }

    /**
     * Record the board's answers and wake the assignee.
     *
     * @param list<mixed> $answers
     * @return array<string, mixed>
     */
    public function answerQuestions(string $userId, string $companyId, string $taskId, ?string $commentId, array $answers): array
    {
        $membership = $this->identity->requireMembership($userId, $companyId);
        $this->identity->assertCanWrite((string) $membership['role']);
        $task = $this->requireTaskRow($companyId, $taskId);
        $comments = $this->db->all(
            'SELECT * FROM task_comments WHERE task_id = :task_id ORDER BY created_at',
            ['task_id' => $taskId],
        );
        $card = $this->agentQuestions->open($this->decodeJson($task['agent_questions'] ?? null), $comments);
        if ($card === null) {
            throw new HolderException('no_agent_questions', 'This task has no open questions.', 422);
        }
        $expected = $card['commentId'] ?? null;
        $expected = is_string($expected) && $expected !== '' ? $expected : null;
        if ($commentId !== $expected) {
            throw new HolderException('no_agent_questions', 'These questions are no longer open.', 422);
        }
        $this->addComment($companyId, $task, 'user', $userId, $this->agentQuestions->render($card, $answers), true, false, 'question_answer');

        return $this->getTask($userId, $companyId, $taskId);
    }

    /**
     * @param array<int|string, mixed>|null $blockerIds
     * @return array<string, mixed>
     */
    public function agentStatus(string $companyId, string $taskId, string $status, ?array $blockerIds = null): array
    {
        $this->assertStatus($status);
        $row = $this->requireTaskRow($companyId, $taskId);
        $previousStatus = (string) $row['status'];
        $previousAssignee = $row['assignee_agent_id'] !== null ? (string) $row['assignee_agent_id'] : null;
        $this->db->transaction(function () use ($companyId, $taskId, $status, $blockerIds, $previousStatus, $previousAssignee): void {
            $this->db->exec(
                'UPDATE tasks SET status = :status, updated_at = NOW() WHERE id = :id AND company_id = :company_id',
                ['status' => $status, 'id' => $taskId, 'company_id' => $companyId],
            );
            if ($blockerIds !== null) {
                $this->replaceBlockers($companyId, $taskId, $blockerIds);
                if ($status === 'blocked') {
                    $this->promoteReadyBlockedTask($companyId, $taskId);
                }
            }
            $this->deliverSubtaskReport($companyId, $taskId, $previousStatus, $status);
            $this->settleBlockers($companyId, $taskId, $previousStatus, $status);
            $this->wakeAssignee($companyId, $taskId, $previousAssignee, $previousStatus, $status);
        });

        return $this->taskResource($this->requireTaskRow($companyId, $taskId), true);
    }

    /**
     * Create a subtask for an active agent who reports directly to the caller.
     * The caller's task stays assigned to them and waits until that subtask is done.
     *
     * @return array<string, mixed>
     */
    public function agentAssign(
        string $companyId,
        string $agentId,
        string $taskId,
        string $assigneeId,
        ?string $runId,
    ): array {
        if ($assigneeId === '') {
            throw new HolderException('missing_field', 'Assignee is required.', 422);
        }
        $task = $this->requireTaskRow($companyId, $taskId);
        $current = $task['assignee_agent_id'] !== null ? (string) $task['assignee_agent_id'] : null;
        if ($current !== $agentId) {
            throw new HolderException('forbidden', 'This task is not assigned to you.', 403);
        }
        $status = (string) $task['status'];
        if ($status === 'done' || $status === 'cancelled') {
            throw new HolderException('closed', 'A finished task cannot be assigned.', 409);
        }
        $report = $this->org->requireRow($companyId, $assigneeId);
        if ((string) ($report['manager_id'] ?? '') !== $agentId) {
            throw new HolderException('not_a_report', 'You can only assign this task to an agent who reports to you.', 403);
        }
        if ((string) $report['status'] !== 'active') {
            throw new HolderException('agent_inactive', 'That agent is not active.', 409);
        }

        $childId = Ids::uuid();
        $this->db->transaction(function () use ($companyId, $agentId, $taskId, $assigneeId, $runId, $task, $report, $childId): void {
            $this->db->exec(
                'INSERT INTO tasks (
                    id, company_id, goal_id, project_id, parent_id, assignee_agent_id, title, description, status
                ) VALUES (
                    :id, :company_id, :goal_id, :project_id, :parent_id, :assignee_agent_id, :title, :description, :status
                )',
                [
                    'id' => $childId,
                    'company_id' => $companyId,
                    'goal_id' => $task['goal_id'],
                    'project_id' => $task['project_id'],
                    'parent_id' => $taskId,
                    'assignee_agent_id' => $assigneeId,
                    'title' => (string) $task['title'],
                    'description' => (string) $task['description'],
                    'status' => 'todo',
                ],
            );
            $this->db->exec(
                'INSERT INTO task_blockers (company_id, task_id, blocker_task_id) VALUES (:company_id, :task_id, :blocker_task_id)',
                ['company_id' => $companyId, 'task_id' => $taskId, 'blocker_task_id' => $childId],
            );
            $this->db->exec(
                'UPDATE tasks SET status = :status, agent_questions = NULL, updated_at = NOW() WHERE id = :id AND company_id = :company_id',
                ['status' => 'blocked', 'id' => $taskId, 'company_id' => $companyId],
            );
            $this->db->exec(
                'INSERT INTO task_comments (id, company_id, task_id, author_type, author_id, body) VALUES (:id, :company_id, :task_id, :author_type, :author_id, :body)',
                [
                    'id' => Ids::uuid(),
                    'company_id' => $companyId,
                    'task_id' => $taskId,
                    'author_type' => 'agent',
                    'author_id' => $agentId,
                    'body' => 'Assigned a subtask to ' . (string) $report['name'] . '.',
                ],
            );
            $this->db->exec(
                "UPDATE wakeups SET status = 'cancelled' WHERE agent_id = :agent_id AND task_id = :task_id AND status = 'pending'",
                ['agent_id' => $agentId, 'task_id' => $taskId],
            );
            if ($runId !== null && $runId !== '') {
                $this->db->exec(
                    "UPDATE runs SET cancel_requested = 1 WHERE id = :id AND company_id = :company_id AND agent_id = :agent_id AND task_id = :task_id AND status = 'running'",
                    [
                        'id' => $runId,
                        'company_id' => $companyId,
                        'agent_id' => $agentId,
                        'task_id' => $taskId,
                    ],
                );
            }
            $this->enqueue($companyId, $assigneeId, $childId, 'assignment');
        });

        return $this->taskResource($this->requireTaskRow($companyId, $taskId), true);
    }

    public function requestCancel(string $userId, string $companyId, string $taskId): void
    {
        $membership = $this->identity->requireMembership($userId, $companyId);
        $this->identity->assertCanWrite((string) $membership['role']);
        $this->requireTaskRow($companyId, $taskId);
        $this->db->exec(
            "UPDATE runs SET cancel_requested = 1 WHERE task_id = :task_id AND company_id = :company_id AND status = 'running'",
            ['task_id' => $taskId, 'company_id' => $companyId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function latestEventId(string $companyId, string $taskId): int
    {
        $this->requireTaskRow($companyId, $taskId);
        $row = $this->db->one(
            'SELECT COALESCE(MAX(e.id), 0) AS id FROM run_events e
             JOIN runs r ON r.id = e.run_id
             WHERE r.task_id = :task_id AND r.company_id = :company_id',
            ['task_id' => $taskId, 'company_id' => $companyId],
        );

        return (int) ($row['id'] ?? 0);
    }

    public function eventsForTask(string $companyId, string $taskId, int $afterId = 0): array
    {
        $this->requireTaskRow($companyId, $taskId);

        $events = $this->db->all(
            'SELECT e.* FROM run_events e
             JOIN runs r ON r.id = e.run_id
             WHERE r.task_id = :task_id AND r.company_id = :company_id AND e.id > :after_id
             ORDER BY e.id',
            ['task_id' => $taskId, 'company_id' => $companyId, 'after_id' => $afterId],
        );

        return array_values(array_filter($events, $this->visibleEvent(...)));
    }

    /**
     * Tool-argument and thinking deltas are huge and are not shown. Keep the text the page renders.
     *
     * @param array<string, mixed> $event
     */
    private function visibleEvent(array $event): bool
    {
        if ((string) $event['event_type'] !== 'message_update') {
            return true;
        }
        $payload = $event['payload'];
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($payload)) {
            return false;
        }
        $update = $payload['assistantMessageEvent'] ?? null;
        $kind = is_array($update) ? (string) ($update['type'] ?? '') : '';

        return $kind === 'text_delta';
    }

    public function taskIsQuiet(string $companyId, string $taskId): bool
    {
        $running = $this->db->one(
            "SELECT id FROM runs WHERE task_id = :task_id AND company_id = :company_id AND status = 'running' LIMIT 1",
            ['task_id' => $taskId, 'company_id' => $companyId],
        );
        if ($running !== null) {
            return false;
        }
        $pending = $this->db->one(
            "SELECT id FROM wakeups WHERE task_id = :task_id AND company_id = :company_id AND status = 'pending' LIMIT 1",
            ['task_id' => $taskId, 'company_id' => $companyId],
        );

        return $pending === null;
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function addComment(
        string $companyId,
        array $task,
        string $authorType,
        string $authorId,
        string $body,
        bool $wake,
        bool $clearOpening = false,
        string $wakeReason = 'comment',
    ): array {
        $body = trim($body);
        if ($body === '') {
            throw new HolderException('missing_field', 'Comment body is required.', 422);
        }
        $id = Ids::uuid();
        $taskId = (string) $task['id'];
        $this->db->transaction(function () use ($id, $companyId, $taskId, $authorType, $authorId, $body, $wake, $task, $clearOpening, $wakeReason): void {
            $this->db->exec(
                'INSERT INTO task_comments (id, company_id, task_id, author_type, author_id, body) VALUES (:id, :company_id, :task_id, :author_type, :author_id, :body)',
                [
                    'id' => $id,
                    'company_id' => $companyId,
                    'task_id' => $taskId,
                    'author_type' => $authorType,
                    'author_id' => $authorId,
                    'body' => $body,
                ],
            );
            if ($clearOpening) {
                $this->db->exec(
                    'UPDATE tasks SET opening_question = NULL, agent_questions = NULL, updated_at = NOW() WHERE id = :id AND company_id = :company_id',
                    ['id' => $taskId, 'company_id' => $companyId],
                );
            } elseif ($authorType === 'user') {
                $this->db->exec(
                    'UPDATE tasks SET agent_questions = NULL, updated_at = NOW() WHERE id = :id AND company_id = :company_id',
                    ['id' => $taskId, 'company_id' => $companyId],
                );
            } else {
                $this->db->exec(
                    'UPDATE tasks SET updated_at = NOW() WHERE id = :id',
                    ['id' => $taskId],
                );
            }
            if ($wake && $task['assignee_agent_id'] !== null && (string) $task['status'] !== 'blocked') {
                $this->enqueue($companyId, (string) $task['assignee_agent_id'], $taskId, $wakeReason);
            }
        });

        $row = $this->db->one('SELECT * FROM task_comments WHERE id = :id', ['id' => $id]);

        return $this->commentResource($row ?? []);
    }

    public function enqueue(string $companyId, string $agentId, ?string $taskId, string $reason): void
    {
        $updated = $this->db->one(
            "UPDATE wakeups SET task_id = COALESCE(:task_id, task_id), reason = :reason
             WHERE agent_id = :agent_id AND status = 'pending'
             RETURNING id",
            ['task_id' => $taskId, 'reason' => $reason, 'agent_id' => $agentId],
        );
        if ($updated !== null) {
            return;
        }
        try {
            $this->db->exec(
                'INSERT INTO wakeups (id, company_id, agent_id, task_id, status, reason) VALUES (:id, :company_id, :agent_id, :task_id, :status, :reason)',
                [
                    'id' => Ids::uuid(),
                    'company_id' => $companyId,
                    'agent_id' => $agentId,
                    'task_id' => $taskId,
                    'status' => 'pending',
                    'reason' => $reason,
                ],
            );
        } catch (\Throwable) {
            $this->db->exec(
                "UPDATE wakeups SET task_id = COALESCE(:task_id, task_id), reason = :reason WHERE agent_id = :agent_id AND status = 'pending'",
                ['task_id' => $taskId, 'reason' => $reason, 'agent_id' => $agentId],
            );
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function nullableId(array $input, string $key): ?string
    {
        if (!array_key_exists($key, $input)) {
            return null;
        }

        return $this->emptyToNull($input[$key]);
    }

    private function emptyToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function assertStatus(string $status): void
    {
        if (!in_array($status, self::TASK_STATUSES, true)) {
            throw new HolderException('invalid_status', 'Unknown task status.', 422);
        }
    }

    /**
     * @param array<int|string, mixed> $labels
     */
    private function replaceLabels(string $companyId, string $taskId, array $labels): void
    {
        $this->db->exec('DELETE FROM task_labels WHERE task_id = :task_id', ['task_id' => $taskId]);
        foreach ($labels as $label) {
            $text = trim((string) $label);
            if ($text === '') {
                continue;
            }
            $this->db->exec(
                'INSERT INTO task_labels (company_id, task_id, label) VALUES (:company_id, :task_id, :label) ON CONFLICT DO NOTHING',
                ['company_id' => $companyId, 'task_id' => $taskId, 'label' => $text],
            );
        }
    }

    /**
     * @param array<int|string, mixed> $blockerIds
     */
    private function replaceBlockers(string $companyId, string $taskId, array $blockerIds): void
    {
        $this->db->exec('DELETE FROM task_blockers WHERE task_id = :task_id', ['task_id' => $taskId]);
        $seen = [];
        foreach ($blockerIds as $blockerId) {
            $id = trim((string) $blockerId);
            if ($id === '' || $id === $taskId || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $this->requireTaskRow($companyId, $id);
            $this->assertNoBlockerCycle($companyId, $taskId, $id);
            $this->db->exec(
                'INSERT INTO task_blockers (company_id, task_id, blocker_task_id) VALUES (:company_id, :task_id, :blocker_task_id)',
                ['company_id' => $companyId, 'task_id' => $taskId, 'blocker_task_id' => $id],
            );
        }
    }

    private function assertNoBlockerCycle(string $companyId, string $taskId, string $blockerId): void
    {
        $seen = [];
        $pending = [$blockerId];
        while ($pending !== []) {
            $current = array_pop($pending);
            if (!is_string($current) || isset($seen[$current])) {
                continue;
            }
            if ($current === $taskId) {
                throw new HolderException('blocker_cycle', 'A task cannot be blocked by a task it already blocks.', 422);
            }
            $seen[$current] = true;
            $rows = $this->db->all(
                'SELECT blocker_task_id FROM task_blockers WHERE task_id = :task_id AND company_id = :company_id',
                ['task_id' => $current, 'company_id' => $companyId],
            );
            foreach ($rows as $row) {
                $pending[] = (string) $row['blocker_task_id'];
            }
        }
    }

    /**
     * When a subtask is finished, post that agent's comments on the parent.
     * A blocked parent is woken later, once every blocker is done. Any other open parent is woken now.
     */
    private function deliverSubtaskReport(string $companyId, string $taskId, string $previousStatus, string $requestedStatus): void
    {
        if ($previousStatus === 'done' || $requestedStatus !== 'done') {
            return;
        }
        $task = $this->requireTaskRow($companyId, $taskId);
        $parentId = $task['parent_id'] !== null ? (string) $task['parent_id'] : null;
        if ($parentId === null) {
            return;
        }
        $parent = $this->db->one(
            'SELECT * FROM tasks WHERE id = :id AND company_id = :company_id',
            ['id' => $parentId, 'company_id' => $companyId],
        );
        if ($parent === null) {
            return;
        }
        $assigneeId = $task['assignee_agent_id'] !== null ? (string) $task['assignee_agent_id'] : null;
        if ($assigneeId === null) {
            return;
        }
        $agent = $this->org->requireRow($companyId, $assigneeId);
        $comments = $this->db->all(
            "SELECT body FROM task_comments
             WHERE task_id = :task_id AND company_id = :company_id AND author_type = 'agent' AND author_id = :author_id
             ORDER BY created_at, id",
            ['task_id' => $taskId, 'company_id' => $companyId, 'author_id' => $assigneeId],
        );
        $parts = [];
        foreach ($comments as $comment) {
            $text = trim((string) $comment['body']);
            if ($text !== '') {
                $parts[] = $text;
            }
        }
        $body = (string) $agent['name'] . ' finished the subtask "' . (string) $task['title'] . '".';
        if ($parts !== []) {
            $body .= "\n\n" . implode("\n\n", $parts);
        }
        $this->db->exec(
            'INSERT INTO task_comments (id, company_id, task_id, author_type, author_id, body) VALUES (:id, :company_id, :task_id, :author_type, :author_id, :body)',
            [
                'id' => Ids::uuid(),
                'company_id' => $companyId,
                'task_id' => $parentId,
                'author_type' => 'agent',
                'author_id' => $assigneeId,
                'body' => $body,
            ],
        );
        $this->db->exec(
            'UPDATE tasks SET updated_at = NOW() WHERE id = :id AND company_id = :company_id',
            ['id' => $parentId, 'company_id' => $companyId],
        );
        $parentStatus = (string) $parent['status'];
        $parentAssignee = $parent['assignee_agent_id'] !== null ? (string) $parent['assignee_agent_id'] : null;
        if ($parentAssignee === null || $parentStatus === 'blocked' || $parentStatus === 'done' || $parentStatus === 'cancelled') {
            return;
        }
        $this->enqueue($companyId, $parentAssignee, $parentId, 'review');
    }

    /**
     * When a task becomes done, resume every blocked task whose blockers are all done.
     * Setting this task to blocked on its own does not clear that block: the agent may be waiting on something else.
     */
    private function settleBlockers(string $companyId, string $taskId, string $previousStatus, string $requestedStatus): void
    {
        if ($previousStatus !== 'done' && $requestedStatus === 'done') {
            $this->unblockDependents($companyId, $taskId);
        }
    }

    private function unblockDependents(string $companyId, string $doneTaskId): void
    {
        $dependents = $this->db->all(
            'SELECT t.id, t.assignee_agent_id
             FROM tasks t
             JOIN task_blockers b ON b.task_id = t.id AND b.company_id = t.company_id
             WHERE b.blocker_task_id = :blocker_id
               AND t.company_id = :company_id
               AND t.status = :blocked',
            ['blocker_id' => $doneTaskId, 'company_id' => $companyId, 'blocked' => 'blocked'],
        );
        foreach ($dependents as $dependent) {
            $taskId = (string) $dependent['id'];
            if (!$this->promoteReadyBlockedTask($companyId, $taskId)) {
                continue;
            }
            if ($dependent['assignee_agent_id'] === null) {
                continue;
            }
            $this->enqueue($companyId, (string) $dependent['assignee_agent_id'], $taskId, $this->wakeReasonForUnblock($companyId, $taskId));
        }
    }

    /**
     * A finished subtask asks the parent assignee to check the work. Other blockers only resume the task.
     */
    private function wakeReasonForUnblock(string $companyId, string $taskId): string
    {
        $child = $this->db->one(
            'SELECT child.id
             FROM tasks child
             JOIN task_blockers b ON b.blocker_task_id = child.id AND b.company_id = child.company_id
             WHERE b.task_id = :task_id
               AND b.company_id = :company_id
               AND child.parent_id = :task_id
               AND child.status = :done
             LIMIT 1',
            ['task_id' => $taskId, 'company_id' => $companyId, 'done' => 'done'],
        );

        return $child === null ? 'blockers_resolved' : 'review';
    }

    /**
     * A blocked task with at least one blocker returns to todo once every blocker is done.
     * Cancelled blockers stay unresolved. A block with no linked task stays blocked.
     */
    private function promoteReadyBlockedTask(string $companyId, string $taskId): bool
    {
        $open = $this->db->one(
            'SELECT COUNT(*) AS open_count
             FROM task_blockers b
             JOIN tasks blocker ON blocker.id = b.blocker_task_id
             WHERE b.task_id = :task_id AND b.company_id = :company_id AND blocker.status <> :done',
            ['task_id' => $taskId, 'company_id' => $companyId, 'done' => 'done'],
        );
        if ((int) ($open['open_count'] ?? 0) > 0) {
            return false;
        }
        $present = $this->db->one(
            'SELECT blocker_task_id FROM task_blockers WHERE task_id = :task_id AND company_id = :company_id LIMIT 1',
            ['task_id' => $taskId, 'company_id' => $companyId],
        );
        if ($present === null) {
            return false;
        }
        $updated = $this->db->one(
            'UPDATE tasks SET status = :todo, updated_at = NOW()
             WHERE id = :id AND company_id = :company_id AND status = :blocked
             RETURNING id',
            ['todo' => 'todo', 'id' => $taskId, 'company_id' => $companyId, 'blocked' => 'blocked'],
        );

        return $updated !== null;
    }

    private function wakeAssignee(
        string $companyId,
        string $taskId,
        ?string $previousAssignee,
        string $previousStatus,
        string $requestedStatus,
    ): void {
        $fresh = $this->requireTaskRow($companyId, $taskId);
        $status = (string) $fresh['status'];
        $assignee = $fresh['assignee_agent_id'] !== null ? (string) $fresh['assignee_agent_id'] : null;
        if ($assignee === null || $status === 'blocked' || $status === 'done' || $status === 'cancelled') {
            return;
        }

        $blockersCleared = $requestedStatus === 'blocked' && $status === 'todo';
        if ($blockersCleared) {
            $this->enqueue($companyId, $assignee, $taskId, 'blockers_resolved');

            return;
        }
        if ($assignee !== $previousAssignee) {
            $this->enqueue($companyId, $assignee, $taskId, 'assignment');

            return;
        }
        if ($previousStatus === 'blocked') {
            $this->enqueue($companyId, $assignee, $taskId, 'unblocked');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requireGoal(string $companyId, string $goalId): array
    {
        $row = $this->db->one(
            'SELECT * FROM goals WHERE id = :id AND company_id = :company_id',
            ['id' => $goalId, 'company_id' => $companyId],
        );
        if ($row === null) {
            throw new HolderException('not_found', 'Goal not found.', 404);
        }

        return $row;
    }

    private function requireProject(string $companyId, string $projectId): void
    {
        $row = $this->db->one(
            'SELECT id FROM projects WHERE id = :id AND company_id = :company_id',
            ['id' => $projectId, 'company_id' => $companyId],
        );
        if ($row === null) {
            throw new HolderException('not_found', 'Project not found.', 404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requireTaskRow(string $companyId, string $taskId): array
    {
        $row = $this->db->one(
            'SELECT * FROM tasks WHERE id = :id AND company_id = :company_id',
            ['id' => $taskId, 'company_id' => $companyId],
        );
        if ($row === null) {
            throw new HolderException('not_found', 'Task not found.', 404);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function goalResource(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'companyId' => (string) $row['company_id'],
            'parentId' => $row['parent_id'] !== null ? (string) $row['parent_id'] : null,
            'title' => (string) $row['title'],
            'description' => (string) $row['description'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function taskResource(array $row, bool $detailed): array
    {
        $task = [
            'id' => (string) $row['id'],
            'companyId' => (string) $row['company_id'],
            'goalId' => $row['goal_id'] !== null ? (string) $row['goal_id'] : null,
            'projectId' => $row['project_id'] !== null ? (string) $row['project_id'] : null,
            'parentId' => $row['parent_id'] !== null ? (string) $row['parent_id'] : null,
            'assigneeAgentId' => $row['assignee_agent_id'] !== null ? (string) $row['assignee_agent_id'] : null,
            'title' => (string) $row['title'],
            'description' => (string) $row['description'],
            'status' => (string) $row['status'],
            'checkoutRunId' => $row['checkout_run_id'] !== null ? (string) $row['checkout_run_id'] : null,
            'updatedAt' => (string) ($row['updated_at'] ?? ''),
            'openingQuestion' => $this->openingQuestion($row),
        ];
        if (!$detailed) {
            return $task;
        }

        $labels = $this->db->all(
            'SELECT label FROM task_labels WHERE task_id = :task_id ORDER BY label',
            ['task_id' => $row['id']],
        );
        $blockers = $this->db->all(
            'SELECT b.blocker_task_id, t.title, t.status
             FROM task_blockers b
             JOIN tasks t ON t.id = b.blocker_task_id
             WHERE b.task_id = :task_id
             ORDER BY t.title',
            ['task_id' => $row['id']],
        );
        $comments = $this->db->all(
            'SELECT * FROM task_comments WHERE task_id = :task_id ORDER BY created_at',
            ['task_id' => $row['id']],
        );
        $runs = $this->db->all(
            'SELECT * FROM runs WHERE task_id = :task_id ORDER BY started_at',
            ['task_id' => $row['id']],
        );
        $task['labels'] = array_map(static fn (array $label): string => (string) $label['label'], $labels);
        $task['blockerIds'] = array_map(static fn (array $blocker): string => (string) $blocker['blocker_task_id'], $blockers);
        $task['blockers'] = array_map(static fn (array $blocker): array => [
            'id' => (string) $blocker['blocker_task_id'],
            'title' => (string) $blocker['title'],
            'status' => (string) $blocker['status'],
        ], $blockers);
        $parentId = $task['parentId'];
        $task['parent'] = $this->parentSummary((string) $row['company_id'], is_string($parentId) ? $parentId : null);
        $children = $this->db->all(
            'SELECT id, title, status, assignee_agent_id
             FROM tasks
             WHERE parent_id = :parent_id AND company_id = :company_id
             ORDER BY created_at, id',
            ['parent_id' => $row['id'], 'company_id' => $row['company_id']],
        );
        $task['children'] = array_map(static fn (array $child): array => [
            'id' => (string) $child['id'],
            'title' => (string) $child['title'],
            'status' => (string) $child['status'],
            'assigneeAgentId' => $child['assignee_agent_id'] !== null ? (string) $child['assignee_agent_id'] : null,
        ], $children);
        $task['comments'] = array_map($this->commentResource(...), $comments);
        $task['agentQuestions'] = $this->agentQuestions->open($this->decodeJson($row['agent_questions'] ?? null), $comments);
        $task['runs'] = array_map(function (array $run): array {
            $events = array_values(array_filter(
                $this->db->all(
                    'SELECT id, event_type, payload FROM run_events WHERE run_id = :run_id ORDER BY id',
                    ['run_id' => $run['id']],
                ),
                $this->visibleEvent(...),
            ));

            return [
                'id' => (string) $run['id'],
                'status' => (string) $run['status'],
                'sessionId' => $run['session_id'] !== null ? (string) $run['session_id'] : null,
                'prompt' => (string) $run['prompt'],
                'events' => array_map(static function (array $event): array {
                    $payload = json_decode((string) $event['payload'], true);

                    return [
                        'id' => (int) $event['id'],
                        'type' => (string) $event['event_type'],
                        'payload' => is_array($payload) ? $payload : [],
                    ];
                }, $events),
            ];
        }, $runs);

        return $task;
    }

    /**
     * @return array{id: string, title: string, status: string}|null
     */
    private function parentSummary(string $companyId, ?string $parentId): ?array
    {
        if ($parentId === null) {
            return null;
        }
        $parent = $this->db->one(
            'SELECT id, title, status FROM tasks WHERE id = :id AND company_id = :company_id',
            ['id' => $parentId, 'company_id' => $companyId],
        );
        if ($parent === null) {
            return null;
        }

        return [
            'id' => (string) $parent['id'],
            'title' => (string) $parent['title'],
            'status' => (string) $parent['status'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function openingQuestion(array $row): ?array
    {
        return $this->decodeJson($row['opening_question'] ?? null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : null;
        }

        return is_array($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function commentResource(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'authorType' => (string) ($row['author_type'] ?? ''),
            'authorId' => (string) ($row['author_id'] ?? ''),
            'body' => (string) ($row['body'] ?? ''),
            'createdAt' => (string) ($row['created_at'] ?? ''),
        ];
    }
}
