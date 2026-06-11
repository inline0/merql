<?php

declare(strict_types=1);

namespace Merql\Database;

/**
 * Minimal database connection contract used by the merge engine.
 */
interface DatabaseConnection
{
    public function driverName(): string;

    /**
     * @param list<scalar|null> $params Positional parameters for '?' placeholders.
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * @param list<scalar|null> $params Positional parameters for '?' placeholders.
     * @return list<array<string, scalar|null>>
     */
    public function query(string $sql, array $params = []): array;

    /**
     * @param list<scalar|null> $params Positional parameters for '?' placeholders.
     */
    public function scalar(string $sql, array $params = []): string|null;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;

    public function lastInsertId(): string;
}
