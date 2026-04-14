<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Driver;

use Merql\Driver\SqliteDriver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SqliteDriverTest extends TestCase
{
    #[Test]
    public function quotes_identifiers_with_double_quotes(): void
    {
        $driver = new SqliteDriver();

        $this->assertSame('"posts"', $driver->quoteIdentifier('posts'));
        $this->assertSame('"user name"', $driver->quoteIdentifier('user name'));
    }

    #[Test]
    public function escapes_double_quotes_in_identifiers(): void
    {
        $driver = new SqliteDriver();

        $this->assertSame('"say ""hello"""', $driver->quoteIdentifier('say "hello"'));
    }

    #[Test]
    public function select_all_uses_double_quotes(): void
    {
        $driver = new SqliteDriver();

        $this->assertSame('SELECT * FROM "posts"', $driver->selectAll('posts'));
    }
}
