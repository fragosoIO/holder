<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

final class M20260929120000AddGithub implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute('ALTER TABLE companies ADD COLUMN github_token text');
        $b->execute("ALTER TABLE projects ADD COLUMN repo_url text NOT NULL DEFAULT ''");
        $b->execute("ALTER TABLE projects ADD COLUMN default_branch text NOT NULL DEFAULT ''");
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute('ALTER TABLE projects DROP COLUMN IF EXISTS default_branch');
        $b->execute('ALTER TABLE projects DROP COLUMN IF EXISTS repo_url');
        $b->execute('ALTER TABLE companies DROP COLUMN IF EXISTS github_token');
    }
}
