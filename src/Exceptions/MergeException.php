<?php

declare(strict_types=1);

namespace Merql\Exceptions;

final class MergeException extends \RuntimeException
{
    public static function snapshotRequired(string $name): self
    {
        return new self("Snapshot '{$name}' is required for merge");
    }
}
