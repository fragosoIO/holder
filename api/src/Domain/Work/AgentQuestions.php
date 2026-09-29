<?php

declare(strict_types=1);

namespace App\Domain\Work;

use App\Domain\HolderException;

/**
 * Question cards an agent asks on a task. They use the same select as the onboarding opening question.
 */
final class AgentQuestions
{
    /**
     * @param array<string, mixed>|null $stored
     * @param list<array<string, mixed>> $comments
     * @return array<string, mixed>|null
     */
    public function open(?array $stored, array $comments): ?array
    {
        if (is_array($stored)) {
            try {
                $normalized = $this->normalize($stored);

                return $this->card(null, $normalized['intro'], '', $normalized['questions']);
            } catch (HolderException) {
                // A corrupt card falls through to a question list still sitting in the thread.
            }
        }

        $lastUser = -1;
        foreach ($comments as $index => $comment) {
            if ((string) ($comment['author_type'] ?? '') === 'user') {
                $lastUser = $index;
            }
        }

        $found = null;
        foreach ($comments as $index => $comment) {
            if ($index <= $lastUser || (string) ($comment['author_type'] ?? '') !== 'agent') {
                continue;
            }
            $parsed = $this->fromComment((string) ($comment['body'] ?? ''));
            if ($parsed === null) {
                continue;
            }
            $found = $this->card(
                (string) ($comment['id'] ?? ''),
                '',
                $parsed['commentBody'],
                $parsed['questions'],
            );
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{intro: string, questions: list<array{id: string, prompt: string, options: list<array{id: string, label: string, description: string, freeText: bool}>}>}
     */
    public function normalize(array $input): array
    {
        $intro = trim((string) ($input['intro'] ?? ''));
        if (mb_strlen($intro) > 2000) {
            throw new HolderException('invalid_question', 'The question intro is too long.', 422);
        }
        $questions = $input['questions'] ?? null;
        if (!is_array($questions) || $questions === [] || count($questions) > 8) {
            throw new HolderException('invalid_question', 'Ask between 1 and 8 questions.', 422);
        }

        $normalized = [];
        $seen = [];
        foreach ($questions as $question) {
            if (!is_array($question)) {
                throw new HolderException('invalid_question', 'Each question needs a prompt.', 422);
            }
            $id = trim((string) ($question['id'] ?? ''));
            $prompt = trim((string) ($question['prompt'] ?? ''));
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,40}$/', $id) !== 1 || isset($seen[$id])) {
                throw new HolderException('invalid_question', 'Each question needs its own id.', 422);
            }
            if ($prompt === '' || str_contains($prompt, "\n") || mb_strlen($prompt) > 1000) {
                throw new HolderException('invalid_question', 'Each question needs a single-line prompt.', 422);
            }
            $seen[$id] = true;
            $normalized[] = [
                'id' => $id,
                'prompt' => $prompt,
                'options' => $this->options($question['options'] ?? []),
            ];
        }

        return ['intro' => $intro, 'questions' => $normalized];
    }

    /**
     * @param array<string, mixed> $card
     * @param list<mixed> $answers
     */
    public function render(array $card, array $answers): string
    {
        $questions = $card['questions'] ?? null;
        if (!is_array($questions)) {
            throw new HolderException('no_agent_questions', 'This task has no open questions.', 422);
        }

        $byId = [];
        foreach ($answers as $answer) {
            if (!is_array($answer)) {
                throw new HolderException('invalid_option', 'Answer each question.', 422);
            }
            $id = trim((string) ($answer['id'] ?? ''));
            if ($id === '' || isset($byId[$id])) {
                throw new HolderException('invalid_option', 'Answer each question once.', 422);
            }
            $byId[$id] = $answer;
        }
        if (count($byId) !== count($questions)) {
            throw new HolderException('invalid_option', 'Answer each question.', 422);
        }

        $blocks = [];
        foreach ($questions as $question) {
            if (!is_array($question)) {
                throw new HolderException('no_agent_questions', 'This task has no open questions.', 422);
            }
            $id = (string) ($question['id'] ?? '');
            if (!isset($byId[$id])) {
                throw new HolderException('invalid_option', 'Answer each question.', 422);
            }
            $blocks[] = $this->block($question, $byId[$id]);
        }

        return implode("\n\n", $blocks);
    }

