<?php

declare(strict_types=1);

namespace Merql\Tests\Integration;

use Merql\Connection;
use Merql\Database\DatabaseConnection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DatabaseConnectionContractTest extends TestCase
{
    #[Test]
    public function adapters_return_stringified_rows_and_scalars(): void
    {
        foreach ($this->connectionSpecs() as $spec) {
            $connection = $spec['connection'];
            $table = $this->tableName('values');

            $connection->execute("DROP TABLE IF EXISTS {$table}");
            $connection->execute($spec['auto_increment_sql']($table));
            $connection->execute(
                "INSERT INTO {$table} (amount, label, active, nullable_text) VALUES (?, ?, ?, ?)",
                ['12.34', 'alpha', 1, null],
            );

            $rows = $connection->query("SELECT id, amount, label, active, nullable_text FROM {$table}");
            $this->assertSame('1', $rows[0]['id'], $spec['name']);
            $this->assertSame('12.34', $rows[0]['amount'], $spec['name']);
            $this->assertSame('alpha', $rows[0]['label'], $spec['name']);
            $this->assertSame('1', $rows[0]['active'], $spec['name']);
            $this->assertNull($rows[0]['nullable_text'], $spec['name']);
            $this->assertSame('1', $connection->scalar("SELECT COUNT(*) FROM {$table}"), $spec['name']);
            $this->assertSame('1', $connection->lastInsertId(), $spec['name']);

            $connection->execute("DROP TABLE {$table}");
        }
    }

    #[Test]
    public function adapters_bind_edge_case_values(): void
    {
        foreach ($this->connectionSpecs() as $spec) {
            $connection = $spec['connection'];
            $table = $this->tableName('bindings');

            $connection->execute("DROP TABLE IF EXISTS {$table}");
            $connection->execute(
                "CREATE TABLE {$table} (label VARCHAR(128), nullable_text TEXT NULL, marker VARCHAR(128), body TEXT)",
            );
            $connection->execute(
                "INSERT INTO {$table} (label, nullable_text, marker, body) VALUES (?, ?, ?, ?)",
                ["O'Reilly", null, "100%_\x1F", "line\nbreak"],
            );

            $row = $connection->query("SELECT label, nullable_text, marker, body FROM {$table}")[0];
            $this->assertSame("O'Reilly", $row['label'], $spec['name']);
            $this->assertNull($row['nullable_text'], $spec['name']);
            $this->assertSame("100%_\x1F", $row['marker'], $spec['name']);
            $this->assertSame("line\nbreak", $row['body'], $spec['name']);

            $connection->execute("DROP TABLE {$table}");
        }
    }

    #[Test]
    public function adapters_control_transactions_explicitly(): void
    {
        foreach ($this->connectionSpecs() as $spec) {
            $connection = $spec['connection'];
            $table = $this->tableName('transactions');

            $connection->execute("DROP TABLE IF EXISTS {$table}");
            $connection->execute("CREATE TABLE {$table} (id INTEGER PRIMARY KEY, label VARCHAR(64))");

            $connection->beginTransaction();
            $connection->execute("INSERT INTO {$table} (id, label) VALUES (?, ?)", [1, 'rolled back']);
            $connection->rollBack();
            $this->assertSame('0', $connection->scalar("SELECT COUNT(*) FROM {$table}"), $spec['name']);

            $connection->beginTransaction();
            $connection->execute("INSERT INTO {$table} (id, label) VALUES (?, ?)", [1, 'committed']);
            $connection->commit();
            $this->assertSame('1', $connection->scalar("SELECT COUNT(*) FROM {$table}"), $spec['name']);

            $connection->execute("DROP TABLE {$table}");
        }
    }

    #[Test]
    public function adapters_surface_query_errors(): void
    {
        foreach ($this->connectionSpecs() as $spec) {
            try {
                $spec['connection']->query('SELECT * FROM definitely_missing_merql_table');
                $this->fail('Expected query error for ' . $spec['name']);
            } catch (\Throwable $e) {
                $this->assertNotSame('', $e->getMessage(), $spec['name']);
            }
        }
    }

    /**
     * @return list<array{
     *     name: string,
     *     connection: DatabaseConnection,
     *     auto_increment_sql: \Closure(string): string
     * }>
     */
    private function connectionSpecs(): array
    {
        $specs = [[
            'name' => 'pdo_sqlite',
            'connection' => Connection::sqlite(),
            'auto_increment_sql' => static fn(string $table): string =>
                "CREATE TABLE {$table} ("
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'amount DECIMAL(10, 2), '
                . 'label VARCHAR(64), '
                . 'active TINYINT NULL, '
                . 'nullable_text TEXT NULL'
                . ')',
        ]];

        $mysqli = $this->mysqliConnection();
        if ($mysqli !== null) {
            $specs[] = [
                'name' => 'mysqli',
                'connection' => $mysqli,
                'auto_increment_sql' => static fn(string $table): string =>
                    "CREATE TABLE {$table} ("
                    . 'id INT AUTO_INCREMENT PRIMARY KEY, '
                    . 'amount DECIMAL(10, 2), '
                    . 'label VARCHAR(64), '
                    . 'active TINYINT NULL, '
                    . 'nullable_text TEXT NULL'
                    . ')',
            ];
        }

        return $specs;
    }

    private function mysqliConnection(): ?DatabaseConnection
    {
        $host = getenv('MERQL_MYSQL_HOST') ?: '';
        $database = getenv('MERQL_MYSQL_DATABASE') ?: '';
        $user = getenv('MERQL_MYSQL_USER') ?: '';
        $password = getenv('MERQL_MYSQL_PASSWORD') ?: '';
        $port = (int) (getenv('MERQL_MYSQL_PORT') ?: '3306');

        if ($host === '' || $database === '' || $user === '' || !extension_loaded('mysqli')) {
            return null;
        }

        return Connection::mysqli($host, $database, $user, $password, $port);
    }

    private function tableName(string $suffix): string
    {
        return 'merql_contract_' . $suffix . '_' . bin2hex(random_bytes(4));
    }
}
