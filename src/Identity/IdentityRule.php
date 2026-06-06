<?php

declare(strict_types=1);

namespace Merql\Identity;

use Merql\Schema\TableSchema;

/**
 * Table identity rule.
 */
final readonly class IdentityRule
{
    public const TYPE_PRIMARY = 'primary';
    public const TYPE_NATURAL = 'natural';
    public const TYPE_COMPOSITE = 'composite';
    public const TYPE_CONTENT = 'content';

    /**
     * @param string $type primary|natural|composite|content
     * @param list<string> $columns Columns used for identity.
     */
    public function __construct(
        public string $type,
        public array $columns,
    ) {
    }

    /**
     * @param list<string> $columns
     */
    public static function primary(array $columns): self
    {
        return new self(self::TYPE_PRIMARY, $columns);
    }

    /**
     * @param list<string> $columns
     */
    public static function natural(array $columns): self
    {
        return new self(self::TYPE_NATURAL, $columns);
    }

    /**
     * @param list<string> $columns
     */
    public static function composite(array $columns): self
    {
        return new self(self::TYPE_COMPOSITE, $columns);
    }

    /**
     * @param list<string> $columns
     */
    public static function content(array $columns): self
    {
        return new self(self::TYPE_CONTENT, $columns);
    }

    public static function forSchema(TableSchema $schema): self
    {
        if ($schema->primaryKey !== []) {
            return self::primary($schema->primaryKey);
        }

        if ($schema->uniqueKeys !== []) {
            return self::natural($schema->uniqueKeys[0]);
        }

        return self::content($schema->columnNames());
    }

    public function rowIdentity(): RowIdentity
    {
        return match ($this->type) {
            self::TYPE_PRIMARY => new PrimaryKeyIdentity($this->columns),
            self::TYPE_NATURAL, self::TYPE_COMPOSITE => new NaturalKeyIdentity($this->columns),
            self::TYPE_CONTENT => new ContentHashIdentity($this->columns),
            default => new ContentHashIdentity($this->columns),
        };
    }

    /**
     * @param array<string, scalar|null> $row
     */
    public function key(array $row): string
    {
        return $this->rowIdentity()->key($row);
    }

    /**
     * @return array{type: string, columns: list<string>}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'columns' => $this->columns,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $type = is_string($data['type'] ?? null) ? $data['type'] : self::TYPE_CONTENT;
        $columns = self::stringList($data['columns'] ?? []);

        return new self($type, $columns);
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }
}
