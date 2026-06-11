<?php

declare(strict_types=1);

namespace Merql\Tests\Integration;

use Merql\Apply\GuardedApplier;
use Merql\Connection;
use Merql\Database\DatabaseConnection;
use Merql\Diff\Differ;
use Merql\Merge\ThreeWayMerge;
use Merql\Plan\MergePlanBuilder;
use Merql\Plan\OperationSelection;
use Merql\Rollback\RollbackPlanApplier;
use Merql\Rollback\RollbackPlanBuilder;
use Merql\Snapshot\Snapshotter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MysqliEndToEndTest extends TestCase
{
    #[Test]
    public function snapshot_diff_guarded_apply_and_rollback_over_mysqli(): void
    {
        $connection = $this->mysqliConnection();
        if ($connection === null) {
            $this->markTestSkipped('Set MERQL_MYSQL_* env vars to run mysqli end-to-end tests.');
        }

        $table = 'merql_mysqli_e2e_' . bin2hex(random_bytes(4));
        $connection->execute("DROP TABLE IF EXISTS {$table}");
        $connection->execute("CREATE TABLE {$table} (id INT PRIMARY KEY, title VARCHAR(128))");

        try {
            $connection->execute("INSERT INTO {$table} (id, title) VALUES (1, 'Hello')");
            $snapshotter = new Snapshotter($connection);
            $base = $snapshotter->capture('base', [$table]);

            $connection->execute("UPDATE {$table} SET title = 'Merged' WHERE id = 1");
            $theirs = $snapshotter->capture('theirs', [$table]);

            $changeset = (new Differ())->diff($base, $theirs);
            $this->assertCount(1, $changeset->updates());

            $connection->execute("UPDATE {$table} SET title = 'Hello' WHERE id = 1");
            $expectedLive = $snapshotter->capture('expected-live', [$table]);

            $mergeResult = (new ThreeWayMerge())->merge($base, $base, $theirs);
            $applyResult = (new GuardedApplier($connection))->apply($mergeResult, $expectedLive);
            $this->assertFalse($applyResult->hasErrors());
            $this->assertSame('Merged', $connection->scalar("SELECT title FROM {$table} WHERE id = 1"));

            $plan = (new MergePlanBuilder())->build('plan', $base, $base, $theirs, $mergeResult);
            $operationId = $plan->operations[0]->id;
            $rollback = (new RollbackPlanBuilder())->build(
                'rollback',
                $plan,
                OperationSelection::fromIds([$operationId]),
                [$operationId => ['id' => '1', 'title' => 'Hello']],
            );

            $rollbackResult = (new RollbackPlanApplier($connection))->apply($rollback, $base);
            $this->assertFalse($rollbackResult->hasErrors());
            $this->assertSame('Hello', $connection->scalar("SELECT title FROM {$table} WHERE id = 1"));
        } finally {
            $connection->execute("DROP TABLE IF EXISTS {$table}");
        }
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
}
