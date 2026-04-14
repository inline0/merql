<?php

declare(strict_types=1);

namespace Merql;

use PDO;

/**
 * PDO connection builder.
 */
final class Connection
{
    /**
     * Build a PDO connection from parameters.
     */
    public static function create(
        string $host,
        string $database,
        string $username,
        string $password = '',
        int $port = 3306,
        string $charset = 'utf8mb4',
    ): PDO {
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => true,
        ]);

        return $pdo;
    }

    /**
     * Build a PDO connection from a DSN string.
     */
    public static function fromDsn(string $dsn, string $username = '', string $password = ''): PDO
    {
        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => true,
        ]);
    }
}
