<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Domain\HolderException;

/**
 * Paperclip first-task copy (paperclipai/paperclip 0f14d261), filled in when an organization is created.
 */
final class FirstTaskAssets
{
    public function brief(): string
    {
        return trim(str_replace('{{proposalMode}}', 'confirmation', $this->read('brief.md')));
    }

    public function greeting(string $agentName): string
    {
        return trim($this->fill($this->read('greeting.md'), $agentName, ''));
    }

    public function instructions(string $agentName, string $organizationName): string
    {
        return $this->fill($this->read('chief-of-staff/AGENTS.md'), $agentName, $organizationName);
    }

    /**
     * @return array{
     *     prompt: string,
     *     submitLabel: string,
     *     options: list<array{id: string, label: string, description: string, freeText: bool}>
     * }
     */
    public function openingQuestion(): array
    {
        $decoded = json_decode($this->read('opening-question.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new HolderException('onboarding_assets', 'Opening question is invalid.', 500);
        }
        $prompt = trim((string) ($decoded['prompt'] ?? ''));
        $submitLabel = trim((string) ($decoded['submitLabel'] ?? ''));
        $options = $decoded['options'] ?? null;
        if ($prompt === '' || $submitLabel === '' || !is_array($options) || count($options) !== 2) {
            throw new HolderException('onboarding_assets', 'Opening question is invalid.', 500);
        }

        $normalized = [];
        foreach ($options as $option) {
            if (!is_array($option)) {
                throw new HolderException('onboarding_assets', 'Opening question is invalid.', 500);
            }
            $normalized[] = [
                'id' => trim((string) ($option['id'] ?? '')),
                'label' => trim((string) ($option['label'] ?? '')),
                'description' => trim((string) ($option['description'] ?? '')),
                'freeText' => ($option['freeText'] ?? false) === true,
            ];
        }
        if (
            $normalized[0]['id'] !== 'interview'
            || $normalized[1]['id'] !== 'task'
            || $normalized[1]['freeText'] !== true
            || $normalized[0]['label'] === ''
            || $normalized[1]['label'] === ''
        ) {
            throw new HolderException('onboarding_assets', 'Opening question options must stay interview and task.', 500);
        }

        return [
            'prompt' => $prompt,
            'submitLabel' => $submitLabel,
            'options' => $normalized,
        ];
    }

    private function fill(string $text, string $agentName, string $organizationName): string
    {
        $name = trim($agentName);
        if ($name !== '') {
            $text = str_replace('{{agentName}}', $name, $text);
        } else {
            $text = str_replace(['{{agentName}}, ', '{{agentName}} ', '{{agentName}}'], '', $text);
        }
        $organization = trim($organizationName);

        return str_replace('{{organizationName}}', $organization !== '' ? $organization : 'your organization', $text);
    }

    private function read(string $relative): string
    {
        $path = __DIR__ . '/assets/' . $relative;
        $text = file_get_contents($path);
        if ($text === false) {
            throw new HolderException('onboarding_assets', 'Onboarding text is missing.', 500);
        }

        return $text;
    }
}
