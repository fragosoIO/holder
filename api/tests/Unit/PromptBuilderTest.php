<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Heartbeat\PromptBuilder;
use Codeception\Test\Unit;

final class PromptBuilderTest extends Unit
{
    public function testPromptCarriesMissionGoalAndTask(): void
    {
        $prompt = (new PromptBuilder())->build(
            ['name' => 'Acme', 'mission' => 'Ship Holder'],
            [
                ['title' => 'Company', 'description' => 'The whole firm'],
                ['title' => 'Product', 'description' => 'The app'],
            ],
            [
                'id' => 'task-1',
                'title' => 'Health check',
                'description' => 'Return ok',
                'status' => 'todo',
                'blockers' => [['title' => 'Design', 'status' => 'done']],
            ],
            [['author_type' => 'user', 'body' => 'Start here']],
            ['name' => 'Ada', 'title' => 'Engineer', 'job_description' => 'Builds'],
            null,
            'blockers_resolved',
        );

        $this->assertStringContainsString('Mission: Ship Holder', $prompt);
        $this->assertStringContainsString('Company: The whole firm', $prompt);
        $this->assertStringContainsString('Product: The app', $prompt);
        $this->assertStringContainsString('Task task-1: Health check', $prompt);
        $this->assertStringContainsString('Wake reason: blockers_resolved', $prompt);
        $this->assertStringContainsString('- Design (done)', $prompt);
        $this->assertStringContainsString('[user] Start here', $prompt);
        $this->assertStringContainsString('holder comment --task task-1', $prompt);
        $this->assertStringContainsString('holder status --task task-1 --status blocked --blocked-by OTHER_TASK_ID', $prompt);
        $this->assertStringContainsString('holder questions --task task-1', $prompt);
        $this->assertStringContainsString('unlimited', $prompt);
        $this->assertStringNotContainsString('People who report to you:', $prompt);
        $this->assertStringNotContainsString('holder assign --task task-1', $prompt);
    }

    public function testPromptTellsAManagerToCheckDirectReports(): void
    {
        $prompt = (new PromptBuilder())->build(
            ['name' => 'Acme', 'mission' => 'Ship Holder'],
            [],
            [
                'id' => 'task-1',
                'title' => 'Dark mode',
                'description' => 'Add dark mode to the apple website',
                'status' => 'todo',
            ],
            [],
            ['name' => 'Casey', 'title' => 'Chief of Staff', 'job_description' => 'Coordinates the company'],
            null,
            'assignment',
            [
                [
                    'id' => 'builder-1',
                    'name' => 'Blair',
                    'title' => 'Frontend builder',
                    'job_description' => 'You build the public page from an approved design.',
                ],
            ],
        );

        $this->assertStringContainsString('People who report to you:', $prompt);
        $this->assertStringContainsString(
            '- builder-1 Blair, Frontend builder. Job: You build the public page from an approved design.',
            $prompt,
        );
        $this->assertStringContainsString(
            'Before you do this task, check whether one of these agents is more appropriate for it than you are.',
            $prompt,
        );
        $this->assertStringContainsString('holder assign --task task-1 --agent AGENT_ID', $prompt);
        $this->assertStringContainsString('You can only assign to an agent listed above.', $prompt);
        $this->assertStringContainsString('Assigning creates a subtask for them.', $prompt);
        $this->assertStringContainsString('This task stays with you, and you are woken to check their work when they finish.', $prompt);
        $this->assertStringContainsString('If you keep this task, do it in this workspace.', $prompt);
    }

    public function testPromptTellsAnAssigneeToRespondOnASubtask(): void
    {
        $prompt = (new PromptBuilder())->build(
            ['name' => 'Acme', 'mission' => 'Ship Holder'],
            [],
            [
                'id' => 'task-1',
                'parent_id' => 'parent-1',
                'title' => 'Dark mode',
                'description' => 'Add dark mode to the apple website',
                'status' => 'todo',
            ],
            [],
            ['name' => 'Blair', 'title' => 'Frontend builder', 'job_description' => 'Builds the page'],
            null,
            'assignment',
        );

        $this->assertStringContainsString('This task was assigned to you by another agent.', $prompt);
        $this->assertStringContainsString('That comment is your response.', $prompt);
        $this->assertStringContainsString('holder status --task task-1 --status done', $prompt);
        $this->assertStringNotContainsString('People who report to you:', $prompt);
    }

