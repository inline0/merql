<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Driver;

use Merql\Driver\MysqlDriver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MysqlDriverTest extends TestCase
{
    #[Test]
    public function quotes_identifiers_with_backticks(): void
    {
        $driver = new MysqlDriver();

        $this->assertSame('`posts`', $driver->quoteIdentifier('posts'));
        $this->assertSame('`user name`', $driver->quoteIdentifier('user name'));
    }

    #[Test]
    public function escapes_backticks_in_identifiers(): void
    {
        $driver = new MysqlDriver();

        $this->assertSame('`say ``hello```', $driver->quoteIdentifier('say `hello`'));
    }

    #[Test]
    public function select_all_uses_backticks(): void
    {
        $driver = new MysqlDriver();

        $this->assertSame('SELECT * FROM `posts`', $driver->selectAll('posts'));
    }
}
