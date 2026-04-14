<?php

declare(strict_types=1);

namespace Merql\Exceptions;

final class SnapshotException extends \RuntimeException
{
    public static function notFound(string $name, string $path): self
    {
        return new self("Snapshot '{$name}' not found at {$path}");
    }
}
