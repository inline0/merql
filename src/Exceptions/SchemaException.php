<?php

declare(strict_types=1);

namespace Merql\Exceptions;

final class SchemaException extends \RuntimeException
{
    public static function mismatch(string $table, string $detail): self
    {
        return new self("Schema mismatch on table '{$table}': {$detail}");
    }
}
