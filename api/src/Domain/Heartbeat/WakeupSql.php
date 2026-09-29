<?php

declare(strict_types=1);

namespace App\Domain\Heartbeat;

final class WakeupSql
{
    public const CLAIM = <<<'SQL'
        WITH next AS (
            SELECT id FROM wakeups
            WHERE status = 'pending'
            ORDER BY created_at, id
            FOR UPDATE SKIP LOCKED
            LIMIT 1
        )
        UPDATE wakeups AS w
        SET status = 'claimed', claimed_at = NOW()
        FROM next
        WHERE w.id = next.id
        RETURNING w.*
        SQL;
}
