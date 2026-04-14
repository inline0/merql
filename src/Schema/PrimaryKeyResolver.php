<?php

declare(strict_types=1);

namespace Merql\Schema;

/**
 * Determines how to identify "the same row" for a given table schema.
 */
final class PrimaryKeyResolver
{
    /**
     * Returns the columns to use as row identity.
     *
     * @return list<string>
     */
    public static function resolve(TableSchema $schema): array
    {
        if ($schema->hasPrimaryKey()) {
            return $schema->primaryKey;
        }

        if ($schema->hasUniqueKeys()) {
            return $schema->uniqueKeys[0];
        }

        // Fallback: all columns as identity.
        return array_keys($schema->columns);
    }
}
