<?php

declare(strict_types=1);

namespace Merql\Filter;

/**
 * Filter specific rows from snapshot and merge.
 */
final class RowFilter
{
    /** @var \Closure(string, array<string, mixed>): bool */
    private \Closure $predicate;

    private function __construct(\Closure $predicate)
    {
        $this->predicate = $predicate;
    }

    /**
     * Create a row filter from a predicate.
     *
     * @param callable(string, array<string, mixed>): bool $predicate
     *     Receives table name and row data, returns true to include the row.
     */
    public static function create(callable $predicate): self
    {
        return new self($predicate(...));
    }

    /**
     * @param array<string, mixed> $row
     */
    public function shouldInclude(string $table, array $row): bool
    {
        return ($this->predicate)($table, $row);
    }
}
