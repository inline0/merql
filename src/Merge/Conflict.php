<?php

declare(strict_types=1);

namespace Merql\Merge;

/**
 * An unresolved merge conflict.
 */
final readonly class Conflict
{
    public function __construct(
        private string $table,
        private string $rowKey,
        private string $type,
        private ?string $column = null,
        private mixed $oursValue = null,
        private mixed $theirsValue = null,
        private mixed $baseValue = null,
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

    public function oursValue(): mixed
    {
        return $this->oursValue;
    }

    public function theirsValue(): mixed
    {
        return $this->theirsValue;
    }

    public function baseValue(): mixed
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
        $parts = explode("\x1F", $this->rowKey);
        $result = [];
        foreach ($identityColumns as $i => $col) {
            $result[$col] = $parts[$i] ?? '';
        }

        return $result;
    }
}
