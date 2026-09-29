<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

final readonly class CompanyIsolationCest
{
    public function aMemberCannotSeeAnotherCompany(ApiTester $I): void
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

        $email = 'stranger-' . uniqid() . '@holder.test';
        $I->sendPOST('/api/v1/companies/' . $visible . '/invites', ['email' => $email, 'role' => 'member']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $invite = $I->grabDataFromResponseByJsonPath('$.data.token')[0];

        $I->deleteHeader('Authorization');
        $I->sendPOST('/api/v1/invites/' . $invite . '/accept', ['name' => 'Stranger', 'password' => 'stranger-pass']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $stranger = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $I->haveHttpHeader('Authorization', 'Bearer ' . $stranger);

        $I->sendGET('/api/v1/companies/' . $hidden);
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);

        $I->sendGET('/api/v1/companies/' . $visible);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => ['id' => $visible]]);
    }
}
