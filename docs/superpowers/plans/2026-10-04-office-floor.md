# Live Office Floor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `/floor` page that shows every non-terminated agent in the current company as a sprite in a pixel office, walking to a work spot while a run is active, with a bubble for the task and the latest Pi step.

**Architecture:** `StepLine` and `FloorPlace` are pure. `FloorService` loads agents, the chosen task, and the newest tool or error event, then calls those two. `GET /api/v1/companies/{companyId}/floor` returns that snapshot. The Vue page polls it every 2 seconds and hands it to a Phaser scene. The scene snaps a sprite on first sight and walks it at 120 pixels per second after that.

**Tech Stack:** PHP 8.5 Yii3 API, PostgreSQL, Codeception, Vue 3, TypeScript, Phaser 3, Vite.

**Spec:** `docs/superpowers/specs/2026-10-04-office-floor-design.md`

## Global Constraints

- Route is `GET /api/v1/companies/{companyId}/floor`, named `floor/show`. Same session check as listing agents. No session is 401 `unauthenticated`. Another company's id is 404.
- Response `data.agents` is ordered by `created_at` ascending, then `id`. Terminated agents are omitted.
- `place` is `desk` or `work`. `step` is always a string. `task` is `{ id, title, status }` or `null`.
- Place rules, first match: paused → desk / `Paused`; task `blocked` or `in_review` → desk / `Waiting on you`; running checkout → work and a step line; otherwise desk / `At their desk`.
- Step lines use the `tool_execution_start` or `holder.error` with the greatest id. A newer `message_update` does not count. No such event → `Starting`. Unreadable tool payload → `Working`. `holder.error` → `Hit an error`.
- `edit` and `write` with a non-empty `args.path` → `Editing {path}`. `read` with a path → `Reading {path}`. `bash` with a command → `Running {command}` after collapsing whitespace. A command longer than 80 characters keeps 79 and appends `…`. Empty bash → `Running a command`. Any other non-empty tool name is that name. Names match case.
- The walk is vertical in the agent's own column, 120 pixels per second, idle facing down on arrival. First sight snaps. Later target changes retarget. Walks do not queue.
- Bubble lines cut at 22 code points. A longer line keeps 21 and appends `…`.
- Seat `i` uses spot `(i % 12) + 1` and adds `floor(i / 12) * 64` pixels to y.
- Art is original. Do not copy anything from `joonspk-research/generative_agents`.
- Poll interval is 2 seconds. Unmount aborts the in-flight request and destroys the game.
- Do not add a migration. Do not edit an applied migration.
- Test command, from the repo root: `docker compose run --rm --entrypoint php api vendor/bin/codecept run <suite> <test>`.
- UI typecheck, from `ui/`: `./node_modules/.bin/vue-tsc -b`.

## File structure

- Create `api/src/Domain/Floor/StepLine.php` — one event becomes one step string.
- Create `api/src/Domain/Floor/FloorPlace.php` — agent status, task status, and a running flag become a place and a desk step.
- Create `api/src/Domain/Floor/FloorService.php` — membership, queries, and the snapshot array.
- Create `api/src/Api/FloorEndpoints.php` — the GET handler.
- Modify `api/config/common/routes.php` — register `floor/show`.
- Create `api/tests/Unit/StepLineTest.php` and `api/tests/Unit/FloorPlaceTest.php`.
- Create `api/tests/Integration/FloorSnapshotTest.php` — the query ignores `message_update` and survives a bad payload.
- Create `api/tests/Api/FloorCest.php` — HTTP auth, isolation, desk, paused, terminated, blocked.
- Create `ui/src/floor/present.ts` — bubble text, initials, tint, and seat math.
- Create `ui/scripts/present.test.ts` — node test for that module. It stays outside `src/` so `vue-tsc` does not compile it.
- Create `ui/src/assets/floor/office.png`, `office.json`, `clerk.png`, `clerk.json` — original map and character.
- Create `ui/src/floor/OfficeScene.ts` — Phaser scene.
- Create `ui/src/views/FloorView.vue` — poll, banners, list, game mount.
- Modify `ui/src/types.ts` — `FloorAgent` and `FloorSnapshot`.
- Modify `ui/src/router.ts` and `ui/src/App.vue` — the route and the nav item.
- Modify `ui/package.json` and `ui/package-lock.json` — add `phaser`.

---

### Task 1: Format one run event as a step line

**Files:**
- Create: `api/src/Domain/Floor/StepLine.php`
- Test: `api/tests/Unit/StepLineTest.php`

**Interfaces:**
- Consumes: nothing
- Produces: `App\Domain\Floor\StepLine::fromEvent(array $event): string`. `$event` has `event_type` (`string`) and `payload` (`mixed`). Returns the step string from the spec.

- [ ] **Step 1: Write the failing test**

Create `api/tests/Unit/StepLineTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Floor\StepLine;
use Codeception\Test\Unit;

final class StepLineTest extends Unit
{
    public function testEditAndWriteCopyThePath(): void
    {
        $this->assertSame('Editing src/Login.php', StepLine::fromEvent($this->tool('edit', ['path' => 'src/Login.php'])));
        $this->assertSame('Editing src/Login.php', StepLine::fromEvent($this->tool('write', ['path' => 'src/Login.php'])));
    }

    public function testReadCopiesThePath(): void
    {
        $this->assertSame('Reading src/Login.php', StepLine::fromEvent($this->tool('read', ['path' => 'src/Login.php'])));
    }

    public function testBashCollapsesWhitespace(): void
    {
        $this->assertSame('Running npm test', StepLine::fromEvent($this->tool('bash', ['command' => "npm\n\ttest"])));
    }

    public function testBashKeepsEightyCharacters(): void
    {
        $command = str_repeat('a', 80);

        $this->assertSame('Running ' . $command, StepLine::fromEvent($this->tool('bash', ['command' => $command])));
    }

    public function testBashCutsAtEightyCharacters(): void
    {
        $command = str_repeat('a', 81);

        $this->assertSame('Running ' . str_repeat('a', 79) . '…', StepLine::fromEvent($this->tool('bash', ['command' => $command])));
    }

    public function testBashWithoutACommand(): void
    {
        $this->assertSame('Running a command', StepLine::fromEvent($this->tool('bash', [])));
        $this->assertSame('Running a command', StepLine::fromEvent($this->tool('bash', ['command' => '   '])));
    }

    public function testEditWithoutAPathUsesTheToolName(): void
    {
        $this->assertSame('edit', StepLine::fromEvent($this->tool('edit', [])));
    }

    public function testToolNameIsCaseSensitive(): void
    {
        $this->assertSame('Edit', StepLine::fromEvent($this->tool('Edit', ['path' => 'src/Login.php'])));
    }

    public function testEmptyToolNameIsWorking(): void
    {
        $this->assertSame('Working', StepLine::fromEvent($this->tool('', ['path' => 'src/Login.php'])));
    }

    public function testNonObjectPayloadIsWorking(): void
    {
        $this->assertSame('Working', StepLine::fromEvent([
            'event_type' => 'tool_execution_start',
            'payload' => 'edit',
        ]));
    }

    public function testHolderErrorIgnoresTheBody(): void
    {
        $this->assertSame('Hit an error', StepLine::fromEvent([
            'event_type' => 'holder.error',
            'payload' => ['message' => 'boom'],
        ]));
    }

    /**
     * @param array<string, mixed> $args
     * @return array{event_type: string, payload: array<string, mixed>}
     */
    private function tool(string $name, array $args): array
    {
        return [
            'event_type' => 'tool_execution_start',
            'payload' => ['toolName' => $name, 'args' => $args],
        ];
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose run --rm --entrypoint php api vendor/bin/codecept run Unit StepLineTest`

Expected: FAIL. `StepLine` is not defined.

- [ ] **Step 3: Write the implementation**

