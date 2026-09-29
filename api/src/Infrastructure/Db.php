<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Yiisoft\Db\Connection\ConnectionInterface;

final class Db
{
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    public function connection(): ConnectionInterface
    {
        return $this->connection;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        return $this->connection->createCommand($sql, $this->bind($params))->queryOne();
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->createCommand($sql, $this->bind($params))->queryAll();

        return $rows;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function exec(string $sql, array $params = []): void
    {
        $this->connection->createCommand($sql, $this->bind($params))->execute();
    }

    /**
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        return $this->connection->transaction(function () use ($fn): mixed {
            return $fn($this);
        });
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function bind(array $params): array
    {
        $bound = [];
        foreach ($params as $key => $value) {
            $name = str_starts_with((string) $key, ':') ? (string) $key : ':' . $key;
            $bound[$name] = $value;
        }

        return $bound;
    }
}
