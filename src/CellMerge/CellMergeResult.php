<?php

declare(strict_types=1);

namespace Merql\CellMerge;

/**
 * Result of merging a single cell value.
 */
final readonly class CellMergeResult
{
    private function __construct(
        public bool $clean,
        public mixed $value,
        public int $conflicts,
    ) {
    }

    public static function resolved(mixed $value): self
    {
        return new self(true, $value, 0);
    }

    public static function conflict(mixed $oursValue, int $conflicts = 1): self
    {
        return new self(false, $oursValue, $conflicts);
    }
}
