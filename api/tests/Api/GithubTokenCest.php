<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

use App\Shared\Env;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertStringNotContainsString;

final readonly class GithubTokenCest
{
    public function theOwnerCanSaveAndClearAToken(ApiTester $I): void
    {
        [$token, $companyId] = $this->owner($I);

        $I->sendPUT('/api/v1/companies/' . $companyId . '/github-token', ['token' => 'ghp_example']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => ['githubConnected' => true]]);
        assertStringNotContainsString('ghp_example', $I->grabResponse());

        $I->sendGET('/api/v1/companies/' . $companyId);
        $I->seeResponseContainsJson(['data' => ['githubConnected' => true]]);
        assertStringNotContainsString('ghp_example', $I->grabResponse());

        $I->sendPATCH('/api/v1/companies/' . $companyId, ['name' => 'Acme', 'mission' => 'Still shipping']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => ['githubConnected' => true, 'mission' => 'Still shipping']]);

        $I->sendPUT('/api/v1/companies/' . $companyId . '/github-token', []);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->seeResponseContainsJson(['error_data' => ['code' => 'missing_field']]);
        $stored = $this->githubTokenColumn($companyId);
        assertNotSame('ghp_example', $stored);
        assertStringNotContainsString('ghp_example', (string) $stored);
        $I->sendGET('/api/v1/companies/' . $companyId);
        $I->seeResponseContainsJson(['data' => ['githubConnected' => true]]);

        $I->sendPUT('/api/v1/companies/' . $companyId . '/github-token', ['token' => '   ']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => ['githubConnected' => false]]);
        assertFalse($I->grabDataFromResponseByJsonPath('$.data.githubConnected')[0]);
    }

    public function aMemberCannotSaveAToken(ApiTester $I): void
    {
        [$owner, $companyId] = $this->owner($I);
        $email = 'member-' . uniqid() . '@holder.test';
        $I->sendPOST('/api/v1/companies/' . $companyId . '/invites', ['email' => $email, 'role' => 'member']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $invite = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $I->deleteHeader('Authorization');
        $I->sendPOST('/api/v1/invites/' . $invite . '/accept', ['name' => 'Member', 'password' => 'member-pass']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->haveHttpHeader('Authorization', 'Bearer ' . $I->grabDataFromResponseByJsonPath('$.data.token')[0]);

        $I->sendPUT('/api/v1/companies/' . $companyId . '/github-token', ['token' => 'ghp_example']);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        unset($owner);
    }

    /** @return array{string, string} */
    private function owner(ApiTester $I): array
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/v1/session', ['email' => 'owner@holder.test', 'password' => 'secret-pass']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $token = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $companyId = $I->grabDataFromResponseByJsonPath('$.data.companies[0].id')[0];
        $I->haveHttpHeader('Authorization', 'Bearer ' . $token);

        return [$token, $companyId];
    }

    private function githubTokenColumn(string $companyId): ?string
    {
        $pdo = new \PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                Env::get('HOLDER_DB_HOST', '127.0.0.1'),
                Env::get('HOLDER_DB_PORT', '5432'),
                Env::get('HOLDER_DB_NAME', 'holder'),
            ),
            Env::get('HOLDER_DB_USER', 'holder'),
            Env::get('HOLDER_DB_PASSWORD', 'holder'),
        );
        $statement = $pdo->prepare('SELECT github_token FROM companies WHERE id = :id');
        $statement->execute(['id' => $companyId]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }
}
