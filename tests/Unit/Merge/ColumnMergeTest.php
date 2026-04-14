<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Merge;

use Merql\Merge\ColumnMerge;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ColumnMergeTest extends TestCase
{
    #[Test]
    public function no_changes_keeps_base(): void
    {
        $base = ['title' => 'Hello', 'status' => 'draft'];

        $result = ColumnMerge::merge('t', 'k', $base, $base, $base);

        $this->assertSame($base, $result['values']);
        $this->assertEmpty($result['conflicts']);
    }

    #[Test]
    public function only_theirs_changed_accepts_theirs(): void
    {
        $base = ['title' => 'Hello', 'status' => 'draft'];
        $ours = ['title' => 'Hello', 'status' => 'draft'];
        $theirs = ['title' => 'World', 'status' => 'draft'];

        $result = ColumnMerge::merge('t', 'k', $base, $ours, $theirs);

        $this->assertSame('World', $result['values']['title']);
        $this->assertEmpty($result['conflicts']);
    }

    #[Test]
    public function only_ours_changed_accepts_ours(): void
    {
        $base = ['title' => 'Hello', 'status' => 'draft'];
        $ours = ['title' => 'Mine', 'status' => 'draft'];
        $theirs = ['title' => 'Hello', 'status' => 'draft'];

        $result = ColumnMerge::merge('t', 'k', $base, $ours, $theirs);

        $this->assertSame('Mine', $result['values']['title']);
        $this->assertEmpty($result['conflicts']);
    }

    #[Test]
    public function both_same_value_no_conflict(): void
    {
        $base = ['title' => 'Hello', 'status' => 'draft'];
        $ours = ['title' => 'Same', 'status' => 'draft'];
        $theirs = ['title' => 'Same', 'status' => 'draft'];

        $result = ColumnMerge::merge('t', 'k', $base, $ours, $theirs);

        $this->assertSame('Same', $result['values']['title']);
        $this->assertEmpty($result['conflicts']);
    }

    #[Test]
    public function both_different_values_conflict(): void
    {
        $base = ['title' => 'Hello', 'status' => 'draft'];
        $ours = ['title' => 'Mine', 'status' => 'draft'];
        $theirs = ['title' => 'Theirs', 'status' => 'draft'];

        $result = ColumnMerge::merge('posts', '1', $base, $ours, $theirs);

        $this->assertCount(1, $result['conflicts']);
        $conflict = $result['conflicts'][0];
        $this->assertSame('title', $conflict->column());
        $this->assertSame('Mine', $conflict->oursValue());
        $this->assertSame('Theirs', $conflict->theirsValue());
        $this->assertSame('Hello', $conflict->baseValue());
    }

    #[Test]
    public function different_columns_changed_merge_cleanly(): void
    {
        $base = ['title' => 'Hello', 'content' => 'Body', 'status' => 'draft'];
        $ours = ['title' => 'Hello', 'content' => 'Body v2', 'status' => 'draft'];
        $theirs = ['title' => 'New Title', 'content' => 'Body', 'status' => 'publish'];

        $result = ColumnMerge::merge('t', 'k', $base, $ours, $theirs);

        $this->assertEmpty($result['conflicts']);
        $this->assertSame('New Title', $result['values']['title']);
        $this->assertSame('Body v2', $result['values']['content']);
        $this->assertSame('publish', $result['values']['status']);
    }

    #[Test]
    public function null_transitions_handled(): void
    {
        $base = ['title' => null, 'status' => 'draft'];
        $ours = ['title' => null, 'status' => 'draft'];
        $theirs = ['title' => 'Now Set', 'status' => 'draft'];

        $result = ColumnMerge::merge('t', 'k', $base, $ours, $theirs);

        $this->assertSame('Now Set', $result['values']['title']);
        $this->assertEmpty($result['conflicts']);
    }
}
