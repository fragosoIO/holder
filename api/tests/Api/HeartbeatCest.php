<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertDirectoryExists;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringEndsWith;
use function PHPUnit\Framework\assertTrue;

final readonly class HeartbeatCest
{
    public function assigningATaskWakesPiAndASecondWorkerDoesNotRepeatIt(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/v1/session', ['email' => 'owner@holder.test', 'password' => 'secret-pass']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $token = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $companyId = $I->grabDataFromResponseByJsonPath('$.data.companies[0].id')[0];
        $I->haveHttpHeader('Authorization', 'Bearer ' . $token);

        $I->sendPOST('/api/v1/companies/' . $companyId . '/goals', [
            'title' => 'Ship Holder',
            'description' => 'A company of Pi agents.',
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $goalId = $I->grabDataFromResponseByJsonPath('$.data.id')[0];

        $I->sendGET('/api/v1/companies/' . $companyId);
        $I->seeResponseCodeIs(HttpCode::OK);
        $workspace = $I->grabDataFromResponseByJsonPath('$.data.workspacePath')[0];
        assertStringEndsWith('/workspaces/' . $companyId, $workspace);
        assertDirectoryExists($workspace);

        $fakePi = dirname(__DIR__) . '/fixtures/fake-pi.php';
        $I->sendPOST('/api/v1/companies/' . $companyId . '/agents', [
            'name' => 'Ada',
            'title' => 'Engineer',
            'jobDescription' => 'Builds the product.',
            'piBinary' => $fakePi,
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson([
            'data' => ['piVersion' => 'fake-0.0.1', 'status' => 'active', 'workspacePath' => $workspace],
        ]);
        $agentId = $I->grabDataFromResponseByJsonPath('$.data.id')[0];

        $I->sendPOST('/api/v1/companies/' . $companyId . '/tasks', [
            'title' => 'Write the health check',
            'description' => 'Make /api/v1/health return ok.',
            'goalId' => $goalId,
            'assigneeAgentId' => $agentId,
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $taskId = $I->grabDataFromResponseByJsonPath('$.data.id')[0];

        $worker = dirname(__DIR__, 2) . '/yii';
        $found = false;
        for ($attempt = 0; $attempt < 8 && !$found; $attempt++) {
            $output = [];
            $code = 0;
            exec('php ' . escapeshellarg($worker) . ' heartbeat:work --once', $output, $code);
            assertSame(0, $code, implode("\n", $output));
            $I->sendGET('/api/v1/companies/' . $companyId . '/tasks/' . $taskId);
            $I->seeResponseCodeIs(HttpCode::OK);
            /** @var array{data: array{comments: list<mixed>}} $body */
            $body = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
            $found = $body['data']['comments'] !== [];
        }
        assertTrue($found, 'The assigned task never received a Pi comment.');
        $I->seeResponseContainsJson([
            'data' => [
                'goalId' => $goalId,
                'assigneeAgentId' => $agentId,
                'comments' => [
                    ['authorType' => 'agent', 'body' => 'Pi checked the task.'],
                ],
            ],
        ]);

        $I->sendGET('/api/v1/companies/' . $companyId . '/tasks?assigneeAgentId=' . $agentId . '&goalId=' . $goalId);
        $I->seeResponseContainsJson(['data' => [['id' => $taskId]]]);

        $again = [];
        $againCode = 0;
        exec('php ' . escapeshellarg($worker) . ' heartbeat:work --once', $again, $againCode);
        assertSame(0, $againCode, implode("\n", $again));
        $I->sendGET('/api/v1/companies/' . $companyId . '/tasks/' . $taskId);
        /** @var array{data: array{comments: list<mixed>, runs: list<mixed>}} $after */
        $after = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        assertCount(1, $after['data']['comments']);
        assertCount(1, $after['data']['runs']);
    }

    public function aModelErrorFailsTheRun(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/v1/session', ['email' => 'owner@holder.test', 'password' => 'secret-pass']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $token = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $companyId = $I->grabDataFromResponseByJsonPath('$.data.companies[0].id')[0];
        $I->haveHttpHeader('Authorization', 'Bearer ' . $token);

        $fakePi = dirname(__DIR__) . '/fixtures/fake-pi.php';
        $I->sendPOST('/api/v1/companies/' . $companyId . '/agents', [
            'name' => 'Ada',
            'title' => 'Engineer',
            'piBinary' => $fakePi,
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $agentId = $I->grabDataFromResponseByJsonPath('$.data.id')[0];

        $I->sendPOST('/api/v1/companies/' . $companyId . '/tasks', [
            'title' => 'Write the health check',
            'description' => 'Make /api/v1/health return ok.',
            'assigneeAgentId' => $agentId,
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $taskId = $I->grabDataFromResponseByJsonPath('$.data.id')[0];

        $worker = dirname(__DIR__, 2) . '/yii';
        putenv('FAKE_PI_ERROR=Refresh token expired');
        try {
            $output = [];
            $code = 0;
            exec('php ' . escapeshellarg($worker) . ' heartbeat:work --once', $output, $code);
            assertSame(0, $code, implode("\n", $output));
        } finally {
            putenv('FAKE_PI_ERROR');
        }

        $I->sendGET('/api/v1/companies/' . $companyId . '/tasks/' . $taskId);
        $I->seeResponseCodeIs(HttpCode::OK);
        /** @var array{data: array{comments: list<array{body: string}>, runs: list<array{status: string}>}} $body */
        $body = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        assertCount(1, $body['data']['runs']);
        assertSame('failed', $body['data']['runs'][0]['status']);
        assertStringContainsString('anthropic: Refresh token expired', $body['data']['comments'][0]['body']);
    }

    public function hiringWithoutPiFails(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/v1/session', ['email' => 'owner@holder.test', 'password' => 'secret-pass']);
        $token = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $companyId = $I->grabDataFromResponseByJsonPath('$.data.companies[0].id')[0];
        $I->haveHttpHeader('Authorization', 'Bearer ' . $token);
        $I->sendPOST('/api/v1/companies/' . $companyId . '/agents', [
            'name' => 'Missing',
            'title' => 'Engineer',
            'piBinary' => 'definitely-not-pi-holder',
        ]);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->seeResponseContainsJson(['error_data' => ['code' => 'pi_missing']]);
    }
}
