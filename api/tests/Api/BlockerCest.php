<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

final readonly class BlockerCest
{
    public function aBlockedTaskResumesWhenEveryBlockerIsDone(ApiTester $I): void
    {
        $companyId = $this->board($I);
        $agentId = $this->agent($I, $companyId);
        $designId = $this->task($I, $companyId, 'Design the page');
        $copyId = $this->task($I, $companyId, 'Write the copy');
        $buildId = $this->task($I, $companyId, 'Build the page', 'blocked', $agentId, [$designId, $copyId]);

        $build = $this->show($I, $companyId, $buildId);
        assertSame('blocked', $build['status']);
        assertSame([$designId, $copyId], $build['blockerIds']);

        $this->patch($I, $companyId, $designId, ['status' => 'done']);
        assertSame('blocked', $this->show($I, $companyId, $buildId)['status']);

        $this->patch($I, $companyId, $copyId, ['status' => 'cancelled']);
        assertSame('blocked', $this->show($I, $companyId, $buildId)['status']);

        $this->patch($I, $companyId, $copyId, ['status' => 'done']);
        $ready = $this->show($I, $companyId, $buildId);
        assertSame('todo', $ready['status']);
        assertSame('done', $ready['blockers'][0]['status']);

        $ran = $this->untilRun($I, $companyId, $buildId);
        assertSame('blockers_resolved', $this->wakeReason($ran));
        assertStringContainsString('Write the copy (done)', $ran['prompt']);
        assertStringContainsString('Design the page (done)', $ran['prompt']);
    }

    public function theBoardCanSetABlockedTaskBackToTodo(ApiTester $I): void
    {
        $companyId = $this->board($I);
        $agentId = $this->agent($I, $companyId);
        $designId = $this->task($I, $companyId, 'Design the page');
        $buildId = $this->task($I, $companyId, 'Build the page', 'blocked', $agentId, [$designId]);

        $this->patch($I, $companyId, $buildId, ['status' => 'todo']);
        assertSame('todo', $this->show($I, $companyId, $buildId)['status']);
        $ran = $this->untilRun($I, $companyId, $buildId);
        assertSame('unblocked', $this->wakeReason($ran));
    }

    public function aCommentDoesNotStartABlockedTask(ApiTester $I): void
    {
        $companyId = $this->board($I);
        $agentId = $this->agent($I, $companyId);
        $designId = $this->task($I, $companyId, 'Design the page');
        $buildId = $this->task($I, $companyId, 'Build the page', 'blocked', $agentId, [$designId]);

        $I->sendPOST('/api/v1/companies/' . $companyId . '/tasks/' . $buildId . '/comments', [
            'body' => 'The design is still missing.',
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $this->work();
        $this->work();
        $build = $this->show($I, $companyId, $buildId);
        assertSame('blocked', $build['status']);
        assertSame([], $build['runs']);
    }

    public function linkingAFinishedBlockerResumesTheTask(ApiTester $I): void
    {
        $companyId = $this->board($I);
        $doneId = $this->task($I, $companyId, 'Design the page', 'done');
        $buildId = $this->task($I, $companyId, 'Build the page', 'blocked');

        $this->patch($I, $companyId, $buildId, ['blockerIds' => [$doneId]]);
        assertSame('todo', $this->show($I, $companyId, $buildId)['status']);

        $this->patch($I, $companyId, $buildId, ['status' => 'blocked']);
        assertSame('blocked', $this->show($I, $companyId, $buildId)['status']);
    }

    public function aBlockerCycleIsRejected(ApiTester $I): void
    {
        $companyId = $this->board($I);
        $firstId = $this->task($I, $companyId, 'First');
        $secondId = $this->task($I, $companyId, 'Second');
        $this->patch($I, $companyId, $firstId, ['status' => 'blocked', 'blockerIds' => [$secondId]]);

        $I->sendPATCH('/api/v1/companies/' . $companyId . '/tasks/' . $secondId, [
            'status' => 'blocked',
            'blockerIds' => [$firstId],
        ]);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->seeResponseContainsJson([
            'error_message' => 'A task cannot be blocked by a task it already blocks.',
            'error_data' => ['code' => 'blocker_cycle'],
        ]);
        assertSame('todo', $this->show($I, $companyId, $secondId)['status']);
    }

    private function board(ApiTester $I): string
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/v1/session', ['email' => 'owner@holder.test', 'password' => 'secret-pass']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $token = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $companyId = $I->grabDataFromResponseByJsonPath('$.data.companies[0].id')[0];
        $I->haveHttpHeader('Authorization', 'Bearer ' . $token);

        return $companyId;
    }

    private function agent(ApiTester $I, string $companyId): string
    {
        $I->sendPOST('/api/v1/companies/' . $companyId . '/agents', [
            'name' => 'Ada',
            'title' => 'Engineer',
            'piBinary' => dirname(__DIR__) . '/fixtures/fake-pi.php',
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);

        return $I->grabDataFromResponseByJsonPath('$.data.id')[0];
    }

    /**
     * @param list<string> $blockerIds
     */
    private function task(
        ApiTester $I,
        string $companyId,
        string $title,
        string $status = 'todo',
        ?string $assigneeId = null,
        array $blockerIds = [],
    ): string {
        $payload = ['title' => $title, 'description' => $title, 'status' => $status, 'blockerIds' => $blockerIds];
        if ($assigneeId !== null) {
            $payload['assigneeAgentId'] = $assigneeId;
        }
        $I->sendPOST('/api/v1/companies/' . $companyId . '/tasks', $payload);
        $I->seeResponseCodeIs(HttpCode::OK);

        return $I->grabDataFromResponseByJsonPath('$.data.id')[0];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function patch(ApiTester $I, string $companyId, string $taskId, array $payload): void
    {
        $I->sendPATCH('/api/v1/companies/' . $companyId . '/tasks/' . $taskId, $payload);
        $I->seeResponseCodeIs(HttpCode::OK);
    }

    /**
     * @return array<string, mixed>
     */
    private function show(ApiTester $I, string $companyId, string $taskId): array
    {
        $I->sendGET('/api/v1/companies/' . $companyId . '/tasks/' . $taskId);
        $I->seeResponseCodeIs(HttpCode::OK);
        /** @var array{data: array<string, mixed>} $body */
        $body = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);

        return $body['data'];
    }

    /**
     * @return array<string, mixed>
     */
    private function untilRun(ApiTester $I, string $companyId, string $taskId): array
    {
        $task = $this->show($I, $companyId, $taskId);
        for ($attempt = 0; $attempt < 8 && ($task['runs'] ?? []) === []; $attempt++) {
            $this->work();
            $task = $this->show($I, $companyId, $taskId);
        }
        $runs = $task['runs'] ?? [];
        assertSame(1, is_array($runs) ? count($runs) : 0);

        /** @var array<string, mixed> $run */
        $run = is_array($runs) ? $runs[0] : [];

        return $run;
    }

    /**
     * @param array<string, mixed> $run
     */
    private function wakeReason(array $run): string
    {
        $prompt = (string) ($run['prompt'] ?? '');
        if (preg_match('/Wake reason: (\S+)/', $prompt, $match) !== 1) {
            return '';
        }

        return $match[1];
    }

    private function work(): void
    {
        $output = [];
        $code = 0;
        exec('php ' . escapeshellarg(dirname(__DIR__, 2) . '/yii') . ' heartbeat:work --once', $output, $code);
        assertSame(0, $code, implode("\n", $output));
    }
}
