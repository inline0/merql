<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Apply;

use Merql\Apply\DryRun;
use Merql\Merge\MergeOperation;
use Merql\Merge\MergeResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DryRunTest extends TestCase
{
    #[Test]
    public function generates_readable_sql(): void
    {
        $result = new MergeResult([
            new MergeOperation(
                MergeOperation::TYPE_INSERT,
                'posts',
                '1',
                ['id' => '1', 'title' => 'Hello'],
            ),
        ]);

        $sql = DryRun::generate($result);

        $this->assertCount(1, $sql);
        $this->assertStringContainsString("'1'", $sql[0]);
        $this->assertStringContainsString("'Hello'", $sql[0]);
    }

    #[Test]
    public function null_values_rendered_as_null(): void
    {
        $result = new MergeResult([
            new MergeOperation(
                MergeOperation::TYPE_INSERT,
                'posts',
                '1',
                ['id' => '1', 'title' => null],
            ),
        ]);

        $sql = DryRun::generate($result);

        $this->assertStringContainsString('NULL', $sql[0]);
    }

    #[Test]
    public function does_not_execute_anything(): void
    {
        // DryRun is purely generative. No PDO needed.
        $result = new MergeResult([
            new MergeOperation(MergeOperation::TYPE_INSERT, 'posts', '1', ['id' => '1']),
            new MergeOperation(MergeOperation::TYPE_UPDATE, 'posts', '2', ['id' => '2', 'title' => 'X']),
            new MergeOperation(MergeOperation::TYPE_DELETE, 'posts', '3', ['id' => '3']),
        ]);

        $sql = DryRun::generate($result);

        $this->assertCount(3, $sql);
    }
}
