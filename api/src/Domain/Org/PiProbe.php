<?php

declare(strict_types=1);

namespace App\Domain\Org;

use App\Domain\HolderException;

final class PiProbe
{
    /**
     * Models Pi can use right now, each with the thinking levels that model supports.
     * The default is the model and thinking level Pi itself would start with.
     *
     * @return array{
     *     provider: string,
     *     model: string,
     *     thinking: string,
     *     models: list<array{provider: string, id: string, name: string, thinking: list<string>}>
     * }
     */
    public function catalog(string $binary): array
    {
        $resolved = $this->resolve($binary);
        $stdout = $this->rpc($resolved, [
            ['id' => 'models', 'type' => 'get_available_models'],
            ['id' => 'state', 'type' => 'get_state'],
        ]);
        $modelsResponse = null;
        $stateResponse = null;
        foreach (preg_split("/\r\n|\n|\r/", $stdout) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (!is_array($decoded) || ($decoded['type'] ?? '') !== 'response') {
                continue;
            }
            if (($decoded['id'] ?? '') === 'models') {
                $modelsResponse = $decoded;
            }
            if (($decoded['id'] ?? '') === 'state') {
                $stateResponse = $decoded;
            }
        }

        if (!is_array($modelsResponse) || ($modelsResponse['success'] ?? false) !== true) {
            $detail = is_array($modelsResponse) ? trim((string) ($modelsResponse['error'] ?? '')) : '';
            throw new HolderException(
                'pi_models',
                $detail !== '' ? $detail : 'Pi did not return its model list.',
                422,
            );
        }

        $dataValue = $modelsResponse['data'] ?? null;
        $data = is_array($dataValue) ? $dataValue : [];
        $rawValue = $data['models'] ?? null;
        $rawModels = is_array($rawValue) ? $rawValue : [];
        $models = [];
        foreach ($rawModels as $model) {
            if (!is_array($model)) {
                continue;
            }
            $provider = trim((string) ($model['provider'] ?? ''));
            $id = trim((string) ($model['id'] ?? ''));
            if ($provider === '' || $id === '') {
                continue;
            }
            $name = trim((string) ($model['name'] ?? ''));
            $models[] = [
                'provider' => $provider,
                'id' => $id,
                'name' => $name !== '' ? $name : $id,
                'thinking' => ThinkingLevels::forModel($model),
            ];
        }
        usort(
            $models,
            static fn (array $left, array $right): int => [$left['provider'], $left['id']] <=> [$right['provider'], $right['id']],
        );
        if ($models === []) {
            throw new HolderException(
                'pi_models',
                'Pi has no available models. Sign in with pi auth, then try again.',
                422,
            );
        }

        $stateValue = is_array($stateResponse) ? ($stateResponse['data'] ?? null) : null;
        $state = is_array($stateValue) ? $stateValue : [];
        $currentValue = $state['model'] ?? null;
        $current = is_array($currentValue) ? $currentValue : [];
        $provider = trim((string) ($current['provider'] ?? ''));
        $modelId = trim((string) ($current['id'] ?? ''));
        $selected = null;
        foreach ($models as $model) {
            if ($model['provider'] === $provider && $model['id'] === $modelId) {
                $selected = $model;
                break;
            }
        }
        if ($selected === null) {
            $selected = $models[0];
        }

        return [
            'provider' => $selected['provider'],
            'model' => $selected['id'],
            'thinking' => ThinkingLevels::clamp(trim((string) ($state['thinkingLevel'] ?? '')), $selected['thinking']),
            'models' => $models,
        ];
    }

    public function version(string $binary): string
    {
        $binary = $this->resolve($binary);

        $command = [$binary, '--version'];
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        try {
            $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        } catch (\Throwable) {
            throw $this->missing($binary);
        }
        if (!is_resource($process)) {
            throw $this->missing($binary);
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $version = trim(is_string($stdout) ? strtok($stdout, "\n") ?: '' : '');
        if ($code !== 0 || $version === '') {
            throw $this->missing($binary);
        }

        return $version;
    }

    private function resolve(string $binary): string
    {
        $binary = trim($binary);
        if ($binary === '') {
            throw new HolderException(
                'pi_missing',
                'Pi binary path is empty. Install @earendil-works/pi-coding-agent or set the binary path.',
                422,
            );
        }
        if (str_contains($binary, DIRECTORY_SEPARATOR) || str_starts_with($binary, '.')) {
            if (!is_file($binary)) {
                throw $this->missing($binary);
            }

            return $binary;
        }
        $path = getenv('PATH');
        if (is_string($path)) {
            foreach (explode(PATH_SEPARATOR, $path) as $dir) {
                $candidate = $dir . DIRECTORY_SEPARATOR . $binary;
                if ($dir !== '' && is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        throw $this->missing($binary);
    }

    /**
     * @param list<array<string, mixed>> $commands
     */
    private function rpc(string $binary, array $commands): string
    {
        $command = [$binary, '--mode', 'rpc', '--no-session', '--offline', '--no-context-files'];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        try {
            $process = proc_open($command, $descriptors, $pipes, sys_get_temp_dir(), null, ['bypass_shell' => true]);
        } catch (\Throwable) {
            throw new HolderException('pi_models', 'Pi could not be started to list models.', 422);
        }
        if (!is_resource($process)) {
            throw new HolderException('pi_models', 'Pi could not be started to list models.', 422);
        }

        $payload = '';
        foreach ($commands as $item) {
            $payload .= json_encode($item, JSON_THROW_ON_ERROR) . "\n";
        }
        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        unset($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, 200000);
            if ($ready !== false) {
                foreach ($read as $stream) {
                    $chunk = stream_get_contents($stream);
                    if (!is_string($chunk) || $chunk === '' || $stream !== $pipes[1]) {
                        continue;
                    }
                    $stdout .= $chunk;
                    if (strlen($stdout) > 8_000_000) {
                        $this->closeProcess($process, $pipes);
                        throw new HolderException('pi_models', 'Pi returned a model list that was too large.', 422);
                    }
                }
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $rest = stream_get_contents($pipes[1]);
                if (is_string($rest)) {
                    $stdout .= $rest;
                }
                break;
            }
        }

        $status = proc_get_status($process);
        $this->closeProcess($process, $pipes);
        if ($status['running']) {
            throw new HolderException('pi_models', 'Pi did not return its model list in time.', 422);
        }

        return $stdout;
    }

    /**
     * @param resource $process
     * @param array<array-key, mixed> $pipes
     */
    private function closeProcess($process, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_terminate($process);
        proc_close($process);
    }

    private function missing(string $binary): HolderException
    {
        return new HolderException(
            'pi_missing',
            "Pi binary \"{$binary}\" was not found on PATH. Install @earendil-works/pi-coding-agent or set the binary path.",
            422,
        );
    }
}
