<?php

declare(strict_types=1);

namespace Merql\Apply;

/**
 * Result of applying a merge to a database.
 */
final readonly class ApplyResult
{
    /**
     * @param int $rowsAffected Total rows affected.
     * @param list<string> $errors Any errors encountered.
     */
    public function __construct(
        private int $rowsAffected,
        private array $errors = [],
    ) {
    }

    public function rowsAffected(): int
    {
        return $this->rowsAffected;
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
