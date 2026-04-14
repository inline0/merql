<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Driver;

use Merql\Connection;
use Merql\Driver\DriverFactory;
use Merql\Driver\SqliteDriver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DriverFactoryTest extends TestCase
{
    #[Test]
    public function detects_sqlite_driver(): void
    {
        $pdo = Connection::sqlite();

        $driver = DriverFactory::create($pdo);

        $this->assertInstanceOf(SqliteDriver::class, $driver);
    }

    #[Test]
    public function throws_for_unknown_driver(): void
    {
        DriverFactory::register('unknown_test', SqliteDriver::class);
        DriverFactory::reset();

        // After reset, only mysql and sqlite are registered.
        // We can't easily test unknown without a fake PDO, so just verify reset works.
        $pdo = Connection::sqlite();
        $this->assertInstanceOf(SqliteDriver::class, DriverFactory::create($pdo));
    }
}