Create `api/src/Domain/Floor/StepLine.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Floor;

final class StepLine
{
    /**
     * @param array{event_type?: mixed, payload?: mixed} $event
     */
    public static function fromEvent(array $event): string
    {
        $type = (string) ($event['event_type'] ?? '');
        if ($type === 'holder.error') {
            return 'Hit an error';
        }
        $payload = $event['payload'] ?? null;
        if ($type !== 'tool_execution_start' || !is_array($payload)) {
            return 'Working';
        }
        $name = $payload['toolName'] ?? '';
        if (!is_string($name) || $name === '') {
            return 'Working';
        }
        $args = $payload['args'] ?? null;
        $path = is_array($args) && isset($args['path']) && is_string($args['path']) ? $args['path'] : '';
        $command = is_array($args) && isset($args['command']) && is_string($args['command']) ? $args['command'] : '';
        if (($name === 'edit' || $name === 'write') && $path !== '') {
            return 'Editing ' . $path;
        }
        if ($name === 'read' && $path !== '') {
            return 'Reading ' . $path;
        }
        if ($name === 'bash') {
            $command = trim((string) preg_replace('/\s+/', ' ', $command));
            if ($command === '') {
                return 'Running a command';
            }
            if (mb_strlen($command) > 80) {
                $command = mb_substr($command, 0, 79) . '…';
            }

            return 'Running ' . $command;
        }

        return $name;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose run --rm --entrypoint php api vendor/bin/codecept run Unit StepLineTest`

Expected: PASS. 11 tests.

- [ ] **Step 5: Commit**

```bash
git add api/src/Domain/Floor/StepLine.php api/tests/Unit/StepLineTest.php
git commit -m "feat: format a floor step from one run event"
```

---

### Task 2: Decide desk or work

**Files:**
- Create: `api/src/Domain/Floor/FloorPlace.php`
- Test: `api/tests/Unit/FloorPlaceTest.php`

**Interfaces:**
- Consumes: nothing
- Produces: `App\Domain\Floor\FloorPlace::decide(string $agentStatus, ?string $taskStatus, bool $running): array{place: string, step: string|null}`. `step` is `Paused`, `Waiting on you`, or `At their desk`. It is `null` only when `place` is `work`.

- [ ] **Step 1: Write the failing test**

Create `api/tests/Unit/FloorPlaceTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Floor\FloorPlace;
use Codeception\Test\Unit;

final class FloorPlaceTest extends Unit
{
    public function testIdleAgentStaysAtTheDesk(): void
    {
        $this->assertSame(
            ['place' => 'desk', 'step' => 'At their desk'],
            FloorPlace::decide('active', null, false),
        );
        $this->assertSame(
            ['place' => 'desk', 'step' => 'At their desk'],
            FloorPlace::decide('active', 'in_progress', false),
        );
    }

    public function testRunningAgentGoesToWork(): void
    {
        $this->assertSame(
            ['place' => 'work', 'step' => null],
            FloorPlace::decide('active', 'in_progress', true),
        );
    }

    public function testPausedAgentStaysSeatedWhileARunIsGoing(): void
    {
        $this->assertSame(
            ['place' => 'desk', 'step' => 'Paused'],
            FloorPlace::decide('paused', 'in_progress', true),
        );
    }

    public function testBlockedAndReviewSendTheAgentBack(): void
    {
        $this->assertSame(
            ['place' => 'desk', 'step' => 'Waiting on you'],
            FloorPlace::decide('active', 'blocked', true),
        );
        $this->assertSame(
            ['place' => 'desk', 'step' => 'Waiting on you'],
            FloorPlace::decide('active', 'in_review', true),
        );
    }

    public function testPausedBeatsWaiting(): void
    {
        $this->assertSame(
            ['place' => 'desk', 'step' => 'Paused'],
            FloorPlace::decide('paused', 'blocked', true),
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose run --rm --entrypoint php api vendor/bin/codecept run Unit FloorPlaceTest`

Expected: FAIL. `FloorPlace` is not defined.

- [ ] **Step 3: Write the implementation**

Create `api/src/Domain/Floor/FloorPlace.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Floor;

final class FloorPlace
{
    /**
     * @return array{place: string, step: string|null}
     */
    public static function decide(string $agentStatus, ?string $taskStatus, bool $running): array
    {
        if ($agentStatus === 'paused') {
            return ['place' => 'desk', 'step' => 'Paused'];
        }
        if ($taskStatus === 'blocked' || $taskStatus === 'in_review') {
            return ['place' => 'desk', 'step' => 'Waiting on you'];
        }
        if ($running) {
            return ['place' => 'work', 'step' => null];
        }

        return ['place' => 'desk', 'step' => 'At their desk'];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose run --rm --entrypoint php api vendor/bin/codecept run Unit FloorPlaceTest`

Expected: PASS. 5 tests.

- [ ] **Step 5: Commit**

```bash
git add api/src/Domain/Floor/FloorPlace.php api/tests/Unit/FloorPlaceTest.php
git commit -m "feat: decide whether a floor agent is at the desk or at work"
```

---

### Task 3: Build the floor snapshot

**Files:**
- Create: `api/src/Domain/Floor/FloorService.php`
- Test: `api/tests/Integration/FloorSnapshotTest.php`

**Interfaces:**
- Consumes: `StepLine::fromEvent(array $event): string` and `FloorPlace::decide(string $agentStatus, ?string $taskStatus, bool $running): array{place: string, step: string|null}`. `IdentityService::requireMembership(string $userId, string $companyId): array`. `Db::all` and `Db::one`.
- Produces: `App\Domain\Floor\FloorService::snapshot(string $userId, string $companyId): array{agents: list<array{id: string, name: string, title: string, status: string, place: string, step: string, task: array{id: string, title: string, status: string}|null}>}`. Constructor is `(Db $db, IdentityService $identity)`.

- [ ] **Step 1: Write the failing test**

Create `api/tests/Integration/FloorSnapshotTest.php`. Follow the connection helper in `api/tests/Integration/WakeupClaimTest.php`. Insert a company, a user, a membership, one agent, and one task. The task's `checkout_run_id` points at a `running` run for that agent. Insert a `tool_execution_start` for `edit` of `src/Login.php`, then a newer `message_update`. Call `snapshot` and expect `place` `work` and step `Editing src/Login.php`.

A second test inserts another agent whose only event payload is the JSON string `"not-json"` on a `tool_execution_start`, and expects `place` `work` and step `Working`.

A third test inserts an agent with a running run and no events, and expects step `Starting`.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\CompanyWorkspace;
use App\Domain\Floor\FloorService;
use App\Domain\Github\TokenCipher;
use App\Domain\HolderConfig;
use App\Domain\Identity\IdentityService;
use App\Domain\Ids;
use App\Infrastructure\Db;
use App\Shared\Env;
use Codeception\Test\Unit;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Pgsql\Connection;
use Yiisoft\Db\Pgsql\Driver;
use Yiisoft\Db\Pgsql\Dsn;

final class FloorSnapshotTest extends Unit
{
    private ?string $companyId = null;

    private ?string $userId = null;

    protected function tearDown(): void
    {
        if ($this->companyId !== null) {
            $this->connection()->createCommand(
                'DELETE FROM companies WHERE id = :id',
                [':id' => $this->companyId],
            )->execute();
        }
        if ($this->userId !== null) {
            $this->connection()->createCommand(
                'DELETE FROM users WHERE id = :id',
                [':id' => $this->userId],
            )->execute();
        }
        parent::tearDown();
    }

