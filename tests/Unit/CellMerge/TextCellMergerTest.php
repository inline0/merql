<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\CellMerge;

use Merql\CellMerge\TextCellMerger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextCellMergerTest extends TestCase
{
    private TextCellMerger $merger;

    protected function setUp(): void
    {
        $this->merger = new TextCellMerger();
    }

    #[Test]
    public function different_lines_merge_cleanly(): void
    {
        $base = "line1\nline2\nline3";
        $ours = "line1\nours_line2\nline3";
        $theirs = "line1\nline2\ntheirs_line3";

        $result = $this->merger->merge($base, $ours, $theirs);

        $this->assertTrue($result->clean);
        $this->assertStringContainsString('ours_line2', $result->value);
        $this->assertStringContainsString('theirs_line3', $result->value);
    }

    #[Test]
    public function same_line_different_values_conflicts(): void
    {
        $base = "line1\noriginal\nline3";
        $ours = "line1\nours_change\nline3";
        $theirs = "line1\ntheirs_change\nline3";

        $result = $this->merger->merge($base, $ours, $theirs);

        $this->assertFalse($result->clean);
        $this->assertGreaterThan(0, $result->conflicts);
    }

    #[Test]
    public function identical_changes_merge_cleanly(): void
    {
        $base = "line1\noriginal\nline3";
        $ours = "line1\nsame_change\nline3";
        $theirs = "line1\nsame_change\nline3";

        $result = $this->merger->merge($base, $ours, $theirs);

        $this->assertTrue($result->clean);
        $this->assertStringContainsString('same_change', $result->value);
    }

    #[Test]
    public function only_ours_changed_accepts_ours(): void
    {
        $base = "line1\nline2";
        $ours = "line1\nours_changed";
        $theirs = "line1\nline2";

        $result = $this->merger->merge($base, $ours, $theirs);

        $this->assertTrue($result->clean);
        $this->assertStringContainsString('ours_changed', $result->value);
    }

    #[Test]
    public function only_theirs_changed_accepts_theirs(): void
    {
        $base = "line1\nline2";
        $ours = "line1\nline2";
        $theirs = "line1\ntheirs_changed";

        $result = $this->merger->merge($base, $ours, $theirs);

        $this->assertTrue($result->clean);
        $this->assertStringContainsString('theirs_changed', $result->value);
    }

    #[Test]
    public function same_line_in_multiline_conflicts(): void
    {
        $base = "line1\nshared\nline3";
        $ours = "line1\nours_changed\nline3";
        $theirs = "line1\ntheirs_changed\nline3";

        $result = $this->merger->merge($base, $ours, $theirs);

        $this->assertFalse($result->clean);
        $this->assertGreaterThan(0, $result->conflicts);
    }

    #[Test]
    public function handles_null_base_only_one_changed(): void
    {
        // null base, only theirs has content.
        $result = $this->merger->merge(null, '', "theirs\n");

        $this->assertTrue($result->clean);
        $this->assertStringContainsString('theirs', $result->value);
    }

    #[Test]
    public function ours_adds_lines_at_end_theirs_unchanged(): void
    {
        $base = "line1\nline2";
        $ours = "line1\nline2\nline3_new";
        $theirs = "line1\nline2";

        $result = $this->merger->merge($base, $ours, $theirs);

        $this->assertTrue($result->clean);
        $this->assertStringContainsString('line3_new', $result->value);
    }
}
