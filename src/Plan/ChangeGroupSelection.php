<?php

declare(strict_types=1);

namespace Merql\Plan;

/**
 * Selected merge change group IDs.
 */
final readonly class ChangeGroupSelection
{
    /**
     * @param list<string> $changeGroupIds
     */
    public function __construct(
        public array $changeGroupIds,
    ) {
    }

    /**
     * @param list<string> $changeGroupIds
     */
    public static function fromIds(array $changeGroupIds): self
    {
        $unique = array_values(array_unique($changeGroupIds));
        sort($unique);

        return new self($unique);
    }

    public function contains(string $id): bool
    {
        return in_array($id, $this->changeGroupIds, true);
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->changeGroupIds, JSON_THROW_ON_ERROR));
    }

    public function toOperationSelection(MergePlan $plan): OperationSelection
    {
        return OperationSelection::fromIds($plan->operationIdsForGroups($this));
    }

    /**
     * @return array{change_group_ids: list<string>, hash: string}
     */
    public function toArray(): array
    {
        return [
            'change_group_ids' => $this->changeGroupIds,
            'hash' => $this->hash(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return self::fromIds(self::stringList($data['change_group_ids'] ?? []));
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
