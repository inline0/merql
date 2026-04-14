<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\Identity;

use Merql\Identity\NaturalKeyIdentity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NaturalKeyIdentityTest extends TestCase
{
    #[Test]
    public function builds_key_from_unique_columns(): void
    {
        $identity = new NaturalKeyIdentity(['email']);

        $key = $identity->key(['id' => '1', 'email' => 'a@b.com', 'name' => 'Alice']);

        $this->assertSame('a@b.com', $key);
    }

    #[Test]
    public function multi_column_natural_key(): void
    {
        $identity = new NaturalKeyIdentity(['first_name', 'last_name']);

        $key = $identity->key(['first_name' => 'John', 'last_name' => 'Doe', 'age' => '30']);

        $this->assertSame("John\x1FDoe", $key);
    }

    #[Test]
    public function returns_columns(): void
    {
        $identity = new NaturalKeyIdentity(['email']);

        $this->assertSame(['email'], $identity->columns());
    }
}
