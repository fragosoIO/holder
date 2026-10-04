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
