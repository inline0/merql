<?php

declare(strict_types=1);

namespace Merql\Exceptions;

final class ConflictException extends \RuntimeException
{
    public static function unresolved(int $count): self
    {
        return new self("Cannot apply merge with {$count} unresolved conflict(s)");
    }
}
