<?php

declare(strict_types=1);

namespace App\Domain\Heartbeat;

use App\Domain\CompanyWorkspace;
use App\Domain\Github\GitClient;
use App\Domain\Github\RepoCheckout;
use App\Domain\Github\TokenCipher;
use App\Domain\HolderConfig;
use App\Domain\Ids;
use App\Infrastructure\Db;

final class HeartbeatWorker
{
    public function __construct(
        private readonly Db $db,
        private readonly PromptBuilder $prompts,
        private readonly RunToken $tokens,
        private readonly HolderConfig $config,
        private readonly PiRpcClient $pi,
        private readonly CompanyWorkspace $workspaces,
        private readonly RepoCheckout $checkout,
        private readonly TokenCipher $cipher,
        private readonly GitClient $git,
    ) {}

    public function run(bool $once): int
    {
        $processed = 0;
        do {
            $this->recoverOrphans();
            $claimed = $this->claim();
            if ($claimed === null) {
                if ($once) {
                    break;
                }
                sleep(1);
                continue;
            }
            $this->execute($claimed);
            $processed++;
            if ($once) {
                break;
            }
        } while (true);

        return $processed;
    }

    private function recoverOrphans(): void
    {
        $rows = $this->db->all("SELECT * FROM runs WHERE status = 'running'");
        foreach ($rows as $row) {
            $workerPid = $row['worker_pid'] !== null ? (int) $row['worker_pid'] : 0;
            $piPid = $row['pi_pid'] !== null ? (int) $row['pi_pid'] : 0;
            if ($this->alive($workerPid) && $this->alive($piPid)) {
                continue;
            }
            if ($this->alive($piPid)) {
                if (function_exists('posix_kill')) {
                    posix_kill($piPid, \SIGTERM);
                }
            }
            $this->finishRun((string) $row['id'], 'failed', null);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function claim(): ?array
    {
        return $this->db->transaction(function (): ?array {
            return $this->db->one(WakeupSql::CLAIM);
        });
    }

    /**
     * @param array<string, mixed> $wakeup
     */
    private function execute(array $wakeup): void
    {
        $agent = $this->db->one('SELECT * FROM agents WHERE id = :id', ['id' => $wakeup['agent_id']]);
        if ($agent === null || (string) $agent['status'] !== 'active') {
            $this->db->exec("UPDATE wakeups SET status = 'cancelled' WHERE id = :id", ['id' => $wakeup['id']]);

            return;
        }
        $budget = $agent['monthly_budget_cents'];
        if ($budget !== null && (int) $agent['spent_cents'] >= (int) $budget) {
            $this->db->exec(
                "UPDATE agents SET status = 'paused' WHERE id = :id",
                ['id' => $agent['id']],
            );
            $this->db->exec("UPDATE wakeups SET status = 'cancelled' WHERE id = :id", ['id' => $wakeup['id']]);

            return;
        }

        $task = null;
        if ($wakeup['task_id'] !== null) {
            $task = $this->db->one(
                'SELECT * FROM tasks WHERE id = :id AND company_id = :company_id',
                ['id' => $wakeup['task_id'], 'company_id' => $wakeup['company_id']],
            );
        }
        if ($task === null) {
            $this->db->exec("UPDATE wakeups SET status = 'done' WHERE id = :id", ['id' => $wakeup['id']]);

            return;
        }
        if ((string) $task['status'] === 'blocked') {
            $this->db->exec("UPDATE wakeups SET status = 'cancelled' WHERE id = :id", ['id' => $wakeup['id']]);

            return;
        }

        $company = $this->db->one('SELECT * FROM companies WHERE id = :id', ['id' => $wakeup['company_id']]);
        if ($company === null) {
            return;
        }
        $companyId = (string) $company['id'];
        $workspace = $this->workspaces->ensure($companyId);
        $comments = $this->db->all(
            'SELECT * FROM task_comments WHERE task_id = :task_id ORDER BY created_at',
            ['task_id' => $task['id']],
        );
        $task['blockers'] = $this->db->all(
            'SELECT t.title AS title, t.status AS status
             FROM task_blockers b
             JOIN tasks t ON t.id = b.blocker_task_id
             WHERE b.task_id = :task_id
             ORDER BY t.title',
            ['task_id' => $task['id']],
        );
        $reports = $this->db->all(
            'SELECT id, name, title, job_description
             FROM agents
             WHERE company_id = :company_id AND manager_id = :manager_id AND status = :status
             ORDER BY name, id',
            [
                'company_id' => $wakeup['company_id'],
                'manager_id' => $agent['id'],
                'status' => 'active',
            ],
        );
        /** @var array{repoUrl: string, branch: string, defaultBranch: string, reviewBranches: list<string>}|null $repository */
        $repository = null;
        $project = null;
        if ($task['project_id'] !== null) {
            $project = $this->db->one(
                'SELECT * FROM projects WHERE id = :id AND company_id = :company_id',
                ['id' => $task['project_id'], 'company_id' => $companyId],
            );
            if ($project !== null && (string) $project['repo_url'] !== '') {
                $repository = [
                    'repoUrl' => (string) $project['repo_url'],
                    'branch' => 'holder/' . $task['id'],
                    'defaultBranch' => (string) $project['default_branch'],
                    'reviewBranches' => $this->reviewBranches($companyId, (string) $task['id'], (string) $wakeup['reason']),
                ];
            }
        }
        $goalChain = $this->goalChain($task['goal_id'] !== null ? (string) $task['goal_id'] : null);
        $budgetRemaining = $budget === null ? null : (int) $budget - (int) $agent['spent_cents'];
        $wakeReason = (string) $wakeup['reason'];
        $prompt = $this->prompts->build(
            $company,
            $goalChain,
            $task,
            $comments,
            $agent,
            $budgetRemaining,
            $wakeReason,
            $reports,
            $repository,
        );

        $runId = Ids::uuid();
        $this->db->transaction(function () use ($runId, $wakeup, $agent, $task, $prompt): void {
            $this->db->exec(
                'INSERT INTO runs (
                    id, company_id, agent_id, task_id, wakeup_id, status, worker_pid, prompt
                ) VALUES (
                    :id, :company_id, :agent_id, :task_id, :wakeup_id, :status, :worker_pid, :prompt
                )',
                [
                    'id' => $runId,
                    'company_id' => $wakeup['company_id'],
                    'agent_id' => $agent['id'],
                    'task_id' => $task['id'],
                    'wakeup_id' => $wakeup['id'],
                    'status' => 'running',
                    'worker_pid' => getmypid() ?: 0,
                    'prompt' => $prompt,
                ],
            );
            $this->db->exec(
                'UPDATE tasks SET checkout_run_id = :run_id, status = CASE WHEN status = \'todo\' THEN \'in_progress\' ELSE status END, updated_at = NOW() WHERE id = :id',
                ['run_id' => $runId, 'id' => $task['id']],
            );
            $this->db->exec("UPDATE wakeups SET status = 'done' WHERE id = :id", ['id' => $wakeup['id']]);
        });

        $token = $this->tokens->issue(
            (string) $wakeup['company_id'],
            (string) $agent['id'],
            $runId,
            (string) $task['id'],
        );
        $sessionDir = $this->config->dataDir . '/sessions/' . $agent['id'];
        if (!is_dir($sessionDir)) {
            mkdir($sessionDir, 0775, true);
        }

        $args = ['--mode', 'rpc', '--session-dir', $sessionDir];
        if ($agent['session_id'] !== null && (string) $agent['session_id'] !== '') {
            $args[] = '--session';
            $args[] = (string) $agent['session_id'];
        }
        if ((string) $agent['pi_provider'] !== '') {
            $args[] = '--provider';
            $args[] = (string) $agent['pi_provider'];
        }
        if ((string) $agent['pi_model'] !== '') {
            $args[] = '--model';
            $args[] = (string) $agent['pi_model'];
        }
        if ((string) $agent['pi_thinking'] !== '') {
            $args[] = '--thinking';
            $args[] = (string) $agent['pi_thinking'];
        }

        $githubToken = null;
        try {
            if ($repository !== null && $project !== null) {
                $stored = $company['github_token'] ?? null;
                $githubToken = $this->openGithubToken(is_string($stored) ? $stored : '');
                $prepared = $this->checkout->prepare(
                    (string) $project['id'],
                    (string) $task['id'],
                    (string) $project['repo_url'],
                    (string) $project['default_branch'],
                    $githubToken,
                );
                $workspace = $prepared['worktree'];
                if ($prepared['defaultBranch'] !== (string) $project['default_branch']) {
                    $repository['defaultBranch'] = $prepared['defaultBranch'];
                    $prompt = $this->prompts->build(
                        $company,
                        $goalChain,
                        $task,
                        $comments,
                        $agent,
                        $budgetRemaining,
                        $wakeReason,
                        $reports,
                        $repository,
                    );
                    $this->db->exec(
                        'UPDATE projects SET default_branch = :default_branch WHERE id = :id',
                        ['default_branch' => $prepared['defaultBranch'], 'id' => $project['id']],
                    );
                    $this->db->exec(
                        'UPDATE runs SET prompt = :prompt WHERE id = :id',
                        ['prompt' => $prompt, 'id' => $runId],
                    );
                }
                $this->git->assertGh();
            }
        } catch (\Throwable $error) {
            $this->record($runId, $companyId, 'holder.error', [
                'message' => (new PiRunOutcome())->summarize($error->getMessage()),
            ]);
            $this->finishRun($runId, 'failed', null);

            return;
        }

        $env = $this->childEnv($token, (string) $task['id'], $githubToken);
        $sessionId = null;
        $status = 'failed';
        $failure = null;
        try {
            $this->pi->start((string) $agent['pi_binary'], $args, $workspace, $env);
            $this->db->exec('UPDATE runs SET pi_pid = :pid WHERE id = :id', ['pid' => $this->pi->pid(), 'id' => $runId]);
            $this->pi->prompt('prompt-1', $prompt);
            $status = $this->collect($runId, $companyId, (string) $task['id'], $sessionId, $failure);
        } catch (\Throwable $error) {
            $failure = (new PiRunOutcome())->summarize($error->getMessage());
            $this->record($runId, $companyId, 'holder.error', ['message' => $failure]);
            $status = 'failed';
        } finally {
            try {
                $this->pi->stop();
            } catch (\Throwable) {
                // The run record still has to be closed if the process is already gone.
            }
            $this->finishRun($runId, $status, $sessionId);
            if ($sessionId !== null) {
                $this->db->exec(
                    'UPDATE agents SET session_id = :session_id WHERE id = :id',
                    ['session_id' => $sessionId, 'id' => $agent['id']],
                );
            }
            if ($status === 'failed' && is_string($failure) && $failure !== '') {
                $this->noteFailure($companyId, (string) $task['id'], (string) $agent['id'], $failure);
            }
        }
    }

    private function collect(string $runId, string $companyId, string $taskId, ?string &$sessionId, ?string &$failure): string
    {
        $clock = new RunClock($this->config->runTimeoutSeconds, $this->config->runLimitSeconds);
        $startedAt = microtime(true);
        $lastActivityAt = $startedAt;
        $reason = 'idle';
        $outcomes = new PiRunOutcome();
        $failure = null;
        $steered = [];
        foreach ($this->db->all(
            "SELECT id FROM task_comments WHERE task_id = :task_id AND author_type = 'user'",
            ['task_id' => $taskId],
        ) as $existing) {
            $steered[(string) $existing['id']] = true;
        }
        $seq = 0;
        while (true) {
            $reason = $clock->reason($startedAt, $lastActivityAt, microtime(true));
            if ($reason !== null) {
                break;
            }
            $event = $this->pi->readEvent(0.4);
            if ($event !== null) {
                $lastActivityAt = microtime(true);
                $seq++;
                $type = (string) ($event['type'] ?? 'event');
                $this->record($runId, $companyId, $type, $event);
                if ($type === 'session' && isset($event['id']) && is_string($event['id'])) {
                    $sessionId = $event['id'];
                }
                $found = $outcomes->errorMessage($event);
                if ($found !== null) {
                    $failure = $found;
                }
                if ($type === 'agent_settled') {
                    if ($failure !== null) {
                        $this->record($runId, $companyId, 'holder.error', ['message' => $failure]);

                        return 'failed';
                    }

                    return 'settled';
                }
            }
            $run = $this->db->one('SELECT cancel_requested FROM runs WHERE id = :id', ['id' => $runId]);
            if ($run !== null && (int) $run['cancel_requested'] === 1) {
                $this->pi->abort('abort-' . $seq);
                $this->record($runId, $companyId, 'holder.cancel', ['message' => 'cancel requested']);

                return 'cancelled';
            }
            $fresh = $this->db->all(
                "SELECT * FROM task_comments WHERE task_id = :task_id AND author_type = 'user' ORDER BY created_at",
                ['task_id' => $taskId],
            );
            foreach ($fresh as $comment) {
                $id = (string) $comment['id'];
                if (isset($steered[$id])) {
                    continue;
                }
                $steered[$id] = true;
                $this->pi->steer('steer-' . $id, (string) $comment['body']);
            }
        }
        if ($failure === null) {
            $failure = $reason === 'limit'
                ? 'The run hit its time limit before it finished.'
                : 'The run timed out before it finished.';
        }
        $this->record($runId, $companyId, 'holder.error', ['message' => $failure]);
        $this->pi->abort('abort-timeout');

        return 'failed';
    }

    private function noteFailure(string $companyId, string $taskId, string $agentId, string $message): void
    {
        try {
            $this->db->exec(
                'INSERT INTO task_comments (id, company_id, task_id, author_type, author_id, body) VALUES (:id, :company_id, :task_id, :author_type, :author_id, :body)',
                [
                    'id' => Ids::uuid(),
                    'company_id' => $companyId,
                    'task_id' => $taskId,
                    'author_type' => 'agent',
                    'author_id' => $agentId,
                    'body' => $message,
                ],
            );
        } catch (\Throwable) {
            // The run is already marked failed. A comment is only the explanation.
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function record(string $runId, string $companyId, string $type, array $payload): void
    {
        $this->db->exec(
            'INSERT INTO run_events (company_id, run_id, event_type, payload) VALUES (:company_id, :run_id, :event_type, CAST(:payload AS jsonb))',
            [
                'company_id' => $companyId,
                'run_id' => $runId,
                'event_type' => $type,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ],
        );
    }

    private function finishRun(string $runId, string $status, ?string $sessionId): void
    {
        $this->db->exec(
            'UPDATE runs SET status = :status, finished_at = NOW(), session_id = COALESCE(:session_id, session_id) WHERE id = :id AND status = \'running\'',
            ['status' => $status, 'session_id' => $sessionId, 'id' => $runId],
        );
        $this->db->exec(
            'UPDATE tasks SET checkout_run_id = NULL, updated_at = NOW() WHERE checkout_run_id = :run_id',
            ['run_id' => $runId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function goalChain(?string $goalId): array
    {
        $chain = [];
        $guard = 0;
        while ($goalId !== null && $guard < 20) {
            $goal = $this->db->one('SELECT * FROM goals WHERE id = :id', ['id' => $goalId]);
            if ($goal === null) {
                break;
            }
            array_unshift($chain, $goal);
            $goalId = $goal['parent_id'] !== null ? (string) $goal['parent_id'] : null;
            $guard++;
        }

        return $chain;
    }

    /**
     * @return list<string>
     */
    private function reviewBranches(string $companyId, string $taskId, string $reason): array
    {
        if ($reason !== 'review') {
            return [];
        }

        $rows = $this->db->all(
            'SELECT id FROM tasks
             WHERE company_id = :company_id AND parent_id = :parent_id AND status = :status
             ORDER BY created_at',
            [
                'company_id' => $companyId,
                'parent_id' => $taskId,
                'status' => 'done',
            ],
        );
        $branches = [];
        foreach ($rows as $row) {
            $branches[] = 'holder/' . $row['id'];
        }

        return $branches;
    }

    private function openGithubToken(string $stored): string
    {
        if ($stored === '') {
            return '';
        }

        return $this->cipher->open($stored);
    }

    /**
     * @return array<string, string>
     */
    private function childEnv(string $token, string $taskId, ?string $githubToken): array
    {
        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        $stringEnv = [];
        foreach ($env as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $stringEnv[$key] = $value;
            }
        }
        unset($stringEnv['GH_TOKEN'], $stringEnv['GITHUB_TOKEN'], $stringEnv['GIT_ASKPASS']);
        if (is_string($githubToken) && $githubToken !== '') {
            $stringEnv['GH_TOKEN'] = $githubToken;
            $stringEnv['GITHUB_TOKEN'] = $githubToken;
            $stringEnv['GIT_ASKPASS'] = dirname($this->config->binPath) . '/holder-git-askpass';
            $stringEnv['GIT_TERMINAL_PROMPT'] = '0';
        }
        $binDir = dirname($this->config->binPath);
        $path = $stringEnv['PATH'] ?? '';
        $stringEnv['PATH'] = $binDir . ($path !== '' ? ':' . $path : '');
        $stringEnv['HOLDER_API_URL'] = $this->config->apiUrl;
        $stringEnv['HOLDER_RUN_TOKEN'] = $token;
        $stringEnv['HOLDER_TASK_ID'] = $taskId;
        $stringEnv['HOLDER_BIN'] = $this->config->binPath;

        return $stringEnv;
    }

    private function alive(int $pid): bool
    {
        return $pid > 0 && file_exists('/proc/' . $pid);
    }
}
