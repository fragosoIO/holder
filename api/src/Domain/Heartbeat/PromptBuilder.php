<?php

declare(strict_types=1);

namespace App\Domain\Heartbeat;

final class PromptBuilder
{
    /**
     * @param array<string, mixed> $company
     * @param list<array<string, mixed>> $goalChain
     * @param array<string, mixed> $task
     * @param list<array<string, mixed>> $comments
     * @param array<string, mixed> $agent
     * @param list<array<string, mixed>> $reports
     */
    public function build(
        array $company,
        array $goalChain,
        array $task,
        array $comments,
        array $agent,
        ?int $budgetRemainingCents,
        string $wakeReason = '',
        array $reports = [],
    ): string {
        $lines = [];
        $lines[] = sprintf(
            'You are %s, %s.',
            (string) $agent['name'],
            (string) ($agent['title'] !== '' ? $agent['title'] : 'an agent'),
        );
        if ((string) $agent['job_description'] !== '') {
            $lines[] = 'Job: ' . $agent['job_description'];
        }
        $lines[] = 'Company: ' . $company['name'];
        $lines[] = 'Mission: ' . ($company['mission'] !== '' ? $company['mission'] : '(none set)');
        $lines[] = 'Why this task exists:';
        if ($goalChain === []) {
            $lines[] = '- (no goal linked)';
        }
        foreach ($goalChain as $goal) {
            $lines[] = '- ' . $goal['title'] . ($goal['description'] !== '' ? ': ' . $goal['description'] : '');
        }
        $lines[] = 'Task ' . $task['id'] . ': ' . $task['title'];
        $lines[] = 'Status: ' . $task['status'];
        if ($wakeReason !== '') {
            $lines[] = 'Wake reason: ' . $wakeReason;
        }
        if ($wakeReason === 'review') {
            $lines[] = 'A person who reports to you finished a subtask. Read their comment below. Check the work before you assign anything else. If it is good, continue this task or mark it done. If it is not, comment what is wrong and assign it again.';
        }
        $blockers = $task['blockers'] ?? [];
        if (is_array($blockers) && $blockers !== []) {
            $lines[] = 'Blockers:';
            foreach ($blockers as $blocker) {
                if (!is_array($blocker)) {
                    continue;
                }
                $lines[] = '- ' . (string) ($blocker['title'] ?? '') . ' (' . (string) ($blocker['status'] ?? '') . ')';
            }
        }
        $lines[] = 'Description:';
        $lines[] = (string) ($task['description'] !== '' ? $task['description'] : '(empty)');
        $lines[] = 'Comments:';
        if ($comments === []) {
            $lines[] = '- (none)';
        }
        foreach ($comments as $comment) {
            $lines[] = sprintf('- [%s] %s', $comment['author_type'], $comment['body']);
        }
        $lines[] = 'Budget remaining cents: ' . ($budgetRemainingCents === null ? 'unlimited' : (string) $budgetRemainingCents);
        if ($reports !== []) {
            $lines[] = 'People who report to you:';
            foreach ($reports as $report) {
                if (!is_array($report)) {
                    continue;
                }
                $title = trim((string) ($report['title'] ?? ''));
                $job = trim((string) ($report['job_description'] ?? ''));
                $lines[] = sprintf(
                    '- %s %s, %s. Job: %s',
                    (string) ($report['id'] ?? ''),
                    (string) ($report['name'] ?? ''),
                    $title !== '' ? $title : 'an agent',
                    $job !== '' ? $job : '(none)',
                );
            }
            if ($wakeReason === 'review') {
                $lines[] = 'You can hand a follow-up to one of them with:';
            } else {
                $lines[] = 'Before you do this task, check whether one of these agents is more appropriate for it than you are. Compare the task with each job. If one of them should do it, assign it to that agent and stop. Assigning creates a subtask for them. This task stays with you, and you are woken to check their work when they finish. Do not do their work. If you are more appropriate, do the task yourself. If you already decided this task is yours, keep it.';
            }
            $lines[] = 'holder assign --task ' . $task['id'] . ' --agent AGENT_ID';
            $lines[] = 'You can only assign to an agent listed above.';
        }
        $lines[] = 'Record progress with the holder command, which is on PATH:';
        $lines[] = 'holder comment --task ' . $task['id'] . ' --body "what you did"';
        $lines[] = 'holder status --task ' . $task['id'] . ' --status in_progress';
        $lines[] = 'When you cannot continue until another task is done, name it and stop:';
        $lines[] = 'holder status --task ' . $task['id'] . ' --status blocked --blocked-by OTHER_TASK_ID';
        $lines[] = 'Holder sets this task back to todo and wakes you when every named blocker is done. A cancelled blocker does not count. Repeat --blocked-by once per blocker.';
        $parentId = $task['parent_id'] ?? null;
        if (is_string($parentId) && $parentId !== '') {
            $lines[] = 'This task was assigned to you by another agent. When you finish, comment what you did and mark it done. That comment is your response. The agent who assigned you will check your work.';
            $lines[] = 'holder status --task ' . $task['id'] . ' --status done';
        }
        $lines[] = 'When you need the user to choose or answer, post a question card. Do not write the questions as a numbered list in a comment. Each question renders as a select, the same control as the onboarding opening question.';
        $lines[] = 'holder questions --task ' . $task['id'] . ' --body \'{"questions":[{"id":"first","prompt":"What should we achieve first?","options":[{"id":"site","label":"A one-page site","description":"Ship a public page."},{"id":"plan","label":"A written plan","description":"Agree the work before building."}]}]}\'';
        $lines[] = 'Give each real choice a label and a short description. Omit options for an open answer; the card shows a text field. Then set status in_review and stop until they answer.';
        $lines[] = $reports === []
            ? 'HOLDER_RUN_TOKEN and HOLDER_API_URL are already set. Do the task in this workspace.'
            : 'HOLDER_RUN_TOKEN and HOLDER_API_URL are already set. If you keep this task, do it in this workspace. If you assign it, stop.';

        return implode("\n", $lines);
    }
}
