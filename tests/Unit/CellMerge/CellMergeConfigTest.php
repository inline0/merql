<?php

declare(strict_types=1);

namespace Merql\Tests\Unit\CellMerge;

use Merql\CellMerge\CellMergeConfig;
use Merql\CellMerge\JsonCellMerger;
use Merql\CellMerge\OpaqueCellMerger;
use Merql\CellMerge\TextCellMerger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CellMergeConfigTest extends TestCase
{
    #[Test]
    public function default_returns_opaque_merger(): void
    {
        $config = new CellMergeConfig();

        $merger = $config->getMerger('posts', 'title', 'varchar(255)');

        $this->assertInstanceOf(OpaqueCellMerger::class, $merger);
    }

    #[Test]
    public function column_override(): void
    {
        $config = (new CellMergeConfig())
            ->forColumn('content', new TextCellMerger());

        $this->assertInstanceOf(TextCellMerger::class, $config->getMerger('posts', 'content'));
        $this->assertInstanceOf(OpaqueCellMerger::class, $config->getMerger('posts', 'title'));
    }

    #[Test]
    public function qualified_column_takes_priority(): void
    {
        $config = (new CellMergeConfig())
            ->forColumn('content', new TextCellMerger())
            ->forColumn('settings.content', new JsonCellMerger());

        $this->assertInstanceOf(JsonCellMerger::class, $config->getMerger('settings', 'content'));
        $this->assertInstanceOf(TextCellMerger::class, $config->getMerger('posts', 'content'));
    }

    #[Test]
    public function type_based_matching(): void
    {
        $config = (new CellMergeConfig())
            ->forType('json', new JsonCellMerger())
            ->forType('text', new TextCellMerger());

        $this->assertInstanceOf(JsonCellMerger::class, $config->getMerger('t', 'data', 'json'));
        $this->assertInstanceOf(TextCellMerger::class, $config->getMerger('t', 'body', 'longtext'));
        $this->assertInstanceOf(TextCellMerger::class, $config->getMerger('t', 'desc', 'text'));
        $this->assertInstanceOf(OpaqueCellMerger::class, $config->getMerger('t', 'id', 'int'));
    }

    #[Test]
    public function auto_config(): void
    {
        $config = CellMergeConfig::auto();

        $this->assertInstanceOf(TextCellMerger::class, $config->getMerger('t', 'body', 'text'));
        $this->assertInstanceOf(TextCellMerger::class, $config->getMerger('t', 'body', 'longtext'));
        $this->assertInstanceOf(JsonCellMerger::class, $config->getMerger('t', 'data', 'json'));
        $this->assertInstanceOf(OpaqueCellMerger::class, $config->getMerger('t', 'name', 'varchar(255)'));
    }

    #[Test]
    public function column_takes_priority_over_type(): void
    {
        $config = (new CellMergeConfig())
            ->forType('text', new TextCellMerger())
            ->forColumn('notes', new JsonCellMerger());

        // Column override wins even though type would match TextCellMerger.
        $this->assertInstanceOf(JsonCellMerger::class, $config->getMerger('t', 'notes', 'text'));
    }
}
