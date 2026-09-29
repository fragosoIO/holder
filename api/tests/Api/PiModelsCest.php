<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

final readonly class PiModelsCest
{
    public function modelsAndThinkingComeFromPi(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/v1/session', ['email' => 'owner@holder.test', 'password' => 'secret-pass']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $token = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $companyId = $I->grabDataFromResponseByJsonPath('$.data.companies[0].id')[0];
        $I->haveHttpHeader('Authorization', 'Bearer ' . $token);

        $fakePi = dirname(__DIR__) . '/fixtures/fake-pi.php';
        $I->sendGET('/api/v1/companies/' . $companyId . '/pi/models?binary=' . urlencode($fakePi));
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson([
            'data' => [
                'provider' => 'vllm',
                'model' => 'qwen',
                'thinking' => 'medium',
                'models' => [
                    [
                        'provider' => 'anthropic',
                        'id' => 'claude-haiku-4-5',
                        'name' => 'Claude Haiku 4.5',
                        'thinking' => ['off', 'minimal', 'low', 'medium', 'high'],
                    ],
                    [
                        'provider' => 'grok-cli',
                        'id' => 'grok-fast',
                        'name' => 'Grok Fast',
                        'thinking' => ['off'],
                    ],
                    [
                        'provider' => 'vllm',
                        'id' => 'qwen',
                        'name' => 'Qwen',
                        'thinking' => ['off', 'low', 'medium', 'xhigh'],
                    ],
                ],
            ],
        ]);
    }

    public function aMissingBinaryFails(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/v1/session', ['email' => 'owner@holder.test', 'password' => 'secret-pass']);
        $token = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $companyId = $I->grabDataFromResponseByJsonPath('$.data.companies[0].id')[0];
        $I->haveHttpHeader('Authorization', 'Bearer ' . $token);

        $I->sendGET('/api/v1/companies/' . $companyId . '/pi/models?binary=definitely-not-pi-holder');
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->seeResponseContainsJson(['error_data' => ['code' => 'pi_missing']]);
    }
}
