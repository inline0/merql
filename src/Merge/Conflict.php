<?php

declare(strict_types=1);

namespace Merql\Merge;

/**
 * An unresolved merge conflict.
 */
final readonly class Conflict
{
    /**
     * @param scalar|null|array<string, scalar|null> $oursValue
     * @param scalar|null|array<string, scalar|null> $theirsValue
     * @param scalar|null|array<string, scalar|null> $baseValue
     */
    public function __construct(
        private string $table,
        private string $rowKey,
        private string $type,
        private ?string $column = null,
        private string|int|float|bool|array|null $oursValue = null,
        private string|int|float|bool|array|null $theirsValue = null,
        private string|int|float|bool|array|null $baseValue = null,
    ) {
    }

    public function table(): string
    {
        return $this->table;
    }

    public function rowKey(): string
    {
        return $this->rowKey;
    }

    /** @return string "update_update"|"update_delete"|"delete_update"|"insert_insert" */
    public function type(): string
    {
        return $this->type;
    }

    public function column(): ?string
    {
        return $this->column;
    }

    /**
     * @return scalar|null|array<string, scalar|null>
     */
    public function oursValue(): string|int|float|bool|array|null
    {
        return $this->oursValue;
    }

    /**
     * @return scalar|null|array<string, scalar|null>
     */
    public function theirsValue(): string|int|float|bool|array|null
    {
        return $this->theirsValue;
    }

    /**
     * @return scalar|null|array<string, scalar|null>
     */
    public function baseValue(): string|int|float|bool|array|null
    {
        return $this->baseValue;
    }

    /**
     * Build the primary key array from identity columns and the row key.
     *
     * @param list<string> $identityColumns
     * @return array<string, string>
     */
    public function primaryKey(array $identityColumns): array
    {
        $parts = \Merql\Snapshot\Snapshotter::decodeRowKey($this->rowKey);
        $result = [];
        foreach ($identityColumns as $i => $col) {
            $result[$col] = $parts[$i] ?? '';
        }

        return $result;
    }
}