    public function testTheNewestToolBeatsANewerMessageUpdate(): void
    {
        [$service, $userId] = $this->service();
        $agentId = $this->agent('Ada');
        $runId = $this->runningRun($agentId, 'Login page');
        $this->event($runId, 'tool_execution_start', '{"toolName":"edit","args":{"path":"src/Login.php"}}');
        $this->event($runId, 'message_update', '{"assistantMessageEvent":{"type":"text_delta","delta":"hello"}}');

        $snapshot = $service->snapshot($userId, (string) $this->companyId);

        $this->assertCount(1, $snapshot['agents']);
        $this->assertSame('work', $snapshot['agents'][0]['place']);
        $this->assertSame('Editing src/Login.php', $snapshot['agents'][0]['step']);
        $this->assertSame('Login page', $snapshot['agents'][0]['task']['title'] ?? null);
    }

    public function testABadPayloadDoesNotFailTheSnapshot(): void
    {
        [$service, $userId] = $this->service();
        $agentId = $this->agent('Bea');
        $runId = $this->runningRun($agentId, 'Broken');
        $this->event($runId, 'tool_execution_start', '"not-json"');

        $snapshot = $service->snapshot($userId, (string) $this->companyId);

        $this->assertSame('work', $snapshot['agents'][0]['place']);
        $this->assertSame('Working', $snapshot['agents'][0]['step']);
    }

    public function testARunWithNoStepYetSaysStarting(): void
    {
        [$service, $userId] = $this->service();
        $agentId = $this->agent('Cy');
        $this->runningRun($agentId, 'Just started');

        $snapshot = $service->snapshot($userId, (string) $this->companyId);

        $this->assertSame('work', $snapshot['agents'][0]['place']);
        $this->assertSame('Starting', $snapshot['agents'][0]['step']);
    }

    /**
     * @return array{0: FloorService, 1: string}
     */
    private function service(): array
    {
        if (!extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql is required.');
        }
        $config = new HolderConfig(
            mode: 'authenticated',
            secretsKey: 'test-key',
            apiUrl: 'http://127.0.0.1:8081',
            dataDir: sys_get_temp_dir() . '/holder-floor-test',
            binPath: 'bin/holder',
            runTimeoutSeconds: 60,
            runLimitSeconds: 120,
        );
        $db = new Db($this->connection());
        $service = new FloorService(
            $db,
            new IdentityService($db, new CompanyWorkspace($config), new TokenCipher($config)),
        );
        $this->companyId = Ids::uuid();
        $this->userId = Ids::uuid();
        $db->exec(
            'INSERT INTO companies (id, name, mission) VALUES (:id, :name, :mission)',
            ['id' => $this->companyId, 'name' => 'Floor Co', 'mission' => ''],
        );
        $db->exec(
            'INSERT INTO users (id, name, email) VALUES (:id, :name, :email)',
            ['id' => $this->userId, 'name' => 'Floor Owner', 'email' => 'floor-' . $this->userId . '@holder.test'],
        );
        $db->exec(
            'INSERT INTO memberships (company_id, user_id, role) VALUES (:company_id, :user_id, :role)',
            ['company_id' => $this->companyId, 'user_id' => $this->userId, 'role' => 'owner'],
        );

        return [$service, $this->userId];
    }

    private function agent(string $name): string
    {
        $id = Ids::uuid();
        $this->connection()->createCommand(
            'INSERT INTO agents (id, company_id, name, title, job_description, status, workspace_path)
             VALUES (:id, :company_id, :name, :title, :job_description, :status, :workspace_path)',
            [
                ':id' => $id,
                ':company_id' => $this->companyId,
                ':name' => $name,
                ':title' => 'Engineer',
                ':job_description' => '',
                ':status' => 'active',
                ':workspace_path' => sys_get_temp_dir(),
            ],
        )->execute();

        return $id;
    }

    private function runningRun(string $agentId, string $title): string
    {
        $taskId = Ids::uuid();
        $runId = Ids::uuid();
        $connection = $this->connection();
        $connection->createCommand(
            'INSERT INTO tasks (id, company_id, assignee_agent_id, title, description, status)
             VALUES (:id, :company_id, :assignee_agent_id, :title, :description, :status)',
            [
                ':id' => $taskId,
                ':company_id' => $this->companyId,
                ':assignee_agent_id' => $agentId,
                ':title' => $title,
                ':description' => '',
                ':status' => 'in_progress',
            ],
        )->execute();
        $connection->createCommand(
            'INSERT INTO runs (id, company_id, agent_id, task_id, status)
             VALUES (:id, :company_id, :agent_id, :task_id, :status)',
            [
                ':id' => $runId,
                ':company_id' => $this->companyId,
                ':agent_id' => $agentId,
                ':task_id' => $taskId,
                ':status' => 'running',
            ],
        )->execute();
        $connection->createCommand(
            'UPDATE tasks SET checkout_run_id = :run_id WHERE id = :id',
            [':run_id' => $runId, ':id' => $taskId],
        )->execute();

        return $runId;
    }

    private function event(string $runId, string $type, string $payload): void
    {
        $this->connection()->createCommand(
            'INSERT INTO run_events (company_id, run_id, event_type, payload)
             VALUES (:company_id, :run_id, :event_type, CAST(:payload AS jsonb))',
            [
                ':company_id' => $this->companyId,
                ':run_id' => $runId,
                ':event_type' => $type,
                ':payload' => $payload,
            ],
        )->execute();
    }

    private function connection(): ConnectionInterface
    {
        return new Connection(
            new Driver(
                new Dsn(
                    host: Env::get('HOLDER_DB_HOST', '127.0.0.1'),
                    databaseName: Env::get('HOLDER_DB_NAME', 'holder'),
                    port: Env::get('HOLDER_DB_PORT', '5432'),
                ),
                Env::get('HOLDER_DB_USER', 'holder'),
                Env::get('HOLDER_DB_PASSWORD', 'holder'),
            ),
            new SchemaCache(new ArrayCache()),
        );
    }
}
```

The bad-payload test stores the JSON string `"not-json"`. Postgres accepts it. `json_decode` returns a PHP string, and `StepLine` treats that as `Working`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose run --rm --entrypoint php api vendor/bin/codecept run Integration FloorSnapshotTest`

Expected: FAIL. `FloorService` is not defined. If `pdo_pgsql` is missing the test skips; run it inside the api container, which has the extension.

- [ ] **Step 3: Write the implementation**

Create `api/src/Domain/Floor/FloorService.php`:

```php
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
```

