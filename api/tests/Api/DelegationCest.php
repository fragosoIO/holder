<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

final readonly class DelegationCest
{
    public function assignmentPromptNamesOnlyDirectReports(ApiTester $I): void
    {
        [$companyId, $token] = $this->company($I);
        $managerId = $this->agent($I, $companyId, 'Casey', 'Coordinates the company.');
        $builderId = $this->agent(
            $I,
            $companyId,
            'Blair',
            'You build the public page from an approved design.',
            $managerId,
        );
        $retiredId = $this->agent($I, $companyId, 'Drew', 'You should not be offered.', $managerId);
        $I->sendPOST('/api/v1/companies/' . $companyId . '/agents/' . $retiredId . '/terminate');
        $I->seeResponseCodeIs(HttpCode::OK);
        $this->agent($I, $companyId, 'Eden', 'You do not report to Casey.');
        $this->agent($I, $companyId, 'Quinn', 'You report to the builder, not the chief.', $builderId);

        $taskId = $this->task($I, $token, $companyId, $managerId, 'Add dark mode to the apple website');
        $run = $this->untilRun($I, $token, $companyId, $taskId);

        $prompt = (string) $run['prompt'];
        assertSame('assignment', $this->wakeReason($prompt));
        assertStringContainsString('People who report to you:', $prompt);
        assertStringContainsString($builderId . ' Blair, Frontend builder. Job: You build the public page from an approved design.', $prompt);
        assertStringContainsString('holder assign --task ' . $taskId . ' --agent AGENT_ID', $prompt);
        assertStringContainsString('Assigning creates a subtask for them.', $prompt);
        assertStringContainsString('This task stays with you', $prompt);
        assertStringNotContainsString('Drew', $prompt);
        assertStringNotContainsString('Eden', $prompt);
        assertStringNotContainsString('Quinn', $prompt);
    }

    public function aManagerCanHandTheTaskToADirectReport(ApiTester $I): void
    {
        [$companyId, $token] = $this->company($I);
        $managerId = $this->agent($I, $companyId, 'Casey', 'Coordinates the company.');
        $builderId = $this->agent(
            $I,
            $companyId,
            'Blair',
            'You build the public page from an approved design.',
            $managerId,
        );
        $taskId = $this->task($I, $token, $companyId, $managerId, 'Add dark mode to the apple website');

        $I->haveHttpHeader('Authorization', 'Bearer ' . $this->runToken($companyId, $managerId, $taskId));
        $I->sendPOST('/api/v1/agent/tasks/' . $taskId . '/assign', ['assigneeAgentId' => $builderId]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => ['assigneeAgentId' => $managerId]]);
        $I->seeResponseContainsJson(['data' => ['status' => 'blocked']]);
        $I->seeResponseContainsJson(['data' => ['comments' => [['authorType' => 'agent', 'body' => 'Assigned a subtask to Blair.']]]]);
        $I->seeResponseContainsJson(['data' => ['children' => [['assigneeAgentId' => $builderId]]]]);
        /** @var array{data: array{children: list<array{id: string}>}} $assigned */
        $assigned = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        $childId = $assigned['data']['children'][0]['id'];

        $I->haveHttpHeader('Authorization', 'Bearer ' . $token);
        $run = $this->untilRun($I, $token, $companyId, $childId);
        $parent = $this->show($I, $token, $companyId, $taskId);
        assertSame($managerId, $parent['assigneeAgentId']);
        assertSame('blocked', $parent['status']);
        assertSame([], $parent['runs']);
        assertSame('assignment', $this->wakeReason((string) $run['prompt']));
        assertStringContainsString('This task was assigned to you by another agent.', (string) $run['prompt']);
        assertStringNotContainsString('People who report to you:', (string) $run['prompt']);
        assertSame(1, count($this->show($I, $token, $companyId, $childId)['runs']));

        $I->haveHttpHeader('Authorization', 'Bearer ' . $this->runToken($companyId, $managerId, $taskId));
        $I->sendPOST('/api/v1/agent/tasks/' . $taskId . '/assign', ['assigneeAgentId' => $builderId]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $again = $this->show($I, $token, $companyId, $taskId);
        assertSame($managerId, $again['assigneeAgentId']);
        assertSame(2, count($again['children']));
    }

    public function aFinishedSubtaskReportsBackToTheAssigningAgent(ApiTester $I): void
    {
        [$companyId, $token] = $this->company($I);
        $managerId = $this->agent($I, $companyId, 'Casey', 'Coordinates the company.');
        $builderId = $this->agent(
            $I,
            $companyId,
            'Blair',
            'You build the public page from an approved design.',
            $managerId,
        );
        $taskId = $this->task($I, $token, $companyId, $managerId, 'Add dark mode to the apple website');

        $I->haveHttpHeader('Authorization', 'Bearer ' . $this->runToken($companyId, $managerId, $taskId));
        $I->sendPOST('/api/v1/agent/tasks/' . $taskId . '/assign', ['assigneeAgentId' => $builderId]);
        $I->seeResponseCodeIs(HttpCode::OK);
        /** @var array{data: array{children: list<array{id: string}>}} $assigned */
        $assigned = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        $childId = $assigned['data']['children'][0]['id'];

        $I->haveHttpHeader('Authorization', 'Bearer ' . $this->runToken($companyId, $builderId, $childId));
        $I->sendPOST('/api/v1/agent/tasks/' . $childId . '/comments', ['body' => 'Dark mode is in the stylesheet.']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->sendPOST('/api/v1/agent/tasks/' . $childId . '/status', ['status' => 'done']);
        $I->seeResponseCodeIs(HttpCode::OK);

        $parent = $this->show($I, $token, $companyId, $taskId);
        assertSame($managerId, $parent['assigneeAgentId']);
        assertSame('todo', $parent['status']);
        assertSame('done', $parent['blockers'][0]['status']);
        assertSame('done', $this->show($I, $token, $companyId, $childId)['status']);
        $report = '';
        foreach ($parent['comments'] as $comment) {
            if (!is_array($comment) || ($comment['authorId'] ?? '') !== $builderId) {
                continue;
            }
            $report = (string) $comment['body'];
        }
        assertStringContainsString('Blair finished the subtask "Add dark mode to the apple website".', $report);
        assertStringContainsString('Dark mode is in the stylesheet.', $report);

        $run = $this->untilRun($I, $token, $companyId, $taskId);
        assertSame('review', $this->wakeReason((string) $run['prompt']));
        assertStringContainsString('Check the work before you assign anything else.', (string) $run['prompt']);
        assertStringContainsString('Dark mode is in the stylesheet.', (string) $run['prompt']);
        assertSame($managerId, $this->show($I, $token, $companyId, $taskId)['assigneeAgentId']);
    }

    public function aManagerCannotHandTheTaskToAnyoneElse(ApiTester $I): void
    {
        [$companyId, $token] = $this->company($I);
        $managerId = $this->agent($I, $companyId, 'Casey', 'Coordinates the company.');
        $builderId = $this->agent($I, $companyId, 'Blair', 'You build the public page.', $managerId);
        $retiredId = $this->agent($I, $companyId, 'Drew', 'Retired.', $managerId);
        $I->sendPOST('/api/v1/companies/' . $companyId . '/agents/' . $retiredId . '/terminate');
        $I->seeResponseCodeIs(HttpCode::OK);
        $peerId = $this->agent($I, $companyId, 'Eden', 'A peer.');
        $grandchildId = $this->agent($I, $companyId, 'Quinn', 'Reports to Blair.', $builderId);
        $taskId = $this->task($I, $token, $companyId, $managerId, 'Add dark mode');
        $otherId = $this->task($I, $token, $companyId, $managerId, 'Something else');

        $I->haveHttpHeader('Authorization', 'Bearer ' . $this->runToken($companyId, $managerId, $taskId));
        foreach ([$peerId, $grandchildId] as $agentId) {
            $I->sendPOST('/api/v1/agent/tasks/' . $taskId . '/assign', ['assigneeAgentId' => $agentId]);
            $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
            $I->seeResponseContainsJson(['error_data' => ['code' => 'not_a_report']]);
        }
        $I->sendPOST('/api/v1/agent/tasks/' . $taskId . '/assign', ['assigneeAgentId' => $retiredId]);
        $I->seeResponseCodeIs(HttpCode::CONFLICT);
        $I->seeResponseContainsJson(['error_data' => ['code' => 'agent_inactive']]);
        $I->sendPOST('/api/v1/agent/tasks/' . $taskId . '/assign', ['assigneeAgentId' => '']);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->seeResponseContainsJson(['error_data' => ['code' => 'missing_field']]);
        $I->sendPOST('/api/v1/agent/tasks/' . $otherId . '/assign', ['assigneeAgentId' => $builderId]);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);

        $I->haveHttpHeader('Authorization', 'Bearer ' . $this->runToken($companyId, $builderId, $taskId));
        $I->sendPOST('/api/v1/agent/tasks/' . $taskId . '/assign', ['assigneeAgentId' => $grandchildId]);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseContainsJson(['error_data' => ['code' => 'forbidden']]);

        assertSame($managerId, $this->show($I, $token, $companyId, $taskId)['assigneeAgentId']);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function company(ApiTester $I): array
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/v1/session', ['email' => 'owner@holder.test', 'password' => 'secret-pass']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $token = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $I->haveHttpHeader('Authorization', 'Bearer ' . $token);
        $I->sendPOST('/api/v1/companies', ['name' => 'Delegation ' . uniqid(), 'mission' => 'Websites']);
        $I->seeResponseCodeIs(HttpCode::OK);

        return [$I->grabDataFromResponseByJsonPath('$.data.id')[0], $token];
    }

    private function agent(
        ApiTester $I,
        string $companyId,
        string $name,
        string $job,
        ?string $managerId = null,
    ): string {
        $payload = [
            'name' => $name,
            'title' => $name === 'Blair' ? 'Frontend builder' : $name,
            'jobDescription' => $job,
            'piBinary' => dirname(__DIR__) . '/fixtures/fake-pi.php',
        ];
        if ($managerId !== null) {
            $payload['managerId'] = $managerId;
        }
        $I->sendPOST('/api/v1/companies/' . $companyId . '/agents', $payload);
        $I->seeResponseCodeIs(HttpCode::OK);

        return $I->grabDataFromResponseByJsonPath('$.data.id')[0];
    }

    private function task(ApiTester $I, string $token, string $companyId, string $assigneeId, string $description): string
    {
        $I->haveHttpHeader('Authorization', 'Bearer ' . $token);
        $I->sendPOST('/api/v1/companies/' . $companyId . '/tasks', [
            'title' => $description,
            'description' => $description,
            'assigneeAgentId' => $assigneeId,
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);

        return $I->grabDataFromResponseByJsonPath('$.data.id')[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function show(ApiTester $I, string $token, string $companyId, string $taskId): array
    {
        $I->haveHttpHeader('Authorization', 'Bearer ' . $token);
        $I->sendGET('/api/v1/companies/' . $companyId . '/tasks/' . $taskId);
        $I->seeResponseCodeIs(HttpCode::OK);
        /** @var array{data: array<string, mixed>} $body */
        $body = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);

        return $body['data'];
    }

    /**
     * @return array<string, mixed>
     */
    private function untilRun(ApiTester $I, string $token, string $companyId, string $taskId): array
    {
        $task = $this->show($I, $token, $companyId, $taskId);
        for ($attempt = 0; $attempt < 12 && ($task['runs'] ?? []) === []; $attempt++) {
            $this->work();
            $task = $this->show($I, $token, $companyId, $taskId);
        }
        $runs = $task['runs'] ?? [];
        assertSame(1, is_array($runs) ? count($runs) : 0);

        /** @var array<string, mixed> $run */
        $run = is_array($runs) ? $runs[0] : [];

        return $run;
    }

    private function wakeReason(string $prompt): string
    {
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

    private function runToken(string $companyId, string $agentId, string $taskId): string
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode([
            'companyId' => $companyId,
            'agentId' => $agentId,
            'runId' => '00000000-0000-4000-8000-000000000001',
            'taskId' => $taskId,
            'exp' => time() + 3600,
        ])), '+/', '-_'), '=');
        $key = getenv('HOLDER_SECRETS_KEY');
        if (!is_string($key) || $key === '') {
            $key = 'dev-only-change-me';
        }

        return $payload . '.' . hash_hmac('sha256', $payload, $key);
    }
}
