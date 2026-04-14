<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Identity;

use Merql\Identity\PrimaryKeyIdentity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PrimaryKeyIdentityTest extends TestCase
{
    #[Test]
    public function single_column_key(): void
    {
        $identity = new PrimaryKeyIdentity(['id']);

        $key = $identity->key(['id' => '42', 'title' => 'Hello']);

        $this->assertSame('42', $key);
    }

    #[Test]
    public function composite_key(): void
    {
        $identity = new PrimaryKeyIdentity(['post_id', 'meta_key']);

        $key = $identity->key(['post_id' => '1', 'meta_key' => 'color', 'meta_value' => 'red']);

        $this->assertSame("1\x1Fcolor", $key);
    }

    #[Test]
    public function returns_columns(): void
    {
        $identity = new PrimaryKeyIdentity(['id']);

        $this->assertSame(['id'], $identity->columns());
    }
}
