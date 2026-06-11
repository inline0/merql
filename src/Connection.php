<?php

declare(strict_types=1);

namespace Merql;

use Merql\Database\MysqliDatabaseConnection;
use Merql\Database\PdoDatabaseConnection;
use PDO;

/**
 * Connection builders for supported database transports.
 */
final class Connection
{
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
    ): PdoDatabaseConnection {
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

        return self::fromDsn($dsn, $username, $password);
    }

    /**
     * Build a MySQLi connection.
     */
    public static function mysqli(
        string $host,
        string $database,
        string $username,
        string $password = '',
        int $port = 3306,
        string $charset = 'utf8mb4',
    ): MysqliDatabaseConnection {
        return MysqliDatabaseConnection::connect($host, $database, $username, $password, $port, $charset);
    }

    /**
     * Build a SQLite PDO connection.
     *
     * @param string $path File path, or ':memory:' for in-memory database.
     */
    public static function sqlite(string $path = ':memory:'): PdoDatabaseConnection
    {
        $connection = self::fromDsn("sqlite:{$path}");
        $connection->execute('PRAGMA foreign_keys = ON');

        return $connection;
    }

    /**
     * Build a PDO connection from a DSN string.
     */
    public static function fromDsn(string $dsn, string $username = '', string $password = ''): PdoDatabaseConnection
    {
        return new PdoDatabaseConnection(new PDO($dsn, $username, $password, PdoDatabaseConnection::options()));
    }

    public static function fromPdo(PDO $pdo): PdoDatabaseConnection
    {
        return new PdoDatabaseConnection($pdo);
    }

    public static function fromMysqli(\mysqli $mysqli, string $charset = 'utf8mb4'): MysqliDatabaseConnection
    {
        return new MysqliDatabaseConnection($mysqli, $charset);
    }
}