`json_decode` of a JSON string returns a PHP string. `StepLine` then sees a non-array payload and returns `Working`. A `message_update` is not selected, so the older `edit` wins.

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose run --rm --entrypoint php api vendor/bin/codecept run Integration FloorSnapshotTest`

Expected: PASS. 3 tests.

- [ ] **Step 5: Commit**

```bash
git add api/src/Domain/Floor/FloorService.php api/tests/Integration/FloorSnapshotTest.php
git commit -m "feat: build the company floor snapshot"
```

---

### Task 4: Serve the snapshot over HTTP

**Files:**
- Create: `api/src/Api/FloorEndpoints.php`
- Modify: `api/config/common/routes.php`
- Test: `api/tests/Api/FloorCest.php`

**Interfaces:**
- Consumes: `FloorService::snapshot(string $userId, string $companyId): array{agents: list<array<string, mixed>>}`. `ActorContext::requireUser(): Actor`. `ResponseFactory::success`.
- Produces: `GET /api/v1/companies/{companyId}/floor` named `floor/show`. `data` is the snapshot.

- [ ] **Step 1: Write the failing test**

Create `api/tests/Api/FloorCest.php`. Sign in as `owner@holder.test` / `secret-pass`, then `POST /api/v1/companies` so each test has an empty company. Hire with `piBinary` set to `dirname(__DIR__) . '/fixtures/fake-pi.php'`, the same binary `HeartbeatCest` uses. Create tasks with `POST /api/v1/companies/{companyId}/tasks`. Pause with `POST .../agents/{agentId}/pause`. Terminate with `POST .../agents/{agentId}/terminate`. Block with `PATCH .../tasks/{taskId}` and body `{"status":"blocked"}` and no `blockerIds`.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

final readonly class FloorCest
{
    public function aMissingSessionIsUnauthorized(ApiTester $I): void
    {
        $I->sendGET('/api/v1/companies/00000000-0000-4000-8000-000000000000/floor');
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $I->seeResponseContainsJson(['error_data' => ['code' => 'unauthenticated']]);
    }

    public function aMemberCannotSeeAnotherCompanysFloor(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/v1/session', ['email' => 'owner@holder.test', 'password' => 'secret-pass']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $owner = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $I->haveHttpHeader('Authorization', 'Bearer ' . $owner);
        $I->sendPOST('/api/v1/companies', ['name' => 'Hidden ' . uniqid(), 'mission' => 'private']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $hidden = $I->grabDataFromResponseByJsonPath('$.data.id')[0];

        $I->sendPOST('/api/v1/companies', ['name' => 'Visible ' . uniqid(), 'mission' => 'shared']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $visible = $I->grabDataFromResponseByJsonPath('$.data.id')[0];
        $email = 'floor-stranger-' . uniqid() . '@holder.test';
        $I->sendPOST('/api/v1/companies/' . $visible . '/invites', ['email' => $email, 'role' => 'member']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $invite = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $I->deleteHeader('Authorization');
        $I->sendPOST('/api/v1/invites/' . $invite . '/accept', ['name' => 'Stranger', 'password' => 'stranger-pass']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $stranger = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $I->haveHttpHeader('Authorization', 'Bearer ' . $stranger);

        $I->sendGET('/api/v1/companies/' . $hidden . '/floor');
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
    }

    public function anIdleAgentStandsAtTheDesk(ApiTester $I): void
    {
        $companyId = $this->company($I);
        $agentId = $this->agent($I, $companyId, 'Ada');

        $agent = $this->floorAgent($I, $companyId, $agentId);
        assertNotNull($agent);
        assertSame('desk', $agent['place']);
        assertSame('At their desk', $agent['step']);
        assertNull($agent['task']);
    }

    public function anAssignedTaskThatIsNotRunningStaysAtTheDesk(ApiTester $I): void
    {
        $companyId = $this->company($I);
        $agentId = $this->agent($I, $companyId, 'Ada');
        $I->sendPOST('/api/v1/companies/' . $companyId . '/tasks', [
            'title' => 'Write the health check',
            'description' => '',
            'assigneeAgentId' => $agentId,
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $taskId = $I->grabDataFromResponseByJsonPath('$.data.id')[0];

        $agent = $this->floorAgent($I, $companyId, $agentId);
        assertNotNull($agent);
        assertSame('desk', $agent['place']);
        assertSame('At their desk', $agent['step']);
        assertSame($taskId, $agent['task']['id'] ?? null);
        assertSame('Write the health check', $agent['task']['title'] ?? null);
    }

    public function aPausedAgentStaysInTheList(ApiTester $I): void
    {
        $companyId = $this->company($I);
        $agentId = $this->agent($I, $companyId, 'Ada');
        $I->sendPOST('/api/v1/companies/' . $companyId . '/agents/' . $agentId . '/pause');
        $I->seeResponseCodeIs(HttpCode::OK);

        $agent = $this->floorAgent($I, $companyId, $agentId);
        assertNotNull($agent);
        assertSame('desk', $agent['place']);
        assertSame('Paused', $agent['step']);
    }

    public function aTerminatedAgentIsAbsent(ApiTester $I): void
    {
        $companyId = $this->company($I);
        $kept = $this->agent($I, $companyId, 'Ada');
        $gone = $this->agent($I, $companyId, 'Bea');
        $I->sendPOST('/api/v1/companies/' . $companyId . '/agents/' . $gone . '/terminate');
        $I->seeResponseCodeIs(HttpCode::OK);

        assertNotNull($this->floorAgent($I, $companyId, $kept));
        assertNull($this->floorAgent($I, $companyId, $gone));
    }

    public function aBlockedTaskSendsTheAgentBack(ApiTester $I): void
    {
        $companyId = $this->company($I);
        $agentId = $this->agent($I, $companyId, 'Ada');
        $I->sendPOST('/api/v1/companies/' . $companyId . '/tasks', [
            'title' => 'Ask the owner',
            'description' => '',
            'assigneeAgentId' => $agentId,
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $taskId = $I->grabDataFromResponseByJsonPath('$.data.id')[0];
        $I->sendPATCH('/api/v1/companies/' . $companyId . '/tasks/' . $taskId, ['status' => 'blocked']);
        $I->seeResponseCodeIs(HttpCode::OK);

        $agent = $this->floorAgent($I, $companyId, $agentId);
        assertNotNull($agent);
        assertSame('desk', $agent['place']);
        assertSame('Waiting on you', $agent['step']);
        assertSame($taskId, $agent['task']['id'] ?? null);
    }

    private function company(ApiTester $I): string
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/v1/session', ['email' => 'owner@holder.test', 'password' => 'secret-pass']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $token = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $I->haveHttpHeader('Authorization', 'Bearer ' . $token);
        $I->sendPOST('/api/v1/companies', ['name' => 'Floor ' . uniqid(), 'mission' => 'Watch the work.']);
        $I->seeResponseCodeIs(HttpCode::OK);

        return $I->grabDataFromResponseByJsonPath('$.data.id')[0];
    }

    private function agent(ApiTester $I, string $companyId, string $name): string
    {
        $I->sendPOST('/api/v1/companies/' . $companyId . '/agents', [
            'name' => $name,
            'title' => 'Engineer',
            'jobDescription' => 'Builds the product.',
            'piBinary' => dirname(__DIR__) . '/fixtures/fake-pi.php',
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);

        return $I->grabDataFromResponseByJsonPath('$.data.id')[0];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function floorAgent(ApiTester $I, string $companyId, string $agentId): ?array
    {
        $I->sendGET('/api/v1/companies/' . $companyId . '/floor');
        $I->seeResponseCodeIs(HttpCode::OK);
        /** @var array{data: array{agents: list<array<string, mixed>>}} $body */
        $body = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        foreach ($body['data']['agents'] as $agent) {
            if ($agent['id'] === $agentId) {
                return $agent;
            }
        }

        return null;
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose run --rm --entrypoint php api vendor/bin/codecept run Api FloorCest`

Expected: FAIL. The floor route is not registered, so the requests are 404.

- [ ] **Step 3: Add the endpoint and the route**

Create `api/src/Api/FloorEndpoints.php`:

```php
<?php

declare(strict_types=1);

namespace App\Api;

use App\Api\Shared\ResponseFactory;
use App\Domain\ActorContext;
use App\Domain\Floor\FloorService;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class FloorEndpoints
{
    public function __construct(
        private ResponseFactory $responses,
        private FloorService $floor,
        private ActorContext $actors,
    ) {}

    public function show(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actors->requireUser();

        return $this->responses->success($this->floor->snapshot(
            $actor->id,
            (string) $route->getArgument('companyId'),
        ));
    }
}
```

In `api/config/common/routes.php`, import `App\Api\FloorEndpoints` next to the other endpoint imports. After the agent terminate route, add:

```php
Route::get('/companies/{companyId}/floor')->action([FloorEndpoints::class, 'show'])->name('floor/show'),
```

