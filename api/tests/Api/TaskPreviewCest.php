<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

use function PHPUnit\Framework\assertIsString;
use function PHPUnit\Framework\assertSame;

final readonly class TaskPreviewCest
{
    public function aMissingSessionIsUnauthorized(ApiTester $I): void
    {
        $I->deleteHeader('Authorization');
        $I->resetCookie('holder_session');
        $I->sendGET('/api/v1/companies/00000000-0000-4000-8000-000000000000/tasks/00000000-0000-4000-8000-000000000001/preview');
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $I->seeResponseContainsJson(['error_data' => ['code' => 'unauthenticated']]);
    }

    public function aMemberCannotPreviewAnotherCompanysTask(ApiTester $I): void
    {
        $owner = $this->signIn($I);
        $I->haveHttpHeader('Authorization', 'Bearer ' . $owner);
        $I->sendPOST('/api/v1/companies', ['name' => 'Hidden ' . uniqid(), 'mission' => 'private']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $hidden = $I->grabDataFromResponseByJsonPath('$.data.id')[0];
        $I->sendPOST('/api/v1/companies/' . $hidden . '/tasks', ['title' => 'Secret page', 'description' => '']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $taskId = $I->grabDataFromResponseByJsonPath('$.data.id')[0];

        $I->sendPOST('/api/v1/companies', ['name' => 'Visible ' . uniqid(), 'mission' => 'shared']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $visible = $I->grabDataFromResponseByJsonPath('$.data.id')[0];
        $email = 'preview-stranger-' . uniqid() . '@holder.test';
        $I->sendPOST('/api/v1/companies/' . $visible . '/invites', ['email' => $email, 'role' => 'member']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $invite = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $I->deleteHeader('Authorization');
        $I->sendPOST('/api/v1/invites/' . $invite . '/accept', ['name' => 'Stranger', 'password' => 'stranger-pass']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $stranger = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $I->haveHttpHeader('Authorization', 'Bearer ' . $stranger);

        $I->sendGET('/api/v1/companies/' . $hidden . '/tasks/' . $taskId . '/preview');
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
    }

    public function aViewerCanReadThePreview(ApiTester $I): void
    {
        [$companyId] = $this->company($I);
        $I->sendPOST('/api/v1/companies/' . $companyId . '/tasks', ['title' => 'Look', 'description' => '']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $taskId = $I->grabDataFromResponseByJsonPath('$.data.id')[0];
        $email = 'preview-viewer-' . uniqid() . '@holder.test';
        $I->sendPOST('/api/v1/companies/' . $companyId . '/invites', ['email' => $email, 'role' => 'viewer']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $invite = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $I->deleteHeader('Authorization');
        $I->sendPOST('/api/v1/invites/' . $invite . '/accept', ['name' => 'Viewer', 'password' => 'viewer-pass']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $viewer = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $I->haveHttpHeader('Authorization', 'Bearer ' . $viewer);

        $I->sendGET('/api/v1/companies/' . $companyId . '/tasks/' . $taskId . '/preview');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => ['state' => 'no_page']]);
    }

    public function anEmptyWorkspaceHasNoPage(ApiTester $I): void
    {
        [$companyId, $taskId] = $this->task($I);

        $I->sendGET('/api/v1/companies/' . $companyId . '/tasks/' . $taskId . '/preview');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => ['state' => 'no_page', 'url' => null, 'revision' => '']]);
    }

    public function aMemberCanReadThePageWithoutASessionCookie(ApiTester $I): void
    {
        [$companyId, $taskId, $workspace] = $this->task($I);
        file_put_contents($workspace . '/index.html', '<h1>Berry</h1>');
        file_put_contents($workspace . '/styles.css', 'h1{}');

        $I->sendGET('/api/v1/companies/' . $companyId . '/tasks/' . $taskId . '/preview');
        $I->seeResponseCodeIs(HttpCode::OK);
        /** @var array{data: array{state: string, url: string|null}} $body */
        $body = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        assertSame('ready', $body['data']['state']);
        assertIsString($body['data']['url']);
        $url = $body['data']['url'];

        $I->deleteHeader('Authorization');
        $I->resetCookie('holder_session');
        $I->sendGET($url);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeHttpHeader('Content-Type', 'text/html; charset=utf-8');
        $I->seeResponseEquals('<h1>Berry</h1>');

        $css = preg_replace('#/index\.html$#', '/styles.css', $url);
        assertIsString($css);
        $I->sendGET($css);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeHttpHeader('Content-Type', 'text/css; charset=utf-8');
        $I->seeResponseEquals('h1{}');
    }

    public function aTokenDoesNotOpenAnotherTask(ApiTester $I): void
    {
        [$companyId, $taskId, $workspace] = $this->task($I);
        file_put_contents($workspace . '/index.html', '<h1>One</h1>');
        $I->sendPOST('/api/v1/companies/' . $companyId . '/tasks', ['title' => 'Two', 'description' => '']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $other = $I->grabDataFromResponseByJsonPath('$.data.id')[0];

        $I->sendGET('/api/v1/companies/' . $companyId . '/tasks/' . $taskId . '/preview');
        $I->seeResponseCodeIs(HttpCode::OK);
        $url = $I->grabDataFromResponseByJsonPath('$.data.url')[0];
        $token = basename(dirname((string) $url));
        $I->deleteHeader('Authorization');
        $I->resetCookie('holder_session');

        $I->sendGET('/api/v1/companies/' . $companyId . '/tasks/' . $other . '/preview/' . $token . '/index.html');
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->seeResponseEquals('Not found.');
    }

    public function aFileOutsideTheSiteIsNotServed(ApiTester $I): void
    {
        [$companyId, $taskId, $workspace] = $this->task($I);
        file_put_contents($workspace . '/index.html', '<h1>Home</h1>');
        file_put_contents($workspace . '/notes.md', '# notes');
        mkdir($workspace . '/.git');
        file_put_contents($workspace . '/.git/HEAD', 'ref: refs/heads/main');
        file_put_contents(dirname($workspace) . '/secret.txt', 'secret');

        $I->sendGET('/api/v1/companies/' . $companyId . '/tasks/' . $taskId . '/preview');
        $I->seeResponseCodeIs(HttpCode::OK);
        $url = (string) $I->grabDataFromResponseByJsonPath('$.data.url')[0];
        $prefix = preg_replace('#/index\.html$#', '', $url);
        assertIsString($prefix);
        $I->deleteHeader('Authorization');
        $I->resetCookie('holder_session');

        foreach (['/%2e%2e/secret.txt', '/.git/HEAD', '/notes.md'] as $suffix) {
            $I->sendGET($prefix . $suffix);
            $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
            $I->seeResponseEquals('Not found.');
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function task(ApiTester $I): array
    {
        [$companyId, $workspace] = $this->company($I);
        $I->sendPOST('/api/v1/companies/' . $companyId . '/tasks', ['title' => 'Preview me', 'description' => '']);
        $I->seeResponseCodeIs(HttpCode::OK);

        return [$companyId, $I->grabDataFromResponseByJsonPath('$.data.id')[0], $workspace];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function company(ApiTester $I): array
    {
        $this->signIn($I);
        $I->sendPOST('/api/v1/companies', ['name' => 'Preview ' . uniqid(), 'mission' => 'Show the site.']);
        $I->seeResponseCodeIs(HttpCode::OK);

        return [
            $I->grabDataFromResponseByJsonPath('$.data.id')[0],
            $I->grabDataFromResponseByJsonPath('$.data.workspacePath')[0],
        ];
    }

    private function signIn(ApiTester $I): string
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/v1/session', ['email' => 'owner@holder.test', 'password' => 'secret-pass']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $token = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $I->haveHttpHeader('Authorization', 'Bearer ' . $token);

        return $token;
    }
}