    public function testPromptTellsTheOwnerToCheckAFinishedSubtask(): void
    {
        $prompt = (new PromptBuilder())->build(
            ['name' => 'Acme', 'mission' => 'Ship Holder'],
            [],
            [
                'id' => 'task-1',
                'title' => 'Dark mode',
                'description' => 'Add dark mode to the apple website',
                'status' => 'todo',
                'blockers' => [['title' => 'Dark mode', 'status' => 'done']],
            ],
            [['author_type' => 'agent', 'body' => "Blair finished the subtask \"Dark mode\".\n\nDark mode is in the stylesheet."]],
            ['name' => 'Casey', 'title' => 'Chief of Staff', 'job_description' => 'Coordinates the company'],
            null,
            'review',
            [
                [
                    'id' => 'builder-1',
                    'name' => 'Blair',
                    'title' => 'Frontend builder',
                    'job_description' => 'You build the public page from an approved design.',
                ],
            ],
        );

        $this->assertStringContainsString('Wake reason: review', $prompt);
        $this->assertStringContainsString('Check the work before you assign anything else.', $prompt);
        $this->assertStringContainsString('Dark mode is in the stylesheet.', $prompt);
        $this->assertStringContainsString('holder assign --task task-1 --agent AGENT_ID', $prompt);
        $this->assertStringNotContainsString('Before you do this task, check whether one of these agents is more appropriate', $prompt);
    }

    public function testRepositoryPromptTellsTheAgentToOpenAPullRequest(): void
    {
        $prompt = (new PromptBuilder())->build(
            ['name' => 'Acme', 'mission' => 'Ship Holder'],
            [
                ['title' => 'Company', 'description' => 'The whole firm'],
                ['title' => 'Product', 'description' => 'The app'],
            ],
            [
                'id' => 'task-1',
                'title' => 'Health check',
                'description' => 'Return ok',
                'status' => 'todo',
                'blockers' => [['title' => 'Design', 'status' => 'done']],
            ],
            [['author_type' => 'user', 'body' => 'Start here']],
            ['name' => 'Ada', 'title' => 'Engineer', 'job_description' => 'Builds'],
            null,
            'blockers_resolved',
            [],
            [
                'repoUrl' => 'https://github.com/Acme/Widget',
                'branch' => 'holder/task-1',
                'defaultBranch' => 'main',
                'reviewBranches' => [],
            ],
        );

        $this->assertStringContainsString('Repository: https://github.com/Acme/Widget', $prompt);
        $this->assertStringContainsString('holder/task-1', $prompt);
        $this->assertStringContainsString('git push -u origin holder/task-1', $prompt);
        $this->assertStringContainsString('gh pr create', $prompt);
        $this->assertStringContainsString('main as the base', $prompt);
        $this->assertStringContainsString('Do not push main', $prompt);
        $this->assertStringContainsString('Do not force-push', $prompt);
        $this->assertStringContainsString('gh pr view', $prompt);
        $this->assertStringContainsString('Do not print GH_TOKEN or GITHUB_TOKEN', $prompt);
        $this->assertStringContainsString('Health check as the pull request title', $prompt);
        $this->assertStringNotContainsString('ghp_', $prompt);
    }

    public function testReviewPromptNamesChildBranches(): void
    {
        $prompt = (new PromptBuilder())->build(
            ['name' => 'Acme', 'mission' => 'Ship Holder'],
            [
                ['title' => 'Company', 'description' => 'The whole firm'],
                ['title' => 'Product', 'description' => 'The app'],
            ],
            [
                'id' => 'task-1',
                'title' => 'Health check',
                'description' => 'Return ok',
                'status' => 'todo',
                'blockers' => [['title' => 'Design', 'status' => 'done']],
            ],
            [['author_type' => 'user', 'body' => 'Start here']],
            ['name' => 'Ada', 'title' => 'Engineer', 'job_description' => 'Builds'],
            null,
            'review',
            [],
            [
                'repoUrl' => 'https://github.com/Acme/Widget',
                'branch' => 'holder/task-1',
                'defaultBranch' => 'main',
                'reviewBranches' => ['holder/child-1'],
            ],
        );

        $this->assertStringContainsString('holder/child-1', $prompt);
        $this->assertStringContainsString('Do not open a new pull request', $prompt);
        $this->assertStringContainsString('Do not print GH_TOKEN', $prompt);
        $this->assertStringNotContainsString('gh pr create', $prompt);
    }

    public function testACompanyFolderPromptIsUnchanged(): void
    {
        $prompt = (new PromptBuilder())->build(
            ['name' => 'Acme', 'mission' => 'Ship Holder'],
            [
                ['title' => 'Company', 'description' => 'The whole firm'],
                ['title' => 'Product', 'description' => 'The app'],
            ],
            [
                'id' => 'task-1',
                'title' => 'Health check',
                'description' => 'Return ok',
                'status' => 'todo',
                'blockers' => [['title' => 'Design', 'status' => 'done']],
            ],
            [['author_type' => 'user', 'body' => 'Start here']],
            ['name' => 'Ada', 'title' => 'Engineer', 'job_description' => 'Builds'],
            null,
            'blockers_resolved',
        );

        $this->assertStringNotContainsString('gh pr create', $prompt);
    }
}
