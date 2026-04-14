<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Filter;

use Merql\Filter\RowFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RowFilterTest extends TestCase
{
    #[Test]
    public function includes_rows_matching_predicate(): void
    {
        $filter = RowFilter::create(fn(string $table, array $row) => ($row['status'] ?? '') === 'active');

        $this->assertTrue($filter->shouldInclude('users', ['id' => '1', 'status' => 'active']));
        $this->assertFalse($filter->shouldInclude('users', ['id' => '2', 'status' => 'inactive']));
    }

    #[Test]
    public function receives_table_name(): void
    {
        $filter = RowFilter::create(fn(string $table, array $row) => $table !== 'sessions');

        $this->assertTrue($filter->shouldInclude('users', ['id' => '1']));
        $this->assertFalse($filter->shouldInclude('sessions', ['id' => '1']));
    }

    #[Test]
    public function handles_empty_row(): void
    {
        $filter = RowFilter::create(fn(string $table, array $row) => $row !== []);

        $this->assertFalse($filter->shouldInclude('t', []));
        $this->assertTrue($filter->shouldInclude('t', ['a' => '1']));
    }
}
