<?php

declare(strict_types=1);

namespace Merql\Snapshot;

use Merql\Schema\TableSchema;

/**
 * Single table's rows and schema at a point in time.
 */
final readonly class TableSnapshot
{
    /**
     * @param TableSchema $schema Table structure.
     * @param array<int|string, string> $fingerprints Row identity key to fingerprint hash.
     * @param array<int|string, array<string, mixed>> $rows Row identity key to column data.
     * @param list<string> $identityColumns Columns used for row identity.
     */
    public function __construct(
        public TableSchema $schema,
        public array $fingerprints,
        public array $rows,
        public array $identityColumns,
    ) {
    }

    public function hasRow(string $key): bool
    {
        return array_key_exists($key, $this->fingerprints)
            || (ctype_digit($key) && array_key_exists((int) $key, $this->fingerprints));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRow(string $key): ?array
    {
        if (array_key_exists($key, $this->rows)) {
            return $this->rows[$key];
        }

        if (ctype_digit($key) && array_key_exists((int) $key, $this->rows)) {
            return $this->rows[(int) $key];
        }

        return null;
    }

    public function getFingerprint(string $key): ?string
    {
        if (array_key_exists($key, $this->fingerprints)) {
            return $this->fingerprints[$key];
        }

        if (ctype_digit($key) && array_key_exists((int) $key, $this->fingerprints)) {
            return $this->fingerprints[(int) $key];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function rowKeys(): array
    {
        return array_map('strval', array_keys($this->fingerprints));
    }

    public function rowCount(): int
    {
        return count($this->fingerprints);
    }
}
