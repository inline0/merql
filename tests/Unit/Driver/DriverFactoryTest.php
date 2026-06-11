<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Driver;

use Merql\Connection;
use Merql\Database\DatabaseConnection;
use Merql\Driver\DriverFactory;
use Merql\Driver\SqliteDriver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DriverFactoryTest extends TestCase
{
    #[Test]
    public function detects_sqlite_driver(): void
    {
        $connection = Connection::sqlite();

        $driver = DriverFactory::create($connection);

        $this->assertInstanceOf(SqliteDriver::class, $driver);
    }

    #[Test]
    public function throws_for_unknown_driver(): void
    {
        $connection = new class implements DatabaseConnection {
            public function driverName(): string
            {
                return 'unknown_test';
            }

            public function execute(string $sql, array $params = []): int
            {
                return 0;
            }

            public function query(string $sql, array $params = []): array
            {
                return [];
            }

            public function scalar(string $sql, array $params = []): ?string
            {
                return null;
            }

            public function beginTransaction(): void
            {
            }

            public function commit(): void
            {
            }

            public function rollBack(): void
            {
            }

            public function lastInsertId(): string
            {
                return '0';
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported database driver: unknown_test');

        DriverFactory::create($connection);
    }
}
