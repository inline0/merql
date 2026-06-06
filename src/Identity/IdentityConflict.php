<?php

declare(strict_types=1);

namespace Merql\Identity;

/**
 * Identity conflict detected while resolving row keys.
 */
final readonly class IdentityConflict
{
    /**
     * @param string $table Table name.
     * @param string $key Conflicting row identity key.
     * @param list<string> $columns Identity columns.
     * @param int $matchCount Number of rows sharing the key.
     */
    public function __construct(
        public string $table,
        public string $key,
        public array $columns,
        public int $matchCount,
    ) {
    }
}
