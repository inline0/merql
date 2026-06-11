<?php

declare(strict_types=1);

namespace Merql\Driver;

use Merql\Database\DatabaseConnection;

/**
 * Auto-detect and create the appropriate driver for a connection.
 */
final class DriverFactory
{
    /**
     * @var array<string, class-string<Driver>>
     */
    private static array $drivers = [
        'mysql' => MysqlDriver::class,
        'sqlite' => SqliteDriver::class,
    ];

    /**
     * Register a custom driver for a connection driver name.
     *
     * @param class-string<Driver> $driverClass
     */
    public static function register(string $connectionDriver, string $driverClass): void
    {
        self::$drivers[$connectionDriver] = $driverClass;
    }

    /**
     * Create a driver instance based on the connection's driver identity.
     */
    public static function create(DatabaseConnection $connection): Driver
    {
        $connectionDriver = $connection->driverName();

        $class = self::$drivers[$connectionDriver] ?? null;
        if ($class === null) {
            throw new \RuntimeException("Unsupported database driver: {$connectionDriver}");
        }

        return new $class();
    }

    /**
     * Reset to default drivers (for testing).
     */
    public static function reset(): void
    {
        self::$drivers = [
            'mysql' => MysqlDriver::class,
            'sqlite' => SqliteDriver::class,
        ];
    }
}
