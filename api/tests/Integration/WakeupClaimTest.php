<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Heartbeat\WakeupSql;
use App\Domain\Ids;
use App\Shared\Env;
use Codeception\Test\Unit;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Pgsql\Connection;
use Yiisoft\Db\Pgsql\Driver;
use Yiisoft\Db\Pgsql\Dsn;

final class WakeupClaimCest extends Unit
{
    public function testASecondConnectionCannotClaimALockedWakeup(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql is required.');
        }

        $first = $this->connection();
        $first->createCommand("DELETE FROM wakeups WHERE status = 'pending'")->execute();

        $companyId = Ids::uuid();
        $agentId = Ids::uuid();
        $wakeupId = Ids::uuid();
        $first = $this->connection();
        $first->createCommand(
            'INSERT INTO companies (id, name, mission) VALUES (:id, :name, :mission)',
            [':id' => $companyId, ':name' => 'Claim Co', ':mission' => ''],
        )->execute();
        $first->createCommand(
            'INSERT INTO agents (
                id, company_id, name, title, job_description, status, workspace_path
            ) VALUES (
                :id, :company_id, :name, :title, :job_description, :status, :workspace_path
            )',
            [
                ':id' => $agentId,
                ':company_id' => $companyId,
                ':name' => 'Claimer',
                ':title' => 'Engineer',
                ':job_description' => '',
                ':status' => 'active',
                ':workspace_path' => sys_get_temp_dir(),
            ],
        )->execute();
        $first->createCommand(
            'INSERT INTO wakeups (id, company_id, agent_id, status, reason) VALUES (:id, :company_id, :agent_id, :status, :reason)',
            [
                ':id' => $wakeupId,
                ':company_id' => $companyId,
                ':agent_id' => $agentId,
                ':status' => 'pending',
                ':reason' => 'test',
            ],
        )->execute();

        $held = $this->connection();
        $other = $this->connection();
        $heldTx = $held->beginTransaction();
        $claimed = $held->createCommand(WakeupSql::CLAIM)->queryOne();
        $otherTx = $other->beginTransaction();
        $blocked = $other->createCommand(WakeupSql::CLAIM)->queryOne();
        $heldTx->rollBack();
        $otherTx->rollBack();

        $this->assertNotNull($claimed);
        $this->assertSame($wakeupId, $claimed['id'] ?? null);
        $this->assertNull($blocked);

        $first->createCommand('DELETE FROM companies WHERE id = :id', [':id' => $companyId])->execute();
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
