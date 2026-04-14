<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Snapshot;

use Merql\Snapshot\RowFingerprint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RowFingerprintTest extends TestCase
{
    #[Test]
    public function identical_data_produces_same_fingerprint(): void
    {
        $data = ['id' => '1', 'title' => 'Hello', 'status' => 'draft'];

        $a = RowFingerprint::compute($data);
        $b = RowFingerprint::compute($data);

        $this->assertSame($a, $b);
    }

    #[Test]
    public function different_data_produces_different_fingerprint(): void
    {
        $a = RowFingerprint::compute(['id' => '1', 'title' => 'Hello']);
        $b = RowFingerprint::compute(['id' => '1', 'title' => 'World']);

        $this->assertNotSame($a, $b);
    }

    #[Test]
    public function column_order_does_not_matter(): void
    {
        $a = RowFingerprint::compute(['id' => '1', 'title' => 'Hello']);
        $b = RowFingerprint::compute(['title' => 'Hello', 'id' => '1']);

        $this->assertSame($a, $b);
    }

    #[Test]
    public function null_is_distinct_from_empty_string(): void
    {
        $a = RowFingerprint::compute(['id' => '1', 'title' => null]);
        $b = RowFingerprint::compute(['id' => '1', 'title' => '']);

        $this->assertNotSame($a, $b);
    }

    #[Test]
    public function null_is_distinct_from_string_null(): void
    {
        $a = RowFingerprint::compute(['id' => '1', 'title' => null]);
        $b = RowFingerprint::compute(['id' => '1', 'title' => 'NULL']);

        $this->assertNotSame($a, $b);
    }

    #[Test]
    public function fingerprint_is_hex_string(): void
    {
        $fp = RowFingerprint::compute(['id' => '1']);

        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $fp);
    }
}
