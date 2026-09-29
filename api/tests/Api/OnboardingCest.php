<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Domain\Heartbeat\RunToken;
use App\Domain\HolderConfig;
use App\Domain\Ids;
use App\Shared\Env;
use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

final readonly class OnboardingCest
{
    private const DESCRIPTION = <<<'TEXT'
Use the `first-task` skill (/first-task) for this onboarding task. Read its SKILL.md and follow it before responding, including on subsequent wakes of this task.

Single-task proposal mode: `confirmation`.
TEXT;

    public function completingOnboardingCreatesTheOrgTheFirstTaskAndAChiefWhoCanHire(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/v1/session', ['email' => 'owner@holder.test', 'password' => 'secret-pass']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $token = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $I->haveHttpHeader('Authorization', 'Bearer ' . $token);

        $fakePi = dirname(__DIR__) . '/fixtures/fake-pi.php';
        $firstName = 'Northwind ' . uniqid();
        $secondName = 'Contoso ' . uniqid();
        $first = $this->complete($I, $fakePi, $firstName, 'Ada');
        $second = $this->complete($I, $fakePi, $secondName, 'Grace');
        assertNotSame($first['companyId'], $second['companyId']);
        assertNotSame($first['taskId'], $second['taskId']);

        $this->assertOrganization($I, $first, $firstName, 'Ada');
        $this->assertOrganization($I, $second, $secondName, 'Grace');

        $I->sendGET('/api/v1/companies');
        $I->seeResponseCodeIs(HttpCode::OK);
        /** @var array{data: list<array{id: string}>} $companies */
        $companies = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        $before = array_column($companies['data'], 'id');

        $I->sendPOST('/api/v1/onboarding', $this->payload($fakePi, $firstName, 'Ada', $first['companyId']));
        $I->seeResponseCodeIs(HttpCode::OK);
        $repeatedTask = $I->grabDataFromResponseByJsonPath('$.data.task.id')[0];
        $repeatedAgent = $I->grabDataFromResponseByJsonPath('$.data.agent.id')[0];
        assertSame($first['taskId'], $repeatedTask);
        assertSame($first['agentId'], $repeatedAgent);

        $I->sendGET('/api/v1/companies');
        /** @var array{data: list<array{id: string}>} $companiesAfter */
        $companiesAfter = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        assertSame($before, array_column($companiesAfter['data'], 'id'));
        $this->assertSingleFirstTask($I, $first['companyId'], $first['taskId']);

        $I->haveHttpHeader('Authorization', 'Bearer ' . $this->runToken($first['companyId'], $first['agentId'], $first['taskId']));
        $I->sendPOST('/api/v1/companies/' . $second['companyId'] . '/agents', [
            'name' => 'Should Not Land',
            'title' => 'Engineer',
            'piBinary' => $fakePi,
            'piProvider' => 'anthropic',
            'piModel' => 'claude-haiku-4-5',
            'piThinking' => 'off',
        ]);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);

        $I->haveHttpHeader('Authorization', 'Bearer ' . $this->runToken($first['companyId'], $first['agentId'], $first['taskId']));
        $I->sendPOST('/api/v1/companies/' . $first['companyId'] . '/agents', [
            'name' => 'Riley',
            'title' => 'Engineer',
            'jobDescription' => 'Builds the product.',
            'piBinary' => $fakePi,
            'piProvider' => 'anthropic',
            'piModel' => 'claude-haiku-4-5',
            'piThinking' => 'low',
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $reportId = $I->grabDataFromResponseByJsonPath('$.data.id')[0];

        $I->haveHttpHeader('Authorization', 'Bearer ' . $token);
        $I->sendGET('/api/v1/companies/' . $first['companyId'] . '/agents/' . $reportId);
        $I->seeResponseCodeIs(HttpCode::OK);
        /** @var array{data: array{name: string, companyId: string, managerId: string|null}} $report */
        $report = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        assertSame('Riley', $report['data']['name']);
        assertSame($first['companyId'], $report['data']['companyId']);
        assertSame($first['agentId'], $report['data']['managerId']);

        $I->sendGET('/api/v1/companies/' . $first['companyId'] . '/agents');
        /** @var array{data: list<array{id: string}>} $agents */
        $agents = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        assertCount(2, $agents['data']);

        $I->haveHttpHeader('Authorization', 'Bearer ' . $this->runToken($first['companyId'], $reportId, null));
        $I->sendPOST('/api/v1/companies/' . $first['companyId'] . '/agents', [
            'name' => 'Rejected',
            'title' => 'Engineer',
            'piBinary' => $fakePi,
        ]);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseContainsJson(['error_data' => ['code' => 'forbidden']]);

        $I->haveHttpHeader('Authorization', 'Bearer ' . $token);
        $I->sendGET('/api/v1/companies/' . $first['companyId'] . '/agents');
        /** @var array{data: list<array{id: string}>} $still */
        $still = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        assertCount(2, $still['data']);
        $I->sendGET('/api/v1/companies/' . $second['companyId'] . '/agents');
        /** @var array{data: list<mixed>} $secondAgents */
        $secondAgents = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        assertCount(1, $secondAgents['data']);
        $this->assertSingleFirstTask($I, $first['companyId'], $first['taskId']);
    }

    public function answeringTheOpeningQuestionRecordsTheChoiceAndWakesTheAgent(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/v1/session', ['email' => 'owner@holder.test', 'password' => 'secret-pass']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $token = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $I->haveHttpHeader('Authorization', 'Bearer ' . $token);

        $created = $this->complete($I, dirname(__DIR__) . '/fixtures/fake-pi.php', 'Opening ' . uniqid(), 'Ada');
        $taskPath = '/api/v1/companies/' . $created['companyId'] . '/tasks/' . $created['taskId'];
        assertSame(0, $this->pendingWakeups($created['agentId'], $created['taskId']));

        $I->sendPOST($taskPath . '/opening-answer', ['optionId' => 'nope']);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->seeResponseContainsJson(['error_data' => ['code' => 'invalid_option']]);

        $I->sendPOST($taskPath . '/opening-answer', ['optionId' => 'task', 'text' => '   ']);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->seeResponseContainsJson(['error_data' => ['code' => 'missing_field']]);

        $I->sendGET($taskPath);
        $I->seeResponseCodeIs(HttpCode::OK);
        /** @var array{data: array{openingQuestion: array{prompt: string}, comments: list<mixed>}} $stillOpen */
        $stillOpen = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        assertSame('What would you like to do?', $stillOpen['data']['openingQuestion']['prompt']);
        assertCount(1, $stillOpen['data']['comments']);

        $I->sendPOST($taskPath . '/opening-answer', ['optionId' => 'task', 'text' => 'Ship invoices.']);
        $I->seeResponseCodeIs(HttpCode::OK);
        /** @var array{data: array{openingQuestion: mixed, comments: list<array{authorType: string, body: string}>}} $answered */
        $answered = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        assertSame(null, $answered['data']['openingQuestion']);
        assertCount(2, $answered['data']['comments']);
        assertSame('user', $answered['data']['comments'][1]['authorType']);
        assertSame(
            "What would you like to do?\nI have a task in mind\nShip invoices.",
            $answered['data']['comments'][1]['body'],
        );
        assertSame(1, $this->pendingWakeups($created['agentId'], $created['taskId']));

        $I->sendPOST($taskPath . '/opening-answer', ['optionId' => 'interview']);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->seeResponseContainsJson(['error_data' => ['code' => 'no_opening_question']]);

        $interview = $this->complete($I, dirname(__DIR__) . '/fixtures/fake-pi.php', 'Interview ' . uniqid(), 'Grace');
        $interviewPath = '/api/v1/companies/' . $interview['companyId'] . '/tasks/' . $interview['taskId'];
        $I->sendPOST($interviewPath . '/opening-answer', ['optionId' => 'interview']);
        $I->seeResponseCodeIs(HttpCode::OK);
        /** @var array{data: array{comments: list<array{body: string}>}} $interviewed */
        $interviewed = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        assertSame(
            "What would you like to do?\nInterview me and propose a plan and an agent team to execute it.",
            $interviewed['data']['comments'][1]['body'],
        );
        assertSame(1, $this->pendingWakeups($interview['agentId'], $interview['taskId']));
        $this->clearPendingWakeup($created['agentId'], $created['taskId']);
        $this->clearPendingWakeup($interview['agentId'], $interview['taskId']);
    }

    public function agentQuestionsShowAsASelectAndTheAnswerWakesTheAgent(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/v1/session', ['email' => 'owner@holder.test', 'password' => 'secret-pass']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $token = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $I->haveHttpHeader('Authorization', 'Bearer ' . $token);

        $created = $this->complete($I, dirname(__DIR__) . '/fixtures/fake-pi.php', 'Questions ' . uniqid(), 'Ada');
        $taskPath = '/api/v1/companies/' . $created['companyId'] . '/tasks/' . $created['taskId'];
        $run = $this->runToken($created['companyId'], $created['agentId'], $created['taskId']);

        $I->haveHttpHeader('Authorization', 'Bearer ' . $run);
        $I->sendPOST('/api/v1/agent/tasks/' . $created['taskId'] . '/comments', [
            'body' => <<<'TEXT'
Answer these four questions so I can propose a plan and a team. I will not hire anyone until you approve that plan.

1. What does this organization do, and who is it for?
2. What should we achieve first?
TEXT,
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);

        $I->haveHttpHeader('Authorization', 'Bearer ' . $token);
        $I->sendGET($taskPath);
        $I->seeResponseCodeIs(HttpCode::OK);
        /** @var array{data: array{agentQuestions: array{commentId: string, commentBody: string, questions: list<array{id: string, prompt: string, options: list<mixed>}>}}} $listed */
        $listed = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        $card = $listed['data']['agentQuestions'];
        assertSame('Answer these four questions so I can propose a plan and a team. I will not hire anyone until you approve that plan.', $card['commentBody']);
        assertCount(2, $card['questions']);
        assertSame([], $card['questions'][0]['options']);
        assertSame(0, $this->pendingWakeups($created['agentId'], $created['taskId']));

        $I->sendPOST($taskPath . '/question-answer', [
            'commentId' => $card['commentId'],
            'answers' => [
                ['id' => 'q1', 'optionId' => '', 'text' => '   '],
                ['id' => 'q2', 'optionId' => '', 'text' => 'Ship a site.'],
            ],
        ]);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->seeResponseContainsJson(['error_data' => ['code' => 'missing_field']]);

        $I->sendPOST($taskPath . '/question-answer', [
            'commentId' => $card['commentId'],
            'answers' => [
                ['id' => 'q1', 'optionId' => '', 'text' => 'A studio for local shops.'],
                ['id' => 'q2', 'optionId' => '', 'text' => 'Ship a site.'],
            ],
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        /** @var array{data: array{agentQuestions: mixed, comments: list<array{authorType: string, body: string}>}} $answered */
        $answered = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        assertSame(null, $answered['data']['agentQuestions']);
        assertSame('user', $answered['data']['comments'][array_key_last($answered['data']['comments'])]['authorType']);
        assertSame(
            "What does this organization do, and who is it for?\nA studio for local shops.\n\nWhat should we achieve first?\nShip a site.",
            $answered['data']['comments'][array_key_last($answered['data']['comments'])]['body'],
        );
        assertSame(1, $this->pendingWakeups($created['agentId'], $created['taskId']));

        $I->haveHttpHeader('Authorization', 'Bearer ' . $run);
        $I->sendPOST('/api/v1/agent/tasks/' . $created['taskId'] . '/questions', [
            'questions' => [[
                'id' => 'first',
                'prompt' => 'What should we achieve first?',
                'options' => [
                    ['id' => 'site', 'label' => 'A one-page site', 'description' => 'Ship a public page.'],
                    ['id' => 'plan', 'label' => 'A written plan', 'description' => 'Agree the work before building.'],
                ],
            ]],
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);

        $I->haveHttpHeader('Authorization', 'Bearer ' . $token);
        $I->sendGET($taskPath);
        /** @var array{data: array{agentQuestions: array{commentId: mixed, questions: list<array{options: list<array{id: string, label: string}>}>}}} $select */
        $select = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        assertSame(null, $select['data']['agentQuestions']['commentId']);
        assertSame('site', $select['data']['agentQuestions']['questions'][0]['options'][0]['id']);
        assertSame('A one-page site', $select['data']['agentQuestions']['questions'][0]['options'][0]['label']);

        $I->sendPOST($taskPath . '/question-answer', [
            'commentId' => null,
            'answers' => [['id' => 'first', 'optionId' => 'nope', 'text' => '']],
        ]);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->seeResponseContainsJson(['error_data' => ['code' => 'invalid_option']]);

        $I->sendPOST($taskPath . '/question-answer', [
            'commentId' => null,
            'answers' => [['id' => 'first', 'optionId' => 'site', 'text' => '']],
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        /** @var array{data: array{agentQuestions: mixed, comments: list<array{body: string}>}} $chosen */
        $chosen = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        assertSame(null, $chosen['data']['agentQuestions']);
        assertSame(
            "What should we achieve first?\nA one-page site",
            $chosen['data']['comments'][array_key_last($chosen['data']['comments'])]['body'],
        );

        $this->clearPendingWakeup($created['agentId'], $created['taskId']);
    }

    private function pendingWakeups(string $agentId, string $taskId): int
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
        $statement = $pdo->prepare(
            "SELECT COUNT(*) FROM wakeups WHERE agent_id = :agent_id AND task_id = :task_id AND status = 'pending'",
        );
        $statement->execute(['agent_id' => $agentId, 'task_id' => $taskId]);

        return (int) $statement->fetchColumn();
    }

    private function clearPendingWakeup(string $agentId, string $taskId): void
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
        $statement = $pdo->prepare(
            "DELETE FROM wakeups WHERE agent_id = :agent_id AND task_id = :task_id AND status = 'pending'",
        );
        $statement->execute(['agent_id' => $agentId, 'task_id' => $taskId]);
    }

    /**
     * @return array{companyId: string, agentId: string, projectId: string, taskId: string}
     */
    private function complete(ApiTester $I, string $fakePi, string $organization, string $agentName): array
    {
        $I->sendPOST('/api/v1/onboarding', $this->payload($fakePi, $organization, $agentName, ''));
        $I->seeResponseCodeIs(HttpCode::OK);
        $companyId = $I->grabDataFromResponseByJsonPath('$.data.company.id')[0];
        $agentId = $I->grabDataFromResponseByJsonPath('$.data.agent.id')[0];
        $projectId = $I->grabDataFromResponseByJsonPath('$.data.project.id')[0];
        $taskId = $I->grabDataFromResponseByJsonPath('$.data.task.id')[0];

        return [
            'companyId' => $companyId,
            'agentId' => $agentId,
            'projectId' => $projectId,
            'taskId' => $taskId,
        ];
    }

    /**
     * @param array{companyId: string, agentId: string, projectId: string, taskId: string} $created
     */
    private function assertOrganization(ApiTester $I, array $created, string $organization, string $agentName): void
    {
        $I->sendGET('/api/v1/companies/' . $created['companyId']);
        $I->seeResponseCodeIs(HttpCode::OK);
        /** @var array{data: array{name: string, mission: string}} $company */
        $company = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        assertSame($organization, $company['data']['name']);
        assertSame('', $company['data']['mission']);

        $I->sendGET('/api/v1/companies/' . $created['companyId'] . '/goals');
        $I->seeResponseCodeIs(HttpCode::OK);
        /** @var array{data: list<mixed>} $goals */
        $goals = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        assertCount(0, $goals['data']);

        $I->sendGET('/api/v1/companies/' . $created['companyId'] . '/agents');
        $I->seeResponseCodeIs(HttpCode::OK);
        /** @var array{data: list<array<string, mixed>>} $agents */
        $agents = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        assertCount(1, $agents['data']);

        $I->sendGET('/api/v1/companies/' . $created['companyId'] . '/agents/' . $created['agentId']);
        $I->seeResponseCodeIs(HttpCode::OK);
        /** @var array{data: array<string, mixed>} $agent */
        $agent = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        assertSame($agentName, $agent['data']['name']);
        assertSame($created['companyId'], $agent['data']['companyId']);
        assertSame('Chief of Staff', $agent['data']['title']);
        assertSame(null, $agent['data']['managerId']);
        assertSame('anthropic', $agent['data']['piProvider']);
        assertSame('claude-haiku-4-5', $agent['data']['piModel']);
        assertSame('off', $agent['data']['piThinking']);
        assertSame('fake-0.0.1', $agent['data']['piVersion']);
        assertStringContainsString(
            'You are ' . $agentName . ', chief of staff for ' . $organization . '.',
            (string) $agent['data']['jobDescription'],
        );

        $I->sendGET('/api/v1/companies/' . $created['companyId'] . '/projects');
        $I->seeResponseCodeIs(HttpCode::OK);
        /** @var array{data: list<array{id: string, name: string}>} $projects */
        $projects = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        assertCount(1, $projects['data']);
        assertSame($created['projectId'], $projects['data'][0]['id']);
        assertSame('Onboarding', $projects['data'][0]['name']);

        $this->assertSingleFirstTask($I, $created['companyId'], $created['taskId']);
        $I->sendGET('/api/v1/companies/' . $created['companyId'] . '/tasks/' . $created['taskId']);
        $I->seeResponseCodeIs(HttpCode::OK);
        /** @var array{data: array<string, mixed>} $task */
        $task = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        assertSame('Paperclip onboarding', $task['data']['title']);
        assertSame('todo', $task['data']['status']);
        assertSame(self::DESCRIPTION, $task['data']['description']);
        assertSame($created['agentId'], $task['data']['assigneeAgentId']);
        assertSame($created['projectId'], $task['data']['projectId']);
        assertSame(null, $task['data']['checkoutRunId']);
        assertSame([], $task['data']['runs']);

        $question = $task['data']['openingQuestion'];
        assertTrue(is_array($question));
        assertSame('What would you like to do?', $question['prompt']);
        assertSame('Continue', $question['submitLabel']);
        assertCount(2, $question['options']);
        assertSame('interview', $question['options'][0]['id']);
        assertSame('Interview me and propose a plan and an agent team to execute it.', $question['options'][0]['label']);
        assertSame(
            "A few questions about what you're building, then a short plan and the team to carry it out, for you to approve.",
            $question['options'][0]['description'],
        );
        assertFalse($question['options'][0]['freeText']);
        assertSame('task', $question['options'][1]['id']);
        assertSame('I have a task in mind', $question['options'][1]['label']);
        assertSame("Describe it and I'll propose how to get it done.", $question['options'][1]['description']);
        assertTrue($question['options'][1]['freeText']);

        $comments = $task['data']['comments'];
        assertTrue(is_array($comments));
        assertCount(1, $comments);
        assertSame('agent', $comments[0]['authorType']);
        assertSame($created['agentId'], $comments[0]['authorId']);
        assertSame(
            "Welcome to Paperclip! I'm " . $agentName . ", your first agent teammate. Pick how you'd like to start and I'll take it from there.",
            $comments[0]['body'],
        );
    }

    private function assertSingleFirstTask(ApiTester $I, string $companyId, string $taskId): void
    {
        $I->sendGET('/api/v1/companies/' . $companyId . '/tasks');
        $I->seeResponseCodeIs(HttpCode::OK);
        /** @var array{data: list<array{id: string, title: string}>} $tasks */
        $tasks = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        assertCount(1, $tasks['data']);
        assertSame($taskId, $tasks['data'][0]['id']);
        assertSame('Paperclip onboarding', $tasks['data'][0]['title']);
    }

    /**
     * @return array<string, string>
     */
    private function payload(string $fakePi, string $organization, string $agentName, string $companyId): array
    {
        return [
            'name' => $organization,
            'companyId' => $companyId,
            'agentName' => $agentName,
            'piBinary' => $fakePi,
            'piProvider' => 'anthropic',
            'piModel' => 'claude-haiku-4-5',
            'piThinking' => 'off',
        ];
    }

    private function runToken(string $companyId, string $agentId, ?string $taskId): string
    {
        $config = new HolderConfig(
            mode: Env::get('HOLDER_MODE', 'local'),
            secretsKey: Env::get('HOLDER_SECRETS_KEY', 'dev-only-change-me'),
            apiUrl: rtrim(Env::get('HOLDER_API_URL', 'http://127.0.0.1:8080'), '/'),
            dataDir: Env::get('HOLDER_DATA_DIR', dirname(__DIR__, 2) . '/runtime/holder-data'),
            binPath: Env::get('HOLDER_BIN', dirname(__DIR__, 3) . '/bin/holder'),
            runTimeoutSeconds: (int) Env::get('HOLDER_RUN_TIMEOUT', '600'),
            runLimitSeconds: (int) Env::get('HOLDER_RUN_LIMIT', '3600'),
        );

        return (new RunToken($config))->issue($companyId, $agentId, Ids::uuid(), $taskId);
    }
}
