<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Filter;

use Merql\Filter\TableFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TableFilterTest extends TestCase
{
    #[Test]
    public function exclude_filters_matching_tables(): void
    {
        $filter = TableFilter::exclude(['sessions', 'cache_*']);
        $tables = ['posts', 'sessions', 'cache_pages', 'cache_data', 'settings'];

        $result = $filter->apply($tables);

        $this->assertSame(['posts', 'settings'], $result);
    }

    #[Test]
    public function include_only_keeps_matching_tables(): void
    {
        $filter = TableFilter::include(['posts', 'settings']);
        $tables = ['posts', 'sessions', 'settings', 'cache'];

        $result = $filter->apply($tables);

        $this->assertSame(['posts', 'settings'], $result);
    }

    #[Test]
    public function include_with_glob_pattern(): void
    {
        $filter = TableFilter::include(['wp_*']);
        $tables = ['wp_posts', 'wp_options', 'sessions', 'cache'];

        $result = $filter->apply($tables);

        $this->assertSame(['wp_posts', 'wp_options'], $result);
    }

    #[Test]
    public function empty_exclude_keeps_all(): void
    {
        $filter = TableFilter::exclude([]);
        $tables = ['posts', 'settings'];

        $result = $filter->apply($tables);

        $this->assertSame(['posts', 'settings'], $result);
    }
}
