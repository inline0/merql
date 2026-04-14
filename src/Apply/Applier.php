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
 * Executes a merge result as SQL against a database.
 */
final class Applier
{
    private readonly Driver $driver;

    public function __construct(
        private readonly PDO $pdo,
        ?Driver $driver = null,
    ) {
        $this->driver = $driver ?? DriverFactory::create($pdo);
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

        $fkDeps = $this->driver->readForeignKeys($this->pdo);
        $effectiveBase = $base ?? $result->baseSnapshot();
        $statements = SqlGenerator::generate($result, $effectiveBase, $fkDeps, $this->driver);
        $totalAffected = 0;
        $errors = [];

        $this->pdo->beginTransaction();

        try {
            foreach ($statements as $stmt) {
                $prepared = $this->pdo->prepare($stmt['sql']);
                $prepared->execute($stmt['params']);
                $totalAffected += $prepared->rowCount();
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $errors[] = $e->getMessage();
        }

        return new ApplyResult($totalAffected, $errors);
    }
}
