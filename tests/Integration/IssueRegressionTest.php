<?php

declare(strict_types=1);

namespace Merql\Tests\Integration;

use Merql\Apply\Applier;
use Merql\Apply\GuardedApplier;
use Merql\CellMerge\CellMergeConfig;
use Merql\Connection;
use Merql\Merql;
use Merql\Merge\MergeOperation;
use Merql\Merge\MergeResult;
use Merql\Merge\ThreeWayMerge;
use Merql\Plan\ChangeGroupSelection;
use Merql\Plan\MergePlanBuilder;
use Merql\Plan\SelectedMergeResultFactory;
use Merql\Schema\TableSchema;
use Merql\Snapshot\Snapshot;
use Merql\Snapshot\SnapshotStore;
use Merql\Snapshot\Snapshotter;
use Merql\Snapshot\TableSnapshotData;
use Merql\Tests\Oracle\ScenarioRepository;
use Merql\Tests\Oracle\ScenarioRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IssueRegressionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/merql-issues-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        SnapshotStore::setDirectory($this->directory);
        Merql::reset();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        SnapshotStore::setDirectory('.merql/snapshots');
        Merql::reset();
    }

    #[Test]
    public function rolled_back_transactions_report_zero_written_rows(): void
    {
        foreach ([false, true] as $guarded) {
            $connection = Connection::sqlite();
            $connection->execute('CREATE TABLE posts (id INTEGER PRIMARY KEY, content TEXT)');
            $connection->execute("INSERT INTO posts VALUES (1, 'live')");
            $expected = $this->snapshot('live', [['id' => '1', 'content' => 'expected']]);
            $operations = [
                new MergeOperation(MergeOperation::TYPE_INSERT, 'posts', '2', ['id' => '2', 'content' => 'new']),
            ];
            $operations[] = $guarded
                ? new MergeOperation(MergeOperation::TYPE_UPDATE, 'posts', '1', ['id' => '1', 'content' => 'changed'])
                : new MergeOperation(
                    MergeOperation::TYPE_INSERT,
                    'posts',
                    '1',
                    ['id' => '1', 'content' => 'duplicate'],
                );
            $merge = new MergeResult($operations, [], $expected);
            $applied = $guarded
                ? (new GuardedApplier($connection))->apply($merge, $expected)
                : (new Applier($connection))->apply($merge);

            self::assertTrue($applied->hasErrors());
            self::assertSame(0, $applied->rowsAffected());
            self::assertSame('1', $connection->scalar('SELECT COUNT(*) FROM posts'));
            self::assertSame('live', $connection->scalar('SELECT content FROM posts WHERE id = 1'));
        }
    }

    #[Test]
    public function selected_conflicted_groups_keep_their_conflicts(): void
    {
        $base = $this->snapshot('base', [['id' => '1', 'content' => 'original']]);
        $ours = $this->snapshot('ours', [['id' => '1', 'content' => 'ours']]);
        $theirs = $this->snapshot('theirs', [['id' => '1', 'content' => 'theirs']]);
        $result = (new ThreeWayMerge())->merge($base, $ours, $theirs);
        $plan = (new MergePlanBuilder())->build('conflicted', $base, $ours, $theirs, $result);
        self::assertSame('conflicted', $plan->operations[0]->conflictState);
        self::assertSame('conflicted', $plan->changeGroups[0]->conflictState);

        $selected = (new SelectedMergeResultFactory())->fromChangeGroupSelection(
            $plan,
            ChangeGroupSelection::fromIds([$plan->changeGroups[0]->id]),
            $base,
        );
        self::assertFalse($selected->isClean());
        self::assertSame(1, $selected->conflictCount());
        self::assertSame('content', $selected->conflicts()[0]->column());
    }

    #[Test]
    public function facade_configuration_enables_cell_merge_and_reset_clears_it(): void
    {
        $base = $this->snapshot('base', [['id' => '1', 'content' => "one\ntwo\nthree\n"]]);
        $ours = $this->snapshot('ours', [['id' => '1', 'content' => "ONE\ntwo\nthree\n"]]);
        $theirs = $this->snapshot('theirs', [['id' => '1', 'content' => "one\ntwo\nTHREE\n"]]);
        foreach ([$base, $ours, $theirs] as $snapshot) {
            SnapshotStore::save($snapshot);
        }
        self::assertFalse(Merql::merge('base', 'ours', 'theirs')->isClean());
        Merql::init(Connection::sqlite(), cellMergeConfig: CellMergeConfig::auto());
        $result = Merql::merge('base', 'ours', 'theirs');
        self::assertTrue($result->isClean());
        self::assertSame("ONE\ntwo\nTHREE\n", $result->operations()[0]->values['content']);
        self::assertSame('clean', Merql::plan('plan', 'base', 'ours', 'theirs')->operations[0]->conflictState);
        self::assertTrue(Merql::patch('base', 'theirs')->isClean());
        Merql::reset();
        self::assertFalse(Merql::merge('base', 'ours', 'theirs')->isClean());
        self::assertTrue(Merql::merge('base', 'ours', 'theirs', CellMergeConfig::auto())->isClean());

        [$status, $stdout, $stderr] = $this->cli(['merge', 'base', 'ours', 'theirs', '--cell-merge']);
        self::assertSame(0, $status, $stderr);
        self::assertStringContainsString('Clean:      yes', $stdout);
        self::assertSame('', $stderr);
    }

    #[Test]
    public function cli_uses_configured_snapshot_directory_and_unknown_commands_fail(): void
    {
        SnapshotStore::save($this->snapshot('base', []));
        SnapshotStore::save($this->snapshot('current', [['id' => '1', 'content' => 'new']]));
        [$status, $stdout, $stderr] = $this->cli(['diff', 'base', 'current']);
        self::assertSame(0, $status, $stderr);
        self::assertStringContainsString('Inserts: 1', $stdout);

        [$status, $stdout, $stderr] = $this->cli(['bogus']);
        self::assertSame(1, $status);
        self::assertSame('', $stdout);
        self::assertSame("Unknown command: bogus\n", $stderr);
    }

    #[Test]
    public function parallel_scenarios_match_sequential_results(): void
    {
        $scenarios = (new ScenarioRepository())->all();
        self::assertNotEmpty($scenarios);
        self::assertSame(ScenarioRunner::runAll($scenarios), ScenarioRunner::runAll($scenarios, 4));
    }

    #[Test]
    public function scenario_runner_rejects_invalid_job_counts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ScenarioRunner::runAll([], 0);
    }

    /** @param list<array<string, scalar|null>> $rows */
    private function snapshot(string $name, array $rows): Snapshot
    {
        return Snapshotter::fromData($name, [
            'posts' => new TableSnapshotData(
                new TableSchema('posts', ['id' => 'int', 'content' => 'text'], ['id']),
                $rows,
                ['id'],
            ),
        ]);
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string, string}
     */
    private function cli(array $arguments): array
    {
        $environment = getenv();
        $environment['MERQL_SNAPSHOT_DIR'] = $this->directory;
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/merql', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            $environment,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
