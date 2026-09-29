<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

final class M20260928140000AddAgentQuestions implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute('ALTER TABLE tasks ADD COLUMN agent_questions jsonb');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute('ALTER TABLE tasks DROP COLUMN IF EXISTS agent_questions');
    }
}
