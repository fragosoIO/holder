<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringEndsWith;
use function PHPUnit\Framework\assertStringNotContainsString;
use function PHPUnit\Framework\assertTrue;

final readonly class GithubHeartbeatCest
{
    private const PI = '/repo/api/tests/fixtures/record-pi';

    private const GIT_LOG = '/tmp/holder-fake-git.log';

    private const CWD_FILE = '/tmp/holder-pi-cwd';

    private const ENV_FILE = '/tmp/holder-pi-env';

    public function aRepositoryTaskRunsInItsWorktreeAndAFolderTaskDoesNot(ApiTester $I): void
    {
        [$companyId, $agentId] = $this->ownerAgent($I);
        $project = $this->repository($I, $companyId);
        $taskId = $this->task($I, $companyId, $agentId, $project['id']);
        $worktree = str_replace('/repos/' . $project['id'], '/worktrees/' . $taskId, $project['workspace']);

        file_put_contents(self::GIT_LOG, '');
        putenv('GH_TOKEN=leaked-from-worker');
        try {
            $this->workOnce();

            $run = $this->run($I, $companyId, $taskId);
            assertStringContainsString('holder/' . $taskId, $run['prompt']);
            assertStringContainsString('gh pr create', $run['prompt']);
            assertStringNotContainsString('ghp_example', $run['prompt']);
            assertSame($worktree, $this->cwd());
            $env = $this->env();
            assertStringContainsString('GH_TOKEN=ghp_example', $env);
            assertStringNotContainsString('leaked-from-worker', $env);

            $this->workOnce();
            assertSame(1, substr_count((string) file_get_contents(self::GIT_LOG), 'worktree add'));

            $folderId = $this->task($I, $companyId, $agentId, null);
            $this->workOnce();
            assertStringEndsWith('/workspaces/' . $companyId, $this->cwd());
            $folderEnv = $this->env();
            assertStringNotContainsString('GH_TOKEN', $folderEnv);
            assertStringNotContainsString('leaked-from-worker', $folderEnv);
            $folder = $this->run($I, $companyId, $folderId);
            assertStringNotContainsString('gh pr create', $folder['prompt']);
            assertStringNotContainsString('ghp_example', $folder['prompt']);
        } finally {
            putenv('GH_TOKEN');
        }
    }

    public function aMissingGhFailsTheRunWithoutStartingPi(ApiTester $I): void
    {
        [$companyId, $agentId] = $this->ownerAgent($I);
        $project = $this->repository($I, $companyId);
        $taskId = $this->task($I, $companyId, $agentId, $project['id']);
        $marker = "not-rewritten-by-this-run\n";
        file_put_contents(self::CWD_FILE, $marker);

        putenv('HOLDER_FAKE_GH_MISSING=1');
        try {
            $this->workOnce();
        } finally {
            putenv('HOLDER_FAKE_GH_MISSING');
        }

        $run = $this->run($I, $companyId, $taskId);
        assertSame('failed', $run['status']);
        $found = false;
        foreach ($run['events'] as $event) {
            if (($event['payload']['message'] ?? null) === 'gh_unavailable') {
                $found = true;
            }
        }
        assertTrue($found);
        assertSame($marker, (string) file_get_contents(self::CWD_FILE));
    }

    public function finishingARepositoryTaskRemovesTheWorktree(ApiTester $I): void
    {
        [$companyId] = $this->ownerAgent($I);
        $project = $this->repository($I, $companyId);
        $taskId = $this->task($I, $companyId, null, $project['id']);

        file_put_contents(self::GIT_LOG, '');
        $I->sendPATCH('/api/v1/companies/' . $companyId . '/tasks/' . $taskId, ['status' => 'done']);
        $I->seeResponseCodeIs(HttpCode::OK);
        assertSame('done', $I->grabDataFromResponseByJsonPath('$.data.status')[0]);

        $log = (string) file_get_contents(self::GIT_LOG);
        assertStringContainsString('worktree remove --force', $log);
        assertStringContainsString('branch -D holder/' . $taskId, $log);
    }

    public function aFailedRemovalStillMarksTheTaskDone(ApiTester $I): void
    {
        [$companyId] = $this->ownerAgent($I);
        $project = $this->repository($I, $companyId);
        $taskId = $this->task($I, $companyId, null, $project['id']);
        $fail = '/tmp/holder-fake-git.fail';
        file_put_contents($fail, 'remove');
        try {
            $I->sendPATCH('/api/v1/companies/' . $companyId . '/tasks/' . $taskId, ['status' => 'done']);
            $I->seeResponseCodeIs(HttpCode::OK);
            assertSame('done', $I->grabDataFromResponseByJsonPath('$.data.status')[0]);
        } finally {
            if (is_file($fail)) {
                unlink($fail);
            }
        }
    }

    /** @return array{0: string, 1: string} */
    private function ownerAgent(ApiTester $I): array
    {
        $fail = '/tmp/holder-fake-git.fail';
        if (is_file($fail)) {
            unlink($fail);
        }
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/v1/session', ['email' => 'owner@holder.test', 'password' => 'secret-pass']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $token = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $companyId = $I->grabDataFromResponseByJsonPath('$.data.companies[0].id')[0];
        $I->haveHttpHeader('Authorization', 'Bearer ' . $token);

        $I->sendPUT('/api/v1/companies/' . $companyId . '/github-token', ['token' => 'ghp_example']);
        $I->seeResponseCodeIs(HttpCode::OK);

        $I->sendPOST('/api/v1/companies/' . $companyId . '/agents', [
            'name' => 'Ada',
            'title' => 'Engineer',
            'piBinary' => self::PI,
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $agentId = $I->grabDataFromResponseByJsonPath('$.data.id')[0];

        return [$companyId, $agentId];
    }

    /** @return array{id: string, workspace: string} */
    private function repository(ApiTester $I, string $companyId): array
    {
        $I->sendPOST('/api/v1/companies/' . $companyId . '/projects', [
            'name' => 'Widget',
            'repoUrl' => 'https://github.com/Acme/Widget',
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);

        return [
            'id' => $I->grabDataFromResponseByJsonPath('$.data.id')[0],
            'workspace' => $I->grabDataFromResponseByJsonPath('$.data.workspacePath')[0],
        ];
    }

    private function task(ApiTester $I, string $companyId, ?string $agentId, ?string $projectId): string
    {
        $payload = [
            'title' => $projectId === null ? 'Write the folder note' : 'Write the health check',
            'description' => 'Make /api/v1/health return ok.',
        ];
        if ($agentId !== null) {
            $payload['assigneeAgentId'] = $agentId;
        }
        if ($projectId !== null) {
            $payload['projectId'] = $projectId;
        }
        $I->sendPOST('/api/v1/companies/' . $companyId . '/tasks', $payload);
        $I->seeResponseCodeIs(HttpCode::OK);

        return $I->grabDataFromResponseByJsonPath('$.data.id')[0];
    }

    /**
     * @return array{status: string, prompt: string, events: list<array{payload: array<string, mixed>}>}
     */
    private function run(ApiTester $I, string $companyId, string $taskId): array
    {
        $I->sendGET('/api/v1/companies/' . $companyId . '/tasks/' . $taskId);
        $I->seeResponseCodeIs(HttpCode::OK);
        /** @var array{data: array{runs: list<array{status: string, prompt: string, events: list<array{payload: array<string, mixed>}>}>}} $body */
        $body = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);

        return $body['data']['runs'][0];
    }

    private function workOnce(): void
    {
        $worker = dirname(__DIR__, 2) . '/yii';
        $output = [];
        $code = 0;
        exec('php ' . escapeshellarg($worker) . ' heartbeat:work --once', $output, $code);
        assertSame(0, $code, implode("\n", $output));
    }

    private function cwd(): string
    {
        return trim((string) file_get_contents(self::CWD_FILE));
    }

    private function env(): string
    {
        return (string) file_get_contents(self::ENV_FILE);
    }
}
