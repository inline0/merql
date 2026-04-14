<?php

declare(strict_types=1);

namespace Merql\Driver;

use PDO;

/**
 * Auto-detect and create the appropriate driver for a PDO connection.
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
     * Register a custom driver for a PDO driver name.
     *
     * @param class-string<Driver> $driverClass
     */
    public static function register(string $pdoDriver, string $driverClass): void
    {
        self::$drivers[$pdoDriver] = $driverClass;
    }

    /**
     * Create a driver instance based on the PDO connection's driver.
     */
    public static function create(PDO $pdo): Driver
    {
        $pdoDriver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $class = self::$drivers[$pdoDriver] ?? null;
        if ($class === null) {
            throw new \RuntimeException("Unsupported database driver: {$pdoDriver}");
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