    /**
     * @param array<string, mixed> $question
     * @param array<string, mixed> $answer
     */
    private function block(array $question, array $answer): string
    {
        $prompt = trim((string) ($question['prompt'] ?? ''));
        $text = trim((string) ($answer['text'] ?? ''));
        $optionId = trim((string) ($answer['optionId'] ?? ''));
        $options = $question['options'] ?? [];
        if (!is_array($options) || $options === []) {
            if ($text === '') {
                throw new HolderException('missing_field', 'Answer the question.', 422);
            }

            return $prompt . "\n" . $text;
        }

        $chosen = null;
        foreach ($options as $option) {
            if (is_array($option) && (string) ($option['id'] ?? '') === $optionId && $optionId !== '') {
                $chosen = $option;
                break;
            }
        }
        if ($chosen === null) {
            throw new HolderException('invalid_option', 'Choose one of the options.', 422);
        }
        $lines = [$prompt, trim((string) ($chosen['label'] ?? ''))];
        if (($chosen['freeText'] ?? false) === true) {
            if ($text === '') {
                throw new HolderException('missing_field', 'Describe your answer.', 422);
            }
            $lines[] = $text;
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<array{id: string, label: string, description: string, freeText: bool}>
     */
    private function options(mixed $options): array
    {
        if ($options === null || $options === []) {
            return [];
        }
        if (!is_array($options) || count($options) < 2 || count($options) > 8) {
            throw new HolderException('invalid_question', 'A select needs at least two options.', 422);
        }

        $normalized = [];
        $seen = [];
        foreach ($options as $option) {
            if (!is_array($option)) {
                throw new HolderException('invalid_question', 'Each option needs a label.', 422);
            }
            $id = trim((string) ($option['id'] ?? ''));
            $label = trim((string) ($option['label'] ?? ''));
            $description = trim((string) ($option['description'] ?? ''));
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,40}$/', $id) !== 1 || isset($seen[$id])) {
                throw new HolderException('invalid_question', 'Each option needs its own id.', 422);
            }
            if ($label === '' || mb_strlen($label) > 200 || mb_strlen($description) > 500) {
                throw new HolderException('invalid_question', 'Each option needs a label.', 422);
            }
            $seen[$id] = true;
            $normalized[] = [
                'id' => $id,
                'label' => $label,
                'description' => $description,
                'freeText' => ($option['freeText'] ?? false) === true,
            ];
        }

        return $normalized;
    }

    /**
     * @return array{commentBody: string, questions: list<array{id: string, prompt: string, options: list<array{id: string, label: string, description: string, freeText: bool}>}>}|null
     */
    private function fromComment(string $body): ?array
    {
        $lines = preg_split('/\R/', $body);
        if ($lines === false) {
            return null;
        }

        $questions = [];
        $kept = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*(?:\d+[\.\)]|[-*])\s+(.+\?)\s*$/u', $line, $match) === 1) {
                $prompt = trim($match[1]);
                if ($prompt === '' || mb_strlen($prompt) > 1000) {
                    return null;
                }
                $questions[] = [
                    'id' => 'q' . (count($questions) + 1),
                    'prompt' => $prompt,
                    'options' => [],
                ];
                continue;
            }
            $kept[] = $line;
        }
        if ($questions === [] || count($questions) > 8) {
            return null;
        }

        return [
            'commentBody' => trim(implode("\n", $kept)),
            'questions' => $questions,
        ];
    }

    /**
     * @param list<array{id: string, prompt: string, options: list<array{id: string, label: string, description: string, freeText: bool}>}> $questions
     * @return array<string, mixed>
     */
    private function card(?string $commentId, string $intro, string $commentBody, array $questions): array
    {
        return [
            'commentId' => $commentId,
            'commentBody' => $commentBody,
            'intro' => $intro,
            'submitLabel' => 'Continue',
            'questions' => $questions,
        ];
    }
}
