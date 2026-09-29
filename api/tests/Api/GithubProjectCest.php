<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

use function PHPUnit\Framework\assertDirectoryExists;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringEndsWith;
use function PHPUnit\Framework\assertStringNotContainsString;

final readonly class GithubProjectCest
{
    private const FAIL_FILE = '/tmp/holder-fake-git.fail';

    public function aMissingTokenDoesNotCreateTheProject(ApiTester $I): void
    {
        [, $companyId] = $this->owner($I);
        $I->sendPUT('/api/v1/companies/' . $companyId . '/github-token', ['token' => '   ']);
        $I->seeResponseCodeIs(HttpCode::OK);

        $I->sendPOST('/api/v1/companies/' . $companyId . '/projects', [
            'name' => 'Api',
            'repoUrl' => 'https://github.com/Acme/Widget.git',
        ]);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->seeResponseContainsJson(['error_data' => ['code' => 'github_token_missing']]);

        $I->sendGET('/api/v1/companies/' . $companyId . '/projects');
        $I->seeResponseCodeIs(HttpCode::OK);
        assertNotContains('Api', $this->names($I));
    }

    public function anInvalidRepositoryUrlIsRejected(ApiTester $I): void
    {
        [, $companyId] = $this->owner($I);
        $I->sendPUT('/api/v1/companies/' . $companyId . '/github-token', ['token' => 'ghp_example']);
        $I->seeResponseCodeIs(HttpCode::OK);

        $I->sendPOST('/api/v1/companies/' . $companyId . '/projects', [
            'name' => 'Bad',
            'repoUrl' => 'git@github.com:Acme/Widget.git',
        ]);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->seeResponseContainsJson(['error_data' => ['code' => 'invalid_repo_url']]);
    }

    public function aFailedCloneLeavesNoProjectAndHidesTheToken(ApiTester $I): void
    {
        [, $companyId] = $this->owner($I);
        $token = 'ghp_supersecretvalue';
        file_put_contents(self::FAIL_FILE, 'clone');
        $before = $this->repoEntries();
        try {
            $I->sendPUT('/api/v1/companies/' . $companyId . '/github-token', ['token' => $token]);
            $I->seeResponseCodeIs(HttpCode::OK);

            $I->sendPOST('/api/v1/companies/' . $companyId . '/projects', [
                'name' => 'Broken',
                'repoUrl' => 'https://github.com/Acme/Widget.git',
            ]);
            $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
            $I->seeResponseContainsJson(['error_data' => ['code' => 'github_clone_failed']]);
            assertStringNotContainsString($token, $I->grabResponse());
        } finally {
            if (is_file(self::FAIL_FILE)) {
                unlink(self::FAIL_FILE);
            }
        }

        assertSame($before, $this->repoEntries());
        $I->sendGET('/api/v1/companies/' . $companyId . '/projects');
        $I->seeResponseCodeIs(HttpCode::OK);
        assertNotContains('Broken', $this->names($I));
    }

    public function aRepositoryIsClonedWhenTheProjectIsCreated(ApiTester $I): void
    {
        [, $companyId] = $this->owner($I);
        if (is_file(self::FAIL_FILE)) {
            unlink(self::FAIL_FILE);
        }
        $I->sendPUT('/api/v1/companies/' . $companyId . '/github-token', ['token' => 'ghp_example']);
        $I->seeResponseCodeIs(HttpCode::OK);

        $I->sendPOST('/api/v1/companies/' . $companyId . '/projects', [
            'name' => 'Widget',
            'repoUrl' => 'https://github.com/Acme/Widget.git/',
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson([
            'data' => [
                'name' => 'Widget',
                'repoUrl' => 'https://github.com/Acme/Widget',
                'defaultBranch' => 'main',
            ],
        ]);
        $id = $I->grabDataFromResponseByJsonPath('$.data.id')[0];
        $workspace = $I->grabDataFromResponseByJsonPath('$.data.workspacePath')[0];
        assertStringEndsWith('/repos/' . $id, $workspace);
        assertDirectoryExists($workspace);

        $I->sendGET('/api/v1/companies/' . $companyId . '/projects');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson([
            'data' => [[
                'id' => $id,
                'repoUrl' => 'https://github.com/Acme/Widget',
                'defaultBranch' => 'main',
                'workspacePath' => $workspace,
            ]],
        ]);
    }

    public function aLocalProjectHasNoRepository(ApiTester $I): void
    {
        [, $companyId] = $this->owner($I);
        $I->sendPOST('/api/v1/companies/' . $companyId . '/projects', [
            'name' => 'Local',
            'workspacePath' => '/tmp/local-only',
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson([
            'data' => [
                'name' => 'Local',
                'repoUrl' => '',
                'defaultBranch' => '',
                'workspacePath' => '/tmp/local-only',
            ],
        ]);
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

    /** @return list<string> */
    private function names(ApiTester $I): array
    {
        /** @var array{data: list<array{name: string}>} $listed */
        $listed = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);

        return array_column($listed['data'], 'name');
    }

    /** @return list<string> */
    private function repoEntries(): array
    {
        $path = dirname(__DIR__, 2) . '/runtime/holder-data/repos';
        if (!is_dir($path)) {
            return [];
        }
        $items = scandir($path);
        if ($items === false) {
            return [];
        }
        $entries = array_values(array_diff($items, ['.', '..']));
        sort($entries);

        return $entries;
    }
}
