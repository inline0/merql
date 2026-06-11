<?php

declare(strict_types=1);

namespace Merql\Apply;

use Merql\Database\DatabaseConnection;
use Merql\Driver\Driver;
use Merql\Driver\DriverFactory;
use Merql\Exceptions\ConflictException;
use Merql\Merge\MergeResult;
use Merql\Snapshot\Snapshot;

/**
 * Executes a merge result as SQL against a database.
 */
final class Applier
{
    private readonly Driver $driver;

    public function __construct(
        private readonly DatabaseConnection $connection,
        ?Driver $driver = null,
    ) {
        $this->driver = $driver ?? DriverFactory::create($connection);
    }

    /**
     * Apply merge result to the database.
     *
     * @throws ConflictException If unresolved conflicts remain.
     */
    public function apply(MergeResult $result, ?Snapshot $base = null): ApplyResult
    {
        if (!$result->isClean()) {
            throw ConflictException::unresolved($result->conflictCount());
        }

        $fkDeps = $this->driver->readForeignKeys($this->connection);
        $effectiveBase = $base ?? $result->baseSnapshot();
        $statements = SqlGenerator::generate($result, $effectiveBase, $fkDeps, $this->driver);
        $totalAffected = 0;
        $errors = [];

        $this->connection->beginTransaction();

        try {
            foreach ($statements as $stmt) {
                $totalAffected += $this->connection->execute($stmt['sql'], $stmt['params']);
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            $errors[] = $e->getMessage();
        }

        return new ApplyResult($totalAffected, $errors);
    }
}
