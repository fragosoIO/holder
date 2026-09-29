<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\CompanyWorkspace;
use App\Domain\HolderConfig;
use App\Domain\HolderException;
use App\Domain\Ids;
use Codeception\Test\Unit;

final class CompanyWorkspaceTest extends Unit
{
    public function testEnsureCreatesADirectoryNamedForTheCompany(): void
    {
        $root = sys_get_temp_dir() . '/holder-ws-' . bin2hex(random_bytes(4));
        $workspaces = $this->workspaces($root);
        $id = Ids::uuid();

        $path = $workspaces->ensure($id);

        $this->assertSame($root . '/workspaces/' . $id, $path);
        $this->assertDirectoryExists($path);
        $this->assertSame($path, $workspaces->ensure($id));
    }

    public function testEnsureRejectsACompanyIdThatIsNotAUuid(): void
    {
        $workspaces = $this->workspaces(sys_get_temp_dir() . '/holder-ws-unused');

        $this->expectException(HolderException::class);
        $workspaces->ensure('../etc');
    }

    private function workspaces(string $root): CompanyWorkspace
    {
        return new CompanyWorkspace(new HolderConfig(
            mode: 'local',
            secretsKey: 'test',
            apiUrl: 'http://127.0.0.1',
            dataDir: $root,
            binPath: '/bin/holder',
            runTimeoutSeconds: 1,
            runLimitSeconds: 3600,
        ));
    }
}
