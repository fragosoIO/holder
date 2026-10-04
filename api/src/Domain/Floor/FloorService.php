<?php

declare(strict_types=1);

namespace App\Domain\Floor;

use App\Domain\Identity\IdentityService;
use App\Infrastructure\Db;

final readonly class FloorService
{
    public function __construct(
        private Db $db,
        private IdentityService $identity,
    ) {}

    /**
     * @return array{agents: list<array<string, mixed>>}
     */
    public function snapshot(string $userId, string $companyId): array
    {
        $this->identity->requireMembership($userId, $companyId);
        $agents = $this->db->all(
            'SELECT id::text AS id, name, title, status
             FROM agents
             WHERE company_id = :company_id AND status <> \'terminated\'
             ORDER BY created_at, id',
            ['company_id' => $companyId],
        );
        $tasks = $this->tasks($companyId);
        $events = $this->events($companyId);
        $rows = [];
        foreach ($agents as $agent) {
            $task = $tasks[(string) $agent['id']] ?? null;
            $runId = $task['run_id'] ?? null;
            $running = is_string($runId) && $runId !== '';
            $decision = FloorPlace::decide(
                (string) $agent['status'],
                $task === null ? null : (string) $task['status'],
                $running,
            );
            $step = $decision['step'];
            if ($step === null) {
                $event = $running ? ($events[$runId] ?? null) : null;
                $step = $event === null ? 'Starting' : StepLine::fromEvent($event);
            }
            $rows[] = [
                'id' => (string) $agent['id'],
                'name' => (string) $agent['name'],
                'title' => (string) $agent['title'],
                'status' => (string) $agent['status'],
                'place' => $decision['place'],
                'step' => $step,
                'task' => $task === null ? null : [
                    'id' => (string) $task['id'],
                    'title' => (string) $task['title'],
                    'status' => (string) $task['status'],
                ],
            ];
        }

        return ['agents' => $rows];
    }

    /**
     * @return array<string, array{id: string, title: string, status: string, run_id: string|null}>
     */
    private function tasks(string $companyId): array
    {
        $rows = $this->db->all(
            'SELECT DISTINCT ON (t.assignee_agent_id)
                    t.assignee_agent_id::text AS agent_id,
                    t.id::text AS id,
                    t.title,
                    t.status,
                    r.id::text AS run_id
             FROM tasks t
             LEFT JOIN runs r
               ON r.id = t.checkout_run_id
              AND r.agent_id = t.assignee_agent_id
              AND r.status = \'running\'
             WHERE t.company_id = :company_id
               AND t.assignee_agent_id IS NOT NULL
               AND (r.id IS NOT NULL OR t.status NOT IN (\'done\', \'cancelled\'))
             ORDER BY t.assignee_agent_id,
                      (r.id IS NOT NULL) DESC,
                      r.started_at DESC NULLS LAST,
                      t.updated_at DESC,
                      t.id',
            ['company_id' => $companyId],
        );
        $tasks = [];
        foreach ($rows as $row) {
            $tasks[(string) $row['agent_id']] = [
                'id' => (string) $row['id'],
                'title' => (string) $row['title'],
                'status' => (string) $row['status'],
                'run_id' => $row['run_id'] !== null ? (string) $row['run_id'] : null,
            ];
        }

        return $tasks;
    }

    /**
     * @return array<string, array{event_type: string, payload: mixed}>
     */
    private function events(string $companyId): array
    {
        $rows = $this->db->all(
            'SELECT DISTINCT ON (e.run_id)
                    e.run_id::text AS run_id,
                    e.event_type,
                    e.payload::text AS payload
             FROM run_events e
             JOIN runs r ON r.id = e.run_id
             WHERE r.company_id = :company_id
               AND r.status = \'running\'
               AND e.event_type IN (\'tool_execution_start\', \'holder.error\')
             ORDER BY e.run_id, e.id DESC',
            ['company_id' => $companyId],
        );
        $events = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['payload'], true);
            $events[(string) $row['run_id']] = [
                'event_type' => (string) $row['event_type'],
                'payload' => $decoded,
            ];
        }

        return $events;
    }
}
