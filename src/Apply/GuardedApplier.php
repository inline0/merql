<?php

declare(strict_types=1);

namespace Merql\Apply;

use Merql\Driver\Driver;
use Merql\Driver\DriverFactory;
use Merql\Exceptions\ConflictException;
use Merql\Merge\MergeResult;
use Merql\Snapshot\Snapshot;
use PDO;

/**
 * Executes a merge result with optimistic live-row preconditions.
 */
final class GuardedApplier
{
    private readonly Driver $driver;

    public function __construct(
        private readonly PDO $pdo,
        ?Driver $driver = null,
    ) {
        $this->driver = $driver ?? DriverFactory::create($pdo);
    }

    /**
     * @throws ConflictException If unresolved conflicts remain.
     */
    public function apply(MergeResult $result, Snapshot $expectedLive): ApplyResult
    {
        if (!$result->isClean()) {
            throw ConflictException::unresolved($result->conflictCount());
        }

        $fkDeps = $this->driver->readForeignKeys($this->pdo);
        $statements = SqlGenerator::generateGuarded($result, $expectedLive, $fkDeps, $this->driver);
        $totalAffected = 0;
        $errors = [];

        $this->pdo->beginTransaction();

        try {
            foreach ($statements as $stmt) {
                $prepared = $this->pdo->prepare($stmt['sql']);
                $prepared->execute($stmt['params']);
                $affected = $prepared->rowCount();

                if ($affected < 1) {
                    throw new \RuntimeException('Optimistic precondition failed for guarded merge statement.');
                }

                $totalAffected += $affected;
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $errors[] = $e->getMessage();
        }

        return new ApplyResult($totalAffected, $errors);
    }
}
