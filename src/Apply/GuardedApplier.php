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
 * Executes a merge result with optimistic live-row preconditions.
 */
final class GuardedApplier
{
    private readonly Driver $driver;

    public function __construct(
        private readonly DatabaseConnection $connection,
        ?Driver $driver = null,
    ) {
        $this->driver = $driver ?? DriverFactory::create($connection);
    }

    /**
     * @throws ConflictException If unresolved conflicts remain.
     */
    public function apply(MergeResult $result, Snapshot $expectedLive): ApplyResult
    {
        if (!$result->isClean()) {
            throw ConflictException::unresolved($result->conflictCount());
        }

        $fkDeps = $this->driver->readForeignKeys($this->connection);
        $statements = SqlGenerator::generateGuarded($result, $expectedLive, $fkDeps, $this->driver);
        $totalAffected = 0;
        $errors = [];

        $this->connection->beginTransaction();

        try {
            foreach ($statements as $stmt) {
                $affected = $this->connection->execute($stmt['sql'], $stmt['params']);

                if ($affected < 1) {
                    throw new \RuntimeException('Optimistic precondition failed for guarded merge statement.');
                }

                $totalAffected += $affected;
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            $errors[] = $e->getMessage();
        }

        return new ApplyResult($totalAffected, $errors);
    }
}
