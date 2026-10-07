<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

final readonly class FloorCest
{
    public function aMissingSessionIsUnauthorized(ApiTester $I): void
    {
        $I->sendGET('/api/v1/companies/00000000-0000-4000-8000-000000000000/floor');
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $I->seeResponseContainsJson(['error_data' => ['code' => 'unauthenticated']]);
    }

    public function aMemberCannotSeeAnotherCompanysFloor(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/v1/session', ['email' => 'owner@holder.test', 'password' => 'secret-pass']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $owner = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $I->haveHttpHeader('Authorization', 'Bearer ' . $owner);
        $I->sendPOST('/api/v1/companies', ['name' => 'Hidden ' . uniqid(), 'mission' => 'private']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $hidden = $I->grabDataFromResponseByJsonPath('$.data.id')[0];

        $I->sendPOST('/api/v1/companies', ['name' => 'Visible ' . uniqid(), 'mission' => 'shared']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $visible = $I->grabDataFromResponseByJsonPath('$.data.id')[0];
        $email = 'floor-stranger-' . uniqid() . '@holder.test';
        $I->sendPOST('/api/v1/companies/' . $visible . '/invites', ['email' => $email, 'role' => 'member']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $invite = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $I->deleteHeader('Authorization');
        $I->sendPOST('/api/v1/invites/' . $invite . '/accept', ['name' => 'Stranger', 'password' => 'stranger-pass']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $stranger = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $I->haveHttpHeader('Authorization', 'Bearer ' . $stranger);

        $I->sendGET('/api/v1/companies/' . $hidden . '/floor');
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
    }

    public function anIdleAgentSmokesOnTheBalcony(ApiTester $I): void
    {
        $companyId = $this->company($I);
        $agentId = $this->agent($I, $companyId, 'Ada');

        $agent = $this->floorAgent($I, $companyId, $agentId);
        assertNotNull($agent);
        assertSame('balcony', $agent['place']);
        assertSame('Smoking', $agent['step']);
        assertNull($agent['task']);
    }

    public function anAssignedTaskThatIsNotRunningGoesToTheBalcony(ApiTester $I): void
    {
        $companyId = $this->company($I);
        $agentId = $this->agent($I, $companyId, 'Ada');
        $I->sendPOST('/api/v1/companies/' . $companyId . '/tasks', [
            'title' => 'Write the health check',
            'description' => '',
            'assigneeAgentId' => $agentId,
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $taskId = $I->grabDataFromResponseByJsonPath('$.data.id')[0];

        $agent = $this->floorAgent($I, $companyId, $agentId);
        assertNotNull($agent);
        assertSame('balcony', $agent['place']);
        assertSame('Smoking', $agent['step']);
        assertSame($taskId, $agent['task']['id'] ?? null);
        assertSame('Write the health check', $agent['task']['title'] ?? null);
    }

    public function aPausedAgentStaysInTheList(ApiTester $I): void
    {
        $companyId = $this->company($I);
        $agentId = $this->agent($I, $companyId, 'Ada');
        $I->sendPOST('/api/v1/companies/' . $companyId . '/agents/' . $agentId . '/pause');
        $I->seeResponseCodeIs(HttpCode::OK);

        $agent = $this->floorAgent($I, $companyId, $agentId);
        assertNotNull($agent);
        assertSame('desk', $agent['place']);
        assertSame('Paused', $agent['step']);
    }

    public function aTerminatedAgentIsAbsent(ApiTester $I): void
    {
        $companyId = $this->company($I);
        $kept = $this->agent($I, $companyId, 'Ada');
        $gone = $this->agent($I, $companyId, 'Bea');
        $I->sendPOST('/api/v1/companies/' . $companyId . '/agents/' . $gone . '/terminate');
        $I->seeResponseCodeIs(HttpCode::OK);

        assertNotNull($this->floorAgent($I, $companyId, $kept));
        assertNull($this->floorAgent($I, $companyId, $gone));
    }

    public function aBlockedTaskSendsTheAgentBack(ApiTester $I): void
    {
        $companyId = $this->company($I);
        $agentId = $this->agent($I, $companyId, 'Ada');
        $I->sendPOST('/api/v1/companies/' . $companyId . '/tasks', [
            'title' => 'Ask the owner',
            'description' => '',
            'assigneeAgentId' => $agentId,
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $taskId = $I->grabDataFromResponseByJsonPath('$.data.id')[0];
        $I->sendPATCH('/api/v1/companies/' . $companyId . '/tasks/' . $taskId, ['status' => 'blocked']);
        $I->seeResponseCodeIs(HttpCode::OK);

        $agent = $this->floorAgent($I, $companyId, $agentId);
        assertNotNull($agent);
        assertSame('desk', $agent['place']);
        assertSame('Waiting on you', $agent['step']);
        assertSame($taskId, $agent['task']['id'] ?? null);
    }

    private function company(ApiTester $I): string
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/v1/session', ['email' => 'owner@holder.test', 'password' => 'secret-pass']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $token = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $I->haveHttpHeader('Authorization', 'Bearer ' . $token);
        $I->sendPOST('/api/v1/companies', ['name' => 'Floor ' . uniqid(), 'mission' => 'Watch the work.']);
        $I->seeResponseCodeIs(HttpCode::OK);

        return $I->grabDataFromResponseByJsonPath('$.data.id')[0];
    }

    private function agent(ApiTester $I, string $companyId, string $name): string
    {
        $I->sendPOST('/api/v1/companies/' . $companyId . '/agents', [
            'name' => $name,
            'title' => 'Engineer',
            'jobDescription' => 'Builds the product.',
            'piBinary' => dirname(__DIR__) . '/fixtures/fake-pi.php',
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);

        return $I->grabDataFromResponseByJsonPath('$.data.id')[0];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function floorAgent(ApiTester $I, string $companyId, string $agentId): ?array
    {
        $I->sendGET('/api/v1/companies/' . $companyId . '/floor');
        $I->seeResponseCodeIs(HttpCode::OK);
        /** @var array{data: array{agents: list<array<string, mixed>>}} $body */
        $body = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        foreach ($body['data']['agents'] as $agent) {
            if ($agent['id'] === $agentId) {
                return $agent;
            }
        }

        return null;
    }
}
