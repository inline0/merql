<?php

declare(strict_types=1);

namespace Merql\Snapshot;

use Merql\Schema\TableSchema;

/**
 * Input data for building a TableSnapshot without database access.
 */
final readonly class TableSnapshotData
{
    /**
     * @param TableSchema $schema Table structure.
     * @param list<array<string, mixed>> $rows Row data.
     * @param list<string> $identityColumns Columns used for row identity.
     */
    public function __construct(
        public TableSchema $schema,
        public array $rows,
        public array $identityColumns,
    ) {
    }
}
