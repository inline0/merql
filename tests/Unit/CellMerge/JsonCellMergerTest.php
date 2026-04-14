<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\CellMerge;

use Merql\CellMerge\JsonCellMerger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JsonCellMergerTest extends TestCase
{
    private JsonCellMerger $merger;

    protected function setUp(): void
    {
        $this->merger = new JsonCellMerger();
    }

    #[Test]
    public function different_keys_merge_cleanly(): void
    {
        $base = '{"theme":"light","lang":"en"}';
        $ours = '{"theme":"light","lang":"fr"}';
        $theirs = '{"theme":"dark","lang":"en"}';

        $result = $this->merger->merge($base, $ours, $theirs);

        $this->assertTrue($result->clean);
        $merged = json_decode($result->value, true);
        $this->assertSame('dark', $merged['theme']);
        $this->assertSame('fr', $merged['lang']);
    }

    #[Test]
    public function same_key_different_values_conflicts(): void
    {
        $base = '{"theme":"light"}';
        $ours = '{"theme":"dark"}';
        $theirs = '{"theme":"blue"}';

        $result = $this->merger->merge($base, $ours, $theirs);

        $this->assertFalse($result->clean);
        $this->assertSame(1, $result->conflicts);
    }

    #[Test]
    public function key_added_by_theirs(): void
    {
        $base = '{"a":"1"}';
        $ours = '{"a":"1"}';
        $theirs = '{"a":"1","b":"2"}';

        $result = $this->merger->merge($base, $ours, $theirs);

        $this->assertTrue($result->clean);
        $merged = json_decode($result->value, true);
        $this->assertSame('2', $merged['b']);
    }

    #[Test]
    public function key_removed_by_ours(): void
    {
        $base = '{"a":"1","b":"2"}';
        $ours = '{"a":"1"}';
        $theirs = '{"a":"1","b":"2"}';

        $result = $this->merger->merge($base, $ours, $theirs);

        $this->assertTrue($result->clean);
        $merged = json_decode($result->value, true);
        $this->assertArrayNotHasKey('b', $merged);
    }

    #[Test]
    public function both_add_same_key_same_value(): void
    {
        $base = '{"a":"1"}';
        $ours = '{"a":"1","b":"2"}';
        $theirs = '{"a":"1","b":"2"}';

        $result = $this->merger->merge($base, $ours, $theirs);

        $this->assertTrue($result->clean);
    }

    #[Test]
    public function both_add_same_key_different_value_conflicts(): void
    {
        $base = '{"a":"1"}';
        $ours = '{"a":"1","b":"2"}';
        $theirs = '{"a":"1","b":"3"}';

        $result = $this->merger->merge($base, $ours, $theirs);

        $this->assertFalse($result->clean);
    }

    #[Test]
    public function invalid_json_falls_back_to_conflict(): void
    {
        $result = $this->merger->merge('not json', 'also not', 'nope');

        $this->assertFalse($result->clean);
    }

    #[Test]
    public function scalar_json_falls_back_to_conflict(): void
    {
        $result = $this->merger->merge('"hello"', '"ours"', '"theirs"');

        $this->assertFalse($result->clean);
    }

    #[Test]
    public function nested_objects_compared_as_opaque(): void
    {
        $base = '{"config":{"a":1,"b":2}}';
        $ours = '{"config":{"a":1,"b":3}}';
        $theirs = '{"config":{"a":2,"b":2}}';

        $result = $this->merger->merge($base, $ours, $theirs);

        // Both changed "config" to different nested values: conflict.
        $this->assertFalse($result->clean);
    }

    #[Test]
    public function preserves_unicode(): void
    {
        $base = '{"name":"Hello"}';
        $ours = '{"name":"Hello"}';
        $theirs = '{"name":"Héllo 🌍"}';

        $result = $this->merger->merge($base, $ours, $theirs);

        $this->assertTrue($result->clean);
        $this->assertStringContainsString('🌍', $result->value);
    }
}