Yii autowires `FloorEndpoints` and `FloorService`. Do not add a DI definition.

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose run --rm --entrypoint php api vendor/bin/codecept run Api FloorCest`

Expected: PASS. 7 tests. Hiring uses `fake-pi.php`. If a hire returns a Pi error, the assertion on `seeResponseCodeIs(OK)` fails before the floor request; fix the binary path, not the floor rules.

- [ ] **Step 5: Commit**

```bash
git add api/src/Api/FloorEndpoints.php api/config/common/routes.php api/tests/Api/FloorCest.php
git commit -m "feat: serve the company floor snapshot"
```

---

### Task 5: Pure presentation helpers

**Files:**
- Create: `ui/src/floor/present.ts`
- Create: `ui/scripts/present.test.ts`
- Modify: `ui/src/types.ts`

**Interfaces:**
- Consumes: nothing
- Produces:
  - `cutLine(line: string): string` — longer than 22 code points keeps 21 and appends `…`
  - `initials(name: string): string`
  - `bubbleLines(agent: { step: string, task: { title: string } | null }): string[]`
  - `seat(index: number): { spot: number, extraY: number }`
  - `tintColor(id: string): number` — `tintColor('ada')` is `0xd197d8`
  - `FloorAgent` and `FloorSnapshot` exported from `ui/src/types.ts`

- [ ] **Step 1: Write the failing test**

Create `ui/scripts/present.test.ts`:

```typescript
import assert from 'node:assert/strict'
import test from 'node:test'
import { bubbleLines, cutLine, initials, seat, tintColor } from '../src/floor/present.ts'

test('cutLine keeps 21 code points and adds an ellipsis', () => {
  assert.equal(cutLine('short'), 'short')
  assert.equal(cutLine('a'.repeat(22)), 'a'.repeat(22))
  assert.equal(cutLine('a'.repeat(23)), `${'a'.repeat(21)}…`)
})

test('initials use the first and last word', () => {
  assert.equal(initials('Ada Lovelace'), 'AL')
  assert.equal(initials('Ada'), 'A')
  assert.equal(initials('  '), '?')
})

test('bubbleLines drops the second line when there is no task', () => {
  assert.deepEqual(bubbleLines({ step: 'At their desk', task: null }), ['At their desk'])
  assert.deepEqual(
    bubbleLines({ step: 'Editing src/Login.php', task: { title: 'Login page' } }),
    ['Login page', 'Editing src/Login.php'],
  )
})

test('seat wraps every 12 agents and steps down 64 pixels', () => {
  assert.deepEqual(seat(0), { spot: 1, extraY: 0 })
  assert.deepEqual(seat(11), { spot: 12, extraY: 0 })
  assert.deepEqual(seat(12), { spot: 1, extraY: 64 })
  assert.deepEqual(seat(13), { spot: 2, extraY: 64 })
})

test('tintColor is stable', () => {
  assert.equal(tintColor('ada'), 0xd197d8)
  assert.equal(tintColor('ada'), tintColor('ada'))
})
```

- [ ] **Step 2: Run the test to verify it fails**

Run from `ui/`: `node --experimental-strip-types --test scripts/present.test.ts`

Expected: FAIL. The module `../src/floor/present.ts` does not exist.

- [ ] **Step 3: Write the helpers and the shared types**

Add to `ui/src/types.ts`:

```typescript
export type FloorTask = { id: string; title: string; status: string }
export type FloorAgent = {
  id: string
  name: string
  title: string
  status: string
  place: 'desk' | 'work'
  step: string
  task: FloorTask | null
}
export type FloorSnapshot = { agents: FloorAgent[] }
```

Create `ui/src/floor/present.ts`:

```typescript
export function cutLine(line: string): string {
  const chars = Array.from(line)
  if (chars.length <= 22) return line
  return `${chars.slice(0, 21).join('')}…`
}

export function initials(name: string): string {
  const words = name.trim().split(/\s+/).filter((word) => word !== '')
  const first = words[0] ? Array.from(words[0])[0] : ''
  if (!first) return '?'
  if (words.length === 1) return first.toUpperCase()
  const last = Array.from(words[words.length - 1] ?? '')[0] ?? ''
  return `${first}${last}`.toUpperCase()
}

export function bubbleLines(agent: { step: string; task: { title: string } | null }): string[] {
  if (!agent.task) return [cutLine(agent.step)]
  return [cutLine(agent.task.title), cutLine(agent.step)]
}

export function seat(index: number): { spot: number; extraY: number } {
  return { spot: (index % 12) + 1, extraY: Math.floor(index / 12) * 64 }
}

export function tintColor(id: string): number {
  let hash = 0
  for (const char of id) hash = (Math.imul(hash, 31) + (char.codePointAt(0) ?? 0)) >>> 0
  return hsl(hash % 360, 0.45, 0.72)
}

