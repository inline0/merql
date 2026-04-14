<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Apply;

use Merql\Exceptions\ConflictException;
use Merql\Merge\Conflict;
use Merql\Merge\MergeResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApplierTest extends TestCase
{
    #[Test]
    public function throws_when_unresolved_conflicts_exist(): void
    {
        $result = new MergeResult([], [
            new Conflict('posts', '1', 'update_update', 'title', 'A', 'B', 'C'),
        ]);

        $this->assertFalse($result->isClean());

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('1 unresolved conflict');

        // We can't test the full Applier without a PDO, but we can test
        // that MergeResult correctly reports conflicts.
        if (!$result->isClean()) {
            throw ConflictException::unresolved($result->conflictCount());
        }
    }

    #[Test]
    public function conflict_exception_includes_count(): void
    {
        $result = new MergeResult([], [
            new Conflict('t', '1', 'update_update', 'a', 'x', 'y'),
            new Conflict('t', '2', 'update_update', 'b', 'x', 'y'),
            new Conflict('t', '3', 'update_delete'),
        ]);

        $this->assertSame(3, $result->conflictCount());

        $e = ConflictException::unresolved(3);
        $this->assertStringContainsString('3', $e->getMessage());
    }
}
