<?php

declare(strict_types=1);

namespace Merql;

use PDO;

/**
 * PDO connection builder. Supports any PDO-compatible database.
 */
final class Connection
{
    private const PDO_OPTIONS = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => true,
    ];

    /**
     * Build a MySQL PDO connection.
     */
    public static function mysql(
        string $host,
        string $database,
        string $username,
        string $password = '',
        int $port = 3306,
        string $charset = 'utf8mb4',
    ): PDO {
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

        return new PDO($dsn, $username, $password, self::PDO_OPTIONS);
    }

    /**
     * Build a SQLite PDO connection.
     *
     * @param string $path File path, or ':memory:' for in-memory database.
     */
    public static function sqlite(string $path = ':memory:'): PDO
    {
        $pdo = new PDO("sqlite:{$path}", '', '', self::PDO_OPTIONS);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    /**
     * Build a PDO connection from a DSN string.
     */
    public static function fromDsn(string $dsn, string $username = '', string $password = ''): PDO
    {
        return new PDO($dsn, $username, $password, self::PDO_OPTIONS);
    }

    /**
     * @deprecated Use mysql() instead.
     */
    public static function create(
        string $host,
        string $database,
        string $username,
        string $password = '',
        int $port = 3306,
        string $charset = 'utf8mb4',
    ): PDO {
        return self::mysql($host, $database, $username, $password, $port, $charset);
    }
}
