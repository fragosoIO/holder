<?php

declare(strict_types=1);

namespace App\Domain\Org;

final class ThinkingLevels
{
    /** @var list<string> */
    public const ORDER = ['off', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];

    /**
     * Same rules as Pi's getSupportedThinkingLevels: no reasoning means off,
     * xhigh and max appear only when the model maps them, and a null map entry is unsupported.
     *
     * @param array<array-key, mixed> $model
     * @return list<string>
     */
    public static function forModel(array $model): array
    {
        if (empty($model['reasoning'])) {
            return ['off'];
        }

        $mapped = $model['thinkingLevelMap'] ?? null;
        $map = is_array($mapped) ? $mapped : null;
        $levels = [];
        foreach (self::ORDER as $level) {
            if (is_array($map) && array_key_exists($level, $map)) {
                if ($map[$level] === null) {
                    continue;
                }
            } elseif ($level === 'xhigh' || $level === 'max') {
                continue;
            }
            $levels[] = $level;
        }

        return $levels === [] ? ['off'] : $levels;
    }

    /**
     * @param list<string> $available
     */
    public static function clamp(string $level, array $available): string
    {
        if ($available === []) {
            return 'off';
        }
        if (in_array($level, $available, true)) {
            return $level;
        }

        $requested = array_search($level, self::ORDER, true);
        if ($requested === false) {
            return $available[0];
        }
        foreach (array_slice(self::ORDER, $requested) as $candidate) {
            if (in_array($candidate, $available, true)) {
                return $candidate;
            }
        }
        foreach (array_reverse(array_slice(self::ORDER, 0, $requested)) as $candidate) {
            if (in_array($candidate, $available, true)) {
                return $candidate;
            }
        }

        return $available[0];
    }
}
