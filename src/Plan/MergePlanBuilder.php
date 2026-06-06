<?php

declare(strict_types=1);

namespace Merql\Plan;

use Merql\Merge\Conflict;
use Merql\Merge\MergeOperation;
use Merql\Merge\MergeResult;
use Merql\Snapshot\Snapshot;
use Merql\Snapshot\Snapshotter;

/**
 * Builds a merge plan from snapshots and a merge result.
 */
final class MergePlanBuilder
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function build(
        string $id,
        Snapshot $base,
        Snapshot $ours,
        Snapshot $theirs,
        MergeResult $result,
        array $metadata = [],
        ?int $createdAt = null,
    ): MergePlan {
        $operations = [];
        foreach ($result->operations() as $operation) {
            $operations[] = $this->operation($operation, $base, $ours, $theirs);
        }

        $conflicts = array_map(
            static fn(Conflict $conflict): MergePlanConflict => self::conflict($conflict),
            $result->conflicts(),
        );

        $groups = array_map(
            static fn(MergePlanOperation $operation): MergePlanChangeGroup => self::defaultGroup($operation),
            $operations,
        );

        $schemaMismatches = array_map(
            static fn(\Throwable $error): string => $error->getMessage(),
            $result->schemaMismatches(),
        );

        $summary = self::summary($groups, $operations, $conflicts, $schemaMismatches);
        $tables = array_values(array_unique(array_map(
            static fn(MergePlanOperation $operation): string => $operation->table,
            $operations,
        )));
        sort($tables);

        $createdAt ??= time();
        $hash = self::hashPayload([
            'base' => $base->name,
            'ours' => $ours->name,
            'theirs' => $theirs->name,
            'operations' => array_map(
                static fn(MergePlanOperation $operation): array => $operation->toArray(),
                $operations,
            ),
            'conflicts' => array_map(
                static fn(MergePlanConflict $conflict): array => $conflict->toArray(),
                $conflicts,
            ),
            'schema_mismatches' => $schemaMismatches,
        ]);

        return new MergePlan(
            $id,
            $base->name,
            $ours->name,
            $theirs->name,
            $createdAt,
            $tables,
            $summary,
            $groups,
            $operations,
            $conflicts,
            $schemaMismatches,
            $hash,
            $metadata,
        );
    }

    private function operation(
        MergeOperation $operation,
        Snapshot $base,
        Snapshot $ours,
        Snapshot $theirs,
    ): MergePlanOperation {
        $baseTable = $base->getTable($operation->table);
        $oursTable = $ours->getTable($operation->table);
        $theirsTable = $theirs->getTable($operation->table);
        $identityColumns = [];
        if ($baseTable !== null) {
            $identityColumns = $baseTable->identityColumns;
        } elseif ($oursTable !== null) {
            $identityColumns = $oursTable->identityColumns;
        } elseif ($theirsTable !== null) {
            $identityColumns = $theirsTable->identityColumns;
        }
        $identityValues = self::identityValues($operation->rowKey, $identityColumns);
        $baseRow = $baseTable?->getRow($operation->rowKey);
        $oursRow = $oursTable?->getRow($operation->rowKey);
        $theirsRow = $theirsTable?->getRow($operation->rowKey);
        $changedColumns = self::changedColumns($baseRow, $operation->values);

        $id = self::operationId($operation, $changedColumns);

        return new MergePlanOperation(
            $id,
            $operation->type,
            $operation->table,
            $operation->rowKey,
            $identityColumns,
            $identityValues,
            $operation->source,
            $baseRow,
            $oursRow,
            $theirsRow,
            $operation->values,
            $changedColumns,
        );
    }

    /**
     * @param list<string> $identityColumns
     * @return array<string, scalar|null>
     */
    private static function identityValues(string $rowKey, array $identityColumns): array
    {
        $parts = Snapshotter::decodeRowKey($rowKey);
        $values = [];
        foreach ($identityColumns as $index => $column) {
            $values[$column] = $parts[$index] ?? '';
        }

        return $values;
    }

    /**
     * @param array<string, scalar|null>|null $baseRow
     * @param array<string, scalar|null> $resultValues
     * @return list<string>
     */
    private static function changedColumns(?array $baseRow, array $resultValues): array
    {
        if ($baseRow === null) {
            $columns = array_keys($resultValues);
            sort($columns);

            return $columns;
        }

        $columns = [];
        foreach ($resultValues as $column => $value) {
            if (!array_key_exists($column, $baseRow) || $baseRow[$column] !== $value) {
                $columns[] = $column;
            }
        }
        sort($columns);

        return $columns;
    }

    /**
     * @param list<string> $changedColumns
     */
    private static function operationId(MergeOperation $operation, array $changedColumns): string
    {
        return self::hashPayload([
            'table' => $operation->table,
            'row_key' => $operation->rowKey,
            'type' => $operation->type,
            'source' => $operation->source,
            'changed_columns' => $changedColumns,
            'values' => $operation->values,
        ]);
    }

    private static function defaultGroup(MergePlanOperation $operation): MergePlanChangeGroup
    {
        $counts = [$operation->type => 1];
        $id = self::hashPayload([
            'type' => 'operation',
            'primary_table' => $operation->table,
            'primary_row_key' => $operation->rowKey,
            'operation_ids' => [$operation->id],
            'dependency_fingerprint' => '',
        ]);

        return new MergePlanChangeGroup(
            $id,
            'operation',
            ['table' => $operation->table, 'row_key' => $operation->rowKey],
            $operation->table,
            $operation->rowKey,
            [$operation->id],
            [$operation->table],
            $counts,
        );
    }

    private static function conflict(Conflict $conflict): MergePlanConflict
    {
        return new MergePlanConflict(
            $conflict->table(),
            $conflict->rowKey(),
            $conflict->type(),
            $conflict->column(),
            $conflict->oursValue(),
            $conflict->theirsValue(),
            $conflict->baseValue(),
        );
    }

    /**
     * @param list<MergePlanChangeGroup> $groups
     * @param list<MergePlanOperation> $operations
     * @param list<MergePlanConflict> $conflicts
     * @param list<string> $schemaMismatches
     */
    private static function summary(
        array $groups,
        array $operations,
        array $conflicts,
        array $schemaMismatches,
    ): MergePlanSummary {
        $operationCounts = [];
        $tableCounts = [];

        foreach ($operations as $operation) {
            $operationCounts[$operation->type] = ($operationCounts[$operation->type] ?? 0) + 1;
            $tableCounts[$operation->table] = ($tableCounts[$operation->table] ?? 0) + 1;
        }

        ksort($operationCounts);
        ksort($tableCounts);

        return new MergePlanSummary(
            count($groups),
            count($operations),
            count($conflicts),
            count($schemaMismatches),
            $operationCounts,
            $tableCounts,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function hashPayload(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return hash('sha256', $encoded);
    }
}
