<?php

declare(strict_types=1);

namespace Merql\Apply;

use Merql\Exceptions\ConflictException;
use Merql\Merge\MergeResult;
use Merql\Snapshot\Snapshot;
use PDO;

/**
 * Executes a merge result as SQL against a database.
 */
final class Applier
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
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

        $fkDeps = ForeignKeyResolver::readDependencies($this->pdo);
        $statements = SqlGenerator::generate($result, $base, $fkDeps);
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
