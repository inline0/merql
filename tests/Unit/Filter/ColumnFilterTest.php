<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Filter;

use Merql\Filter\ColumnFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ColumnFilterTest extends TestCase
{
    #[Test]
    public function removes_ignored_columns(): void
    {
        $filter = ColumnFilter::ignore(['updated_at', 'modified_date']);

        $row = ['id' => '1', 'title' => 'Hello', 'updated_at' => '2024-01-01', 'modified_date' => '2024-01-01'];
        $result = $filter->applyToRow($row);

        $this->assertSame(['id' => '1', 'title' => 'Hello'], $result);
    }

    #[Test]
    public function keeps_all_when_no_match(): void
    {
        $filter = ColumnFilter::ignore(['updated_at']);

        $row = ['id' => '1', 'title' => 'Hello'];
        $result = $filter->applyToRow($row);

        $this->assertSame(['id' => '1', 'title' => 'Hello'], $result);
    }
}
