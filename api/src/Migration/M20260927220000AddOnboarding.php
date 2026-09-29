<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

final class M20260927220000AddOnboarding implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        foreach ($this->upStatements() as $sql) {
            $b->execute($sql);
        }
    }

    public function down(MigrationBuilder $b): void
    {
        foreach ($this->downStatements() as $sql) {
            $b->execute($sql);
        }
    }

    /**
     * @return list<string>
     */
    private function upStatements(): array
    {
        return [
            'ALTER TABLE agents ADD COLUMN onboarding_first integer NOT NULL DEFAULT 0',
            'ALTER TABLE tasks ADD COLUMN onboarding_first integer NOT NULL DEFAULT 0',
            'ALTER TABLE tasks ADD COLUMN opening_question jsonb',
            'CREATE UNIQUE INDEX agents_one_onboarding_first ON agents (company_id) WHERE onboarding_first = 1',
            'CREATE UNIQUE INDEX tasks_one_onboarding_first ON tasks (company_id) WHERE onboarding_first = 1',
        ];
    }

    /**
     * @return list<string>
     */
    private function downStatements(): array
    {
        return [
            'DROP INDEX IF EXISTS tasks_one_onboarding_first',
            'DROP INDEX IF EXISTS agents_one_onboarding_first',
            'ALTER TABLE tasks DROP COLUMN IF EXISTS opening_question',
            'ALTER TABLE tasks DROP COLUMN IF EXISTS onboarding_first',
            'ALTER TABLE agents DROP COLUMN IF EXISTS onboarding_first',
        ];
    }
}
