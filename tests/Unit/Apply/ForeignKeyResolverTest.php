<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Apply;

use Merql\Apply\ForeignKeyResolver;
use Merql\Merge\MergeOperation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ForeignKeyResolverTest extends TestCase
{
    #[Test]
    public function topological_sort_orders_parents_first(): void
    {
        $deps = [
            'posts' => [],
            'comments' => ['posts'],
            'comment_meta' => ['comments'],
        ];

        $order = ForeignKeyResolver::topologicalSort($deps, ['comment_meta', 'comments', 'posts']);

        $postsIdx = array_search('posts', $order);
        $commentsIdx = array_search('comments', $order);
        $metaIdx = array_search('comment_meta', $order);

        $this->assertLessThan($commentsIdx, $postsIdx);
        $this->assertLessThan($metaIdx, $commentsIdx);
    }

    #[Test]
    public function handles_empty_dependency_map(): void
    {
        $order = ForeignKeyResolver::topologicalSort([], ['a', 'b', 'c']);

        $this->assertCount(3, $order);
        $this->assertContains('a', $order);
        $this->assertContains('b', $order);
        $this->assertContains('c', $order);
    }

    #[Test]
    public function handles_circular_dependencies(): void
    {
        $deps = [
            'a' => ['b'],
            'b' => ['a'],
        ];

        // Should not infinite loop.
        $order = ForeignKeyResolver::topologicalSort($deps, ['a', 'b']);

        $this->assertCount(2, $order);
    }

    #[Test]
    public function tables_not_in_deps_are_included(): void
    {
        $deps = ['child' => ['parent']];

        $order = ForeignKeyResolver::topologicalSort($deps, ['child', 'parent', 'unrelated']);

        $this->assertCount(3, $order);
        $this->assertContains('unrelated', $order);
    }

    #[Test]
    public function sort_operations_by_table_order(): void
    {
        $tableOrder = ['parent', 'child'];

        $ops = [
            new MergeOperation(MergeOperation::TYPE_INSERT, 'child', '1', ['id' => '1']),
            new MergeOperation(MergeOperation::TYPE_INSERT, 'parent', '1', ['id' => '1']),
        ];

        $sorted = ForeignKeyResolver::sortOperations($tableOrder, $ops);

        $this->assertSame('parent', $sorted[0]->table);
        $this->assertSame('child', $sorted[1]->table);
    }

    #[Test]
    public function unknown_tables_sorted_last(): void
    {
        $tableOrder = ['known'];

        $ops = [
            new MergeOperation(MergeOperation::TYPE_INSERT, 'unknown', '1', ['id' => '1']),
            new MergeOperation(MergeOperation::TYPE_INSERT, 'known', '1', ['id' => '1']),
        ];

        $sorted = ForeignKeyResolver::sortOperations($tableOrder, $ops);

        $this->assertSame('known', $sorted[0]->table);
        $this->assertSame('unknown', $sorted[1]->table);
    }

    #[Test]
    public function multi_level_dependency_chain(): void
    {
        $deps = [
            'level3' => ['level2'],
            'level2' => ['level1'],
            'level1' => [],
        ];

        $order = ForeignKeyResolver::topologicalSort($deps, ['level3', 'level2', 'level1']);

        $l1 = array_search('level1', $order);
        $l2 = array_search('level2', $order);
        $l3 = array_search('level3', $order);

        $this->assertLessThan($l2, $l1);
        $this->assertLessThan($l3, $l2);
    }
}
