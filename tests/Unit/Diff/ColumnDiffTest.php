<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Diff;

use Merql\Diff\ColumnDiff;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ColumnDiffTest extends TestCase
{
    #[Test]
    public function stores_column_name_and_values(): void
    {
        $diff = new ColumnDiff('title', 'Hello', 'World');

        $this->assertSame('title', $diff->column);
        $this->assertSame('Hello', $diff->oldValue);
        $this->assertSame('World', $diff->newValue);
    }

    #[Test]
    public function supports_null_old_value(): void
    {
        $diff = new ColumnDiff('title', null, 'Hello');

        $this->assertNull($diff->oldValue);
        $this->assertSame('Hello', $diff->newValue);
    }

    #[Test]
    public function supports_null_new_value(): void
    {
        $diff = new ColumnDiff('title', 'Hello', null);

        $this->assertSame('Hello', $diff->oldValue);
        $this->assertNull($diff->newValue);
    }
}
