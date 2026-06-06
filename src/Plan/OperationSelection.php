<?php

declare(strict_types=1);

namespace Merql\Plan;

/**
 * Selected merge operation IDs.
 */
final readonly class OperationSelection
{
    /**
     * @param list<string> $operationIds
     */
    public function __construct(
        public array $operationIds,
    ) {
    }

    /**
     * @param list<string> $operationIds
     */
    public static function fromIds(array $operationIds): self
    {
        $unique = array_values(array_unique($operationIds));
        sort($unique);

        return new self($unique);
    }

    public function contains(string $id): bool
    {
        return in_array($id, $this->operationIds, true);
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->operationIds, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{operation_ids: list<string>, hash: string}
     */
    public function toArray(): array
    {
        return [
            'operation_ids' => $this->operationIds,
            'hash' => $this->hash(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return self::fromIds(self::stringList($data['operation_ids'] ?? []));
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
