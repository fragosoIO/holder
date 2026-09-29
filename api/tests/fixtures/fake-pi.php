#!/usr/bin/env php
<?php

declare(strict_types=1);

if (in_array('--version', $argv, true)) {
    echo "fake-0.0.1\n";
    exit(0);
}

$emit = static function (array $record): void {
    fwrite(STDOUT, json_encode($record, JSON_THROW_ON_ERROR) . "\n");
    fflush(STDOUT);
};

$saw = false;
while (($line = fgets(STDIN)) !== false) {
    $saw = true;
    $command = json_decode($line, true);
    if (!is_array($command)) {
        fwrite(STDERR, "fake-pi received no command\n");
        exit(1);
    }
    $type = (string) ($command['type'] ?? '');
    $id = $command['id'] ?? null;

    if ($type === 'get_available_models') {
        $emit([
            'id' => $id,
            'type' => 'response',
            'command' => 'get_available_models',
            'success' => true,
            'data' => [
                'models' => [
                    [
                        'provider' => 'anthropic',
                        'id' => 'claude-haiku-4-5',
                        'name' => 'Claude Haiku 4.5',
                        'reasoning' => true,
                    ],
                    [
                        'provider' => 'vllm',
                        'id' => 'qwen',
                        'name' => 'Qwen',
                        'reasoning' => true,
                        'thinkingLevelMap' => [
                            'off' => 'off',
                            'minimal' => null,
                            'low' => 'low',
                            'medium' => 'medium',
                            'high' => null,
                            'xhigh' => 'xhigh',
                            'max' => null,
                        ],
                    ],
                    [
                        'provider' => 'grok-cli',
                        'id' => 'grok-fast',
                        'name' => 'Grok Fast',
                        'reasoning' => false,
                        'thinkingLevelMap' => [
                            'off' => 'none',
                            'minimal' => null,
                            'low' => null,
                            'medium' => null,
                            'high' => null,
                            'xhigh' => null,
                        ],
                    ],
                ],
            ],
        ]);
        continue;
    }

    if ($type === 'get_state') {
        $emit([
            'id' => $id,
            'type' => 'response',
            'command' => 'get_state',
            'success' => true,
            'data' => [
                'model' => ['provider' => 'vllm', 'id' => 'qwen'],
                'thinkingLevel' => 'medium',
            ],
        ]);
        continue;
    }

    if ($type !== 'prompt') {
        continue;
    }

    $emit([
        'id' => $id,
        'type' => 'response',
        'command' => 'prompt',
        'success' => true,
        'data' => ['disposition' => 'started'],
    ]);
    $emit(['type' => 'session', 'id' => 'fake-session']);

    $forced = getenv('FAKE_PI_ERROR');
    if (is_string($forced) && $forced !== '') {
        $emit([
            'type' => 'message_end',
            'message' => [
                'role' => 'assistant',
                'content' => [],
                'stopReason' => 'error',
                'errorMessage' => 'OAuth refresh failed for anthropic: token refresh failed. body={"error":"invalid_grant","error_description":"' . $forced . '"}; stack=Error: hidden',
            ],
        ]);
        $emit(['type' => 'agent_settled']);
        exit(0);
    }
    $emit([
        'type' => 'message_update',
        'assistantMessageEvent' => ['type' => 'text_delta', 'delta' => 'working'],
    ]);

    $bin = getenv('HOLDER_BIN') ?: '';
    $task = getenv('HOLDER_TASK_ID') ?: '';
    if ($bin !== '' && $task !== '') {
        $comment = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($bin)
            . ' comment --task ' . escapeshellarg($task)
            . ' --body ' . escapeshellarg('Pi checked the task.');
        exec($comment, $output, $code);
        if ($code !== 0) {
            fwrite(STDERR, implode("\n", $output) . "\n");
        }
    }

    $emit(['type' => 'agent_settled']);
    exit(0);
}

if (!$saw) {
    fwrite(STDERR, "fake-pi received no command\n");
    exit(1);
}