function hsl(hue: number, saturation: number, lightness: number): number {
  const chroma = (1 - Math.abs(2 * lightness - 1)) * saturation
  const channel = hue / 60
  const x = chroma * (1 - Math.abs((channel % 2) - 1))
  const match = lightness - chroma / 2
  let red = 0
  let green = 0
  let blue = 0
  if (channel < 1) {
    red = chroma
    green = x
  } else if (channel < 2) {
    red = x
    green = chroma
  } else if (channel < 3) {
    green = chroma
    blue = x
  } else if (channel < 4) {
    green = x
    blue = chroma
  } else if (channel < 5) {
    red = x
    blue = chroma
  } else {
    red = chroma
    blue = x
  }
  const byte = (value: number) => Math.round((value + match) * 255)
  return (byte(red) << 16) | (byte(green) << 8) | byte(blue)
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run from `ui/`: `node --experimental-strip-types --test scripts/present.test.ts`

Expected: PASS. 5 tests.

- [ ] **Step 5: Commit**

```bash
git add ui/src/floor/present.ts ui/scripts/present.test.ts ui/src/types.ts
git commit -m "feat: add floor bubble, seat, and tint helpers"
```

---

### Task 6: Draw the office and the clerk

**Files:**
- Create: `ui/src/assets/floor/office.png`
- Create: `ui/src/assets/floor/office.json`
- Create: `ui/src/assets/floor/clerk.png`
- Create: `ui/src/assets/floor/clerk.json`

**Interfaces:**
- Consumes: nothing
- Produces: a Tiled map named `office`, 40 by 24 tiles of 32 pixels. Tileset image is four 32×32 tiles in one 128×32 PNG: floor, wall, desk, table, gids 1–4. Object layer `spots` has point objects `desk-1` … `desk-12` and `work-1` … `work-12`. Desk `n` is tile x `1 + (n - 1) * 3`, tile y `4`. Work `n` uses the same x and tile y `18`. The point is the feet: pixel x `tileX * 32 + 16`, pixel y `(tileY + 1) * 32`. The clerk atlas is 160×128: four rows (`down`, `left`, `right`, `up`) and five columns (idle, then `walk.000` through `walk.003`). Frame names are `down`, `down-walk.000`, and the same pattern for the other directions. Figures are light gray (`#f4f1ea`) with a dark outline so a tint reads.

- [ ] **Step 1: Write a one-shot drawer**

Write this program to `/tmp/draw-floor.mjs`. It uses `node:zlib` and writes the four files. Do not commit the script.

```javascript
import { deflateSync } from 'node:zlib'
import { mkdirSync, writeFileSync } from 'node:fs'

const table = new Uint32Array(256)
for (let n = 0; n < 256; n++) {
  let c = n
  for (let k = 0; k < 8; k++) c = (c & 1) ? (0xedb88320 ^ (c >>> 1)) : (c >>> 1)
  table[n] = c >>> 0
}

function crc32(buf) {
  let c = 0xffffffff
  for (const byte of buf) c = table[(c ^ byte) & 0xff] ^ (c >>> 8)
  return (c ^ 0xffffffff) >>> 0
}

function chunk(type, data) {
  const length = Buffer.alloc(4)
  length.writeUInt32BE(data.length)
  const body = Buffer.concat([Buffer.from(type), data])
  const crc = Buffer.alloc(4)
  crc.writeUInt32BE(crc32(body))
  return Buffer.concat([length, body, crc])
}

function png(width, height, rgba) {
  const raw = Buffer.alloc((width * 4 + 1) * height)
  for (let y = 0; y < height; y++) {
    raw[y * (width * 4 + 1)] = 0
    rgba.copy(raw, y * (width * 4 + 1) + 1, y * width * 4, (y + 1) * width * 4)
  }
  const ihdr = Buffer.alloc(13)
  ihdr.writeUInt32BE(width, 0)
  ihdr.writeUInt32BE(height, 4)
  ihdr[8] = 8
  ihdr[9] = 6
  const signature = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10])
  return Buffer.concat([signature, chunk('IHDR', ihdr), chunk('IDAT', deflateSync(raw)), chunk('IEND', Buffer.alloc(0))])
}

function set(rgba, width, x, y, color) {
  if (x < 0 || y < 0) return
  const i = (y * width + x) * 4
  if (i < 0 || i + 3 >= rgba.length) return
  rgba[i] = color[0]
  rgba[i + 1] = color[1]
  rgba[i + 2] = color[2]
  rgba[i + 3] = 255
}

function fill(rgba, width, x, y, w, h, color) {
  for (let yy = 0; yy < h; yy++) for (let xx = 0; xx < w; xx++) set(rgba, width, x + xx, y + yy, color)
}

function border(rgba, width, x, y, w, h, color) {
  fill(rgba, width, x, y, w, 1, color)
  fill(rgba, width, x, y + h - 1, w, 1, color)
  fill(rgba, width, x, y, 1, h, color)
  fill(rgba, width, x + w - 1, y, 1, h, color)
}

const floor = [196, 165, 116]
const wall = [92, 70, 56]
const wood = [122, 78, 46]
const screen = [214, 226, 232]
const paper = [244, 241, 234]
const ink = [42, 38, 34]

const tiles = Buffer.alloc(128 * 32 * 4)
for (let i = 0; i < 4; i++) fill(tiles, 128, i * 32, 0, 32, 32, floor)
fill(tiles, 128, 32, 0, 32, 32, wall)
fill(tiles, 128, 32, 0, 32, 4, [214, 196, 170])
fill(tiles, 128, 64 + 4, 18, 24, 8, wood)
fill(tiles, 128, 64 + 10, 8, 12, 10, screen)
fill(tiles, 128, 96 + 2, 16, 28, 10, wood)
fill(tiles, 128, 96 + 6, 12, 8, 6, paper)

const sheet = Buffer.alloc(160 * 128 * 4, 0)
for (let row = 0; row < 4; row++) {
  for (let col = 0; col < 5; col++) {
    const ox = col * 32
    const oy = row * 32
    const bob = col === 0 ? 0 : (col % 2 === 0 ? -1 : 1)
    fill(sheet, 160, ox + 11, oy + 4, 10, 10, paper)
    border(sheet, 160, ox + 11, oy + 4, 10, 10, ink)
    fill(sheet, 160, ox + 10, oy + 15, 12, 8, paper)
    border(sheet, 160, ox + 10, oy + 15, 12, 8, ink)
    fill(sheet, 160, ox + 12, oy + 24, 3, 6, ink)
    fill(sheet, 160, ox + 17 + bob, oy + 24, 3, 6, ink)
  }
}

const width = 40
const height = 24
const data = new Array(width * height).fill(1)
function paint(tx, ty, gid) {
  if (tx < 0 || ty < 0 || tx >= width || ty >= height) return
  data[ty * width + tx] = gid
}
for (let x = 0; x < width; x++) {
  paint(x, 0, 2)
  paint(x, 1, 2)
}
const objects = []
for (let n = 1; n <= 12; n++) {
  const tileX = 1 + (n - 1) * 3
  paint(tileX, 3, 3)
  paint(tileX, 17, 4)
  for (const [name, tileY] of [[`desk-${n}`, 4], [`work-${n}`, 18]]) {
    objects.push({
      name,
      type: '',
      x: tileX * 32 + 16,
      y: (tileY + 1) * 32,
      width: 0,
      height: 0,
      point: true,
      visible: true,
    })
  }
}

const frames = {}
for (const [row, dir] of ['down', 'left', 'right', 'up'].entries()) {
  const names = [dir, `${dir}-walk.000`, `${dir}-walk.001`, `${dir}-walk.002`, `${dir}-walk.003`]
  names.forEach((name, col) => {
    frames[name] = {
      frame: { x: col * 32, y: row * 32, w: 32, h: 32 },
      rotated: false,
      trimmed: false,
      spriteSourceSize: { x: 0, y: 0, w: 32, h: 32 },
      sourceSize: { w: 32, h: 32 },
    }
  })
}

const map = {
  compressionlevel: -1,
  width,
  height,
  tilewidth: 32,
  tileheight: 32,
  infinite: false,
  orientation: 'orthogonal',
  renderorder: 'right-down',
  type: 'map',
  nextlayerid: 3,
  nextobjectid: objects.length + 1,
  tilesets: [{
    columns: 4,
    firstgid: 1,
    image: 'office.png',
    imageheight: 32,
    imagewidth: 128,
    margin: 0,
    name: 'office',
    spacing: 0,
    tilecount: 4,
    tilewidth: 32,
    tileheight: 32,
  }],
  layers: [
    { id: 1, name: 'ground', type: 'tilelayer', visible: true, opacity: 1, x: 0, y: 0, width, height, data },
    { id: 2, name: 'spots', type: 'objectgroup', visible: true, opacity: 1, x: 0, y: 0, objects },
  ],
}

if (objects.filter((object) => object.name.startsWith('desk-')).length !== 12) {
  throw new Error('expected 12 desks')
}
mkdirSync('ui/src/assets/floor', { recursive: true })
writeFileSync('ui/src/assets/floor/office.png', png(128, 32, tiles))
writeFileSync('ui/src/assets/floor/clerk.png', png(160, 128, sheet))
writeFileSync('ui/src/assets/floor/office.json', JSON.stringify(map))
writeFileSync('ui/src/assets/floor/clerk.json', JSON.stringify({
  frames,
  meta: { app: 'holder', image: 'clerk.png', size: { w: 160, h: 128 }, scale: '1' },
}))
```

- [ ] **Step 2: Run the drawer**

From the repo root: `node /tmp/draw-floor.mjs`

Expected: the four files exist. `office.json` has 24 spot objects. Delete `/tmp/draw-floor.mjs` after the files look right.

- [ ] **Step 3: Commit**

```bash
git add ui/src/assets/floor/office.png ui/src/assets/floor/office.json ui/src/assets/floor/clerk.png ui/src/assets/floor/clerk.json
git commit -m "feat: add the original office tiles and clerk"
```

---

### Task 7: Show the office

**Files:**
- Create: `ui/src/floor/OfficeScene.ts`
- Create: `ui/src/views/FloorView.vue`
- Modify: `ui/src/router.ts`
- Modify: `ui/src/App.vue`
- Modify: `ui/package.json`
- Modify: `ui/package-lock.json`

**Interfaces:**
- Consumes: `FloorSnapshot` and `FloorAgent` from `ui/src/types.ts`. `bubbleLines`, `initials`, `seat`, and `tintColor` from `ui/src/floor/present.ts`. `api` and `failureMessage` from `ui/src/api.ts`. `GET /api/v1/companies/{id}/floor`. Spot names and feet points from Task 6.
- Produces: route `/floor`, nav item Floor after Dashboard, and `OfficeScene.apply(snapshot: FloorSnapshot): void`.

- [ ] **Step 1: Install Phaser**

From `ui/`: `npm install phaser@3`

Expected: `phaser` is a dependency in `ui/package.json` and the lockfile updates. The resolved version is 3.x.

- [ ] **Step 2: Write the scene**

Create `ui/src/floor/OfficeScene.ts`:

```typescript
import Phaser from 'phaser'
import clerkAtlasUrl from '../assets/floor/clerk.json?url'
import clerkUrl from '../assets/floor/clerk.png'
import mapUrl from '../assets/floor/office.json?url'
import tilesUrl from '../assets/floor/office.png'
import type { FloorAgent, FloorSnapshot } from '../types'
import { bubbleLines, initials, seat, tintColor } from './present'

type Handlers = {
  open: (agent: FloorAgent) => void
  hover: (agent: FloorAgent | null) => void
}

let handlers: Handlers = {
  open: () => {},
  hover: () => {},
}

export function setFloorHandlers(next: Handlers): void {
  handlers = next
}

type Actor = {
  agent: FloorAgent
  sprite: Phaser.GameObjects.Sprite
  label: Phaser.GameObjects.Text
  bubble: Phaser.GameObjects.Text
  plate: Phaser.GameObjects.Graphics
  targetX: number
  targetY: number
}

function isSnapshot(value: unknown): value is FloorSnapshot {
  return typeof value === 'object' && value !== null && 'agents' in value && Array.isArray(value.agents)
}

export class OfficeScene extends Phaser.Scene {
  private actors = new Map<string, Actor>()
  private spots = new Map<string, { x: number; y: number }>()
  private cursors: Phaser.Types.Input.Keyboard.CursorKeys | null = null
  private drag: { x: number; y: number; scrollX: number; scrollY: number } | null = null
  private moved = false
  private mapWidth = 0
  private mapHeight = 0
  private ready = false

  constructor() {
    super('office')
  }

  preload(): void {
    this.load.image('office-tiles', tilesUrl)
    this.load.tilemapTiledJSON('office-map', mapUrl)
    this.load.atlas('clerk', clerkUrl, clerkAtlasUrl)
  }

  create(): void {
    const map = this.make.tilemap({ key: 'office-map' })
    const tiles = map.addTilesetImage('office', 'office-tiles')
    if (!tiles) throw new Error('Office tileset did not load.')
    map.createLayer('ground', tiles, 0, 0)
    for (const object of map.getObjectLayer('spots')?.objects ?? []) {
      if (object.name) this.spots.set(object.name, { x: object.x ?? 0, y: object.y ?? 0 })
    }
    this.mapWidth = map.widthInPixels
    this.mapHeight = map.heightInPixels
    const camera = this.cameras.main
    camera.setZoom(1)
    camera.centerOn(this.mapWidth / 2, this.mapHeight / 2)
    camera.setBounds(0, 0, this.mapWidth, this.mapHeight)
    this.cursors = this.input.keyboard?.createCursorKeys() ?? null
    this.input.on('pointerdown', (pointer: Phaser.Input.Pointer) => {
      this.drag = { x: pointer.x, y: pointer.y, scrollX: camera.scrollX, scrollY: camera.scrollY }
      this.moved = false
    })
    this.input.on('pointermove', (pointer: Phaser.Input.Pointer) => {
      if (!this.drag || !pointer.isDown) return
      const dx = pointer.x - this.drag.x
      const dy = pointer.y - this.drag.y
      if (Math.hypot(dx, dy) > 4) this.moved = true
      camera.scrollX = this.drag.scrollX - dx / camera.zoom
      camera.scrollY = this.drag.scrollY - dy / camera.zoom
    })
    this.input.on('wheel', (...args: unknown[]) => {
      const dy = typeof args[3] === 'number' ? args[3] : 0
      camera.setZoom(Phaser.Math.Clamp(camera.zoom - dy * 0.001, 1, 2))
    })
    for (const dir of ['down', 'left', 'right', 'up']) {
      this.anims.create({
        key: `${dir}-walk`,
        frames: this.anims.generateFrameNames('clerk', {
          prefix: `${dir}-walk.`,
          start: 0,
          end: 3,
          zeroPad: 3,
        }),
        frameRate: 8,
        repeat: -1,
      })
    }
    this.ready = true
    const initial = this.game.registry.get('snapshot')
    if (isSnapshot(initial)) this.apply(initial)
  }

  apply(snapshot: FloorSnapshot): void {
    if (!this.ready) {
      this.game.registry.set('snapshot', snapshot)
      return
    }
    const keep = new Set<string>()
    snapshot.agents.forEach((agent, index) => {
      keep.add(agent.id)
      const point = this.point(agent.place, index)
      const actor = this.actors.get(agent.id)
      if (!actor) {
        this.actors.set(agent.id, this.spawn(agent, point))
        return
      }
      actor.agent = agent
      actor.targetX = point.x
      actor.targetY = point.y
      actor.bubble.setText(bubbleLines(agent).join('\n'))
    })
    for (const [id, actor] of this.actors) {
      if (keep.has(id)) continue
      actor.sprite.destroy()
      actor.label.destroy()
      actor.bubble.destroy()
      actor.plate.destroy()
      this.actors.delete(id)
    }
    const rows = snapshot.agents.length === 0 ? 0 : Math.floor((snapshot.agents.length - 1) / 12)
    this.cameras.main.setBounds(0, 0, this.mapWidth, this.mapHeight + rows * 64)
  }

  update(_time: number, delta: number): void {
    const camera = this.cameras.main
    if (this.cursors) {
      const pan = (400 * delta) / 1000
      if (this.cursors.left.isDown) camera.scrollX -= pan
      if (this.cursors.right.isDown) camera.scrollX += pan
      if (this.cursors.up.isDown) camera.scrollY -= pan
      if (this.cursors.down.isDown) camera.scrollY += pan
    }
    for (const actor of this.actors.values()) {
      const dx = actor.targetX - actor.sprite.x
      const dy = actor.targetY - actor.sprite.y
      const distance = Math.hypot(dx, dy)
      if (distance < 1) {
        actor.sprite.setPosition(actor.targetX, actor.targetY)
        actor.sprite.anims.stop()
        actor.sprite.setFrame('down')
      } else {
        const step = Math.min(distance, (120 * delta) / 1000)
        actor.sprite.x += (dx / distance) * step
        actor.sprite.y += (dy / distance) * step
        const dir = Math.abs(dx) > Math.abs(dy) ? (dx > 0 ? 'right' : 'left') : (dy > 0 ? 'down' : 'up')
        actor.sprite.anims.play(`${dir}-walk`, true)
      }
      actor.sprite.setDepth(actor.sprite.y)
      actor.label.setPosition(actor.sprite.x, actor.sprite.y + 2)
      actor.bubble.setPosition(actor.sprite.x, actor.sprite.y - 36)
      actor.plate.clear()
      actor.plate.fillStyle(0xfff7e8, 1)
      actor.plate.fillRoundedRect(
        actor.sprite.x - actor.bubble.width / 2 - 4,
        actor.sprite.y - 36 - actor.bubble.height - 2,
        actor.bubble.width + 8,
        actor.bubble.height + 6,
        4,
      )
      actor.plate.setDepth(actor.sprite.y + 1)
      actor.bubble.setDepth(actor.sprite.y + 2)
      actor.label.setDepth(actor.sprite.y + 2)
    }
  }

  private point(place: FloorAgent['place'], index: number): { x: number; y: number } {
    const { spot, extraY } = seat(index)
    const found = this.spots.get(`${place}-${spot}`)
    if (!found) throw new Error(`Missing spot ${place}-${spot}`)
    return { x: found.x, y: found.y + extraY }
  }

  private spawn(agent: FloorAgent, point: { x: number; y: number }): Actor {
    const sprite = this.add.sprite(point.x, point.y, 'clerk', 'down').setOrigin(0.5, 1).setTint(tintColor(agent.id))
    sprite.setInteractive({ useHandCursor: true })
    const label = this.add.text(point.x, point.y + 2, initials(agent.name), {
      fontFamily: 'monospace',
      fontSize: '10px',
      color: '#f5f5f4',
    }).setOrigin(0.5, 0)
    const bubble = this.add.text(point.x, point.y - 36, bubbleLines(agent).join('\n'), {
      fontFamily: 'monospace',
      fontSize: '12px',
      color: '#1c1915',
      align: 'center',
    }).setOrigin(0.5, 1)
    const actor: Actor = {
      agent,
      sprite,
      label,
      bubble,
      plate: this.add.graphics(),
      targetX: point.x,
      targetY: point.y,
    }
    sprite.on('pointerup', () => {
      if (this.moved) return
      handlers.open(actor.agent)
    })
    sprite.on('pointerover', () => handlers.hover(actor.agent))
    sprite.on('pointerout', () => handlers.hover(null))
    return actor
  }
}
```

- [ ] **Step 3: Write the page, the route, and the nav item**

Create `ui/src/views/FloorView.vue`:

```vue
<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import Phaser from 'phaser'
import { api, failureMessage } from '../api'
import { OfficeScene, setFloorHandlers } from '../floor/OfficeScene'
import { useBoard } from '../stores/board'
import type { FloorAgent, FloorSnapshot } from '../types'

const router = useRouter()
const { company } = storeToRefs(useBoard())
const host = ref<HTMLDivElement | null>(null)
const agents = ref<FloorAgent[]>([])
const hovered = ref<FloorAgent | null>(null)
const seen = ref(false)
const error = ref('')
const stalled = ref(false)

let game: Phaser.Game | null = null
let timer = 0
let abort: AbortController | null = null
let generation = 0

function linkFor(agent: FloorAgent): string {
  return agent.task ? `/issues/${agent.task.id}` : `/agents/${agent.id}`
}

function publish(snapshot: FloorSnapshot) {
  game?.registry.set('snapshot', snapshot)
  const scene = game?.scene.getScene('office')
  if (scene instanceof OfficeScene) scene.apply(snapshot)
}

async function poll() {
  const current = company.value
  if (!current) return
  const mine = ++generation
  abort?.abort()
  const controller = new AbortController()
  abort = controller
  try {
    const data = await api<FloorSnapshot>(`/api/v1/companies/${current.id}/floor`, { signal: controller.signal })
    if (mine !== generation) return
    seen.value = true
    error.value = ''
    stalled.value = false
    agents.value = data.agents
    publish(data)
  } catch (reason) {
    if (controller.signal.aborted || mine !== generation) return
    if (!seen.value) error.value = failureMessage(reason, 'The office could not be loaded.')
    else stalled.value = true
  }
}

onMounted(() => {
  setFloorHandlers({
    open: (agent) => {
      void router.push(linkFor(agent))
    },
    hover: (agent) => {
      hovered.value = agent
    },
  })
  if (host.value) {
    game = new Phaser.Game({
      type: Phaser.AUTO,
      parent: host.value,
      width: host.value.clientWidth || 640,
      height: 448,
      pixelArt: true,
      backgroundColor: '#1c1915',
      banner: false,
      scale: { mode: Phaser.Scale.RESIZE },
      scene: [OfficeScene],
    })
  }
  void poll()
  timer = window.setInterval(() => {
    void poll()
  }, 2000)
})

onUnmounted(() => {
  window.clearInterval(timer)
  abort?.abort()
  game?.destroy(true)
  game = null
})

watch(() => company.value?.id, () => {
  seen.value = false
  error.value = ''
  stalled.value = false
  agents.value = []
  hovered.value = null
  publish({ agents: [] })
  void poll()
})
</script>

<template>
  <div class="space-y-4">
    <p v-if="!seen && !error" class="text-sm text-muted-foreground" role="status">Loading the office…</p>
    <p v-if="error" class="text-sm text-destructive" role="alert">{{ error }}</p>
    <p v-if="stalled" class="text-sm text-muted-foreground" role="status">Live updates paused</p>
    <div
      v-if="seen && agents.length === 0"
      class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3"
    >
      <p class="text-sm">You have no agents.</p>
      <RouterLink to="/agents/new" class="text-sm font-medium underline underline-offset-2">Create one here</RouterLink>
    </div>
    <div class="relative">
      <div ref="host" class="h-[28rem] w-full overflow-hidden rounded-xl border border-border" />
      <p v-if="hovered" class="absolute left-2 top-2 z-10 max-w-sm rounded-lg border border-border bg-card px-3 py-2 text-sm shadow">
        <span class="block font-medium">{{ hovered.name }}</span>
        <span class="block text-muted-foreground">{{ hovered.task?.title ?? 'No task' }}</span>
        <span class="block">{{ hovered.step }}</span>
      </p>
    </div>
    <ul v-if="agents.length > 0" class="divide-y divide-border overflow-hidden rounded-xl border border-border">
      <li v-for="agent in agents" :key="agent.id">
        <RouterLink
          :to="linkFor(agent)"
          class="flex items-center justify-between gap-3 px-3 py-2 text-sm no-underline hover:bg-accent/50"
        >
          <span>{{ agent.name }}</span>
          <span class="min-w-0 truncate text-muted-foreground">{{ agent.step }}</span>
        </RouterLink>
      </li>
    </ul>
  </div>
</template>
```

In `ui/src/router.ts`, add the import and the route after the dashboard route:

```typescript
import FloorView from './views/FloorView.vue'
```

```typescript
    { path: '/dashboard', component: DashboardView, meta: { title: 'Dashboard' } },
    { path: '/floor', component: FloorView, meta: { title: 'Floor' } },
```

In `ui/src/App.vue`, add `Map` to the `@lucide/vue` import. In `primary`, put Floor immediately after Dashboard:

```typescript
  { to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
  { to: '/floor', label: 'Floor', icon: Map },
  { to: '/inbox', label: 'Inbox', icon: Inbox },
```

The mobile bar is currently `primary[1]` and `primary[2]`. After this insert those indexes are Dashboard and Floor, so Inbox would fall off. Add this computed next to `primary`:

```typescript
const mobile = computed(() =>
  ['/dashboard', '/inbox', '/issues', '/agents'].flatMap((to) => {
    const item = [...primary, ...work, ...organization].find((entry) => entry.to === to)
    return item ? [item] : []
  }),
)
```

Replace the mobile `v-for` list with `mobile`. The bar stays Dashboard, Inbox, Tasks, and Agents.

- [ ] **Step 4: Typecheck**

Run from `ui/`: `./node_modules/.bin/vue-tsc -b`

Expected: exit 0. If the default Phaser import fails, switch `OfficeScene.ts` to `import * as Phaser from 'phaser'` and typecheck again. Do not leave unused locals; `noUnusedLocals` is on.

- [ ] **Step 5: Check the page in the browser**

Use the running UI. Sign in and open `/floor`.

- The office tiles render. With no agents, the banner says `You have no agents.` and `Create one here` goes to `/agents/new`. The canvas has no sprites.
- With an idle agent, the sprite stands on a desk. The list shows the name and `At their desk`. The link opens `/agents/{id}`.
- With an agent whose snapshot `place` is `work`, the sprite walks from the desk to the work row and then idles facing down. The bubble shows the task title and the step. Clicking the sprite opens `/issues/{id}`. A drag that moves more than a few pixels does not navigate.
- Arrow keys pan. The wheel zooms and stops at the closest and farthest limits.
- After the office has loaded, set the browser offline. The page shows `Live updates paused` and the sprite stays where it is. Back online, the banner clears on a later poll.
- At a 390px wide viewport, the list is on the page and its link still opens the task. The mobile bar still contains Inbox.

If a check fails, fix it and run that check again before committing.

- [ ] **Step 6: Commit**

```bash
git add ui/package.json ui/package-lock.json ui/src/floor/OfficeScene.ts ui/src/views/FloorView.vue ui/src/router.ts ui/src/App.vue
git commit -m "feat: show agents working on the office floor"
```

---

## Self-review

Spec coverage: step text is Task 1. Place rules are Task 2. Task choice, event query, `Starting`, and a bad payload are Task 3. HTTP auth and the desk cases are Task 4. Bubble length, initials, tint, and seats are Task 5. The map and the clerk are Task 6. Polling, walking, clicks, hover, the list, failures, the route, and the browser pass are Task 7. Replay, map editing, unique portraits, sound, and a push stream have no tasks.

The bad-payload test inserts a JSON string, not invalid jsonb, because the column cannot store invalid JSON. `StepLine` still receives a non-array payload.
