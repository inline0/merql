<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Identity;

use Merql\Identity\ContentHashIdentity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContentHashIdentityTest extends TestCase
{
    #[Test]
    public function produces_consistent_key(): void
    {
        $identity = new ContentHashIdentity(['name', 'value']);
        $row = ['name' => 'Alice', 'value' => '42'];

        $a = $identity->key($row);
        $b = $identity->key($row);

        $this->assertSame($a, $b);
    }

    #[Test]
    public function different_data_produces_different_key(): void
    {
        $identity = new ContentHashIdentity(['name', 'value']);

        $a = $identity->key(['name' => 'Alice', 'value' => '42']);
        $b = $identity->key(['name' => 'Bob', 'value' => '42']);

        $this->assertNotSame($a, $b);
    }

    #[Test]
    public function key_is_independent_of_column_order(): void
    {
        $identity = new ContentHashIdentity(['name', 'value']);

        $a = $identity->key(['name' => 'Alice', 'value' => '42']);
        $b = $identity->key(['value' => '42', 'name' => 'Alice']);

        $this->assertSame($a, $b);
    }

    #[Test]
    public function returns_all_columns(): void
    {
        $identity = new ContentHashIdentity(['name', 'value', 'extra']);

        $this->assertSame(['name', 'value', 'extra'], $identity->columns());
    }

    #[Test]
    public function null_is_distinct_from_empty(): void
    {
        $identity = new ContentHashIdentity(['name']);

        $a = $identity->key(['name' => null]);
        $b = $identity->key(['name' => '']);

        $this->assertNotSame($a, $b);
    }
}
