<?php

declare(strict_types=1);

namespace Merql\Plan;

/**
 * Serializable merge plan.
 */
final readonly class MergePlan
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param list<string> $tables
     * @param list<MergePlanChangeGroup> $changeGroups
     * @param list<MergePlanOperation> $operations
     * @param list<MergePlanConflict> $conflicts
     * @param list<string> $schemaMismatches
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $baseSnapshot,
        public string $oursSnapshot,
        public string $theirsSnapshot,
        public int $createdAt,
        public array $tables,
        public MergePlanSummary $summary,
        public array $changeGroups,
        public array $operations,
        public array $conflicts,
        public array $schemaMismatches,
        public string $hash,
        public array $metadata = [],
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {
    }

    public function operation(string $id): ?MergePlanOperation
    {
        foreach ($this->operations as $operation) {
            if ($operation->id === $id) {
                return $operation;
            }
        }

        return null;
    }

    public function changeGroup(string $id): ?MergePlanChangeGroup
    {
        foreach ($this->changeGroups as $group) {
            if ($group->id === $id) {
                return $group;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function operationIdsForGroups(ChangeGroupSelection $selection): array
    {
        $selected = [];
        foreach ($this->changeGroups as $group) {
            if (!$selection->contains($group->id)) {
                continue;
            }

            foreach ($group->operationIds as $operationId) {
                $selected[$operationId] = true;
            }
        }

        return array_keys($selected);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'id' => $this->id,
            'base_snapshot' => $this->baseSnapshot,
            'ours_snapshot' => $this->oursSnapshot,
            'theirs_snapshot' => $this->theirsSnapshot,
            'created_at' => $this->createdAt,
            'tables' => $this->tables,
            'summary' => $this->summary->toArray(),
            'change_groups' => array_map(
                static fn(MergePlanChangeGroup $group): array => $group->toArray(),
                $this->changeGroups,
            ),
            'operations' => array_map(
                static fn(MergePlanOperation $operation): array => $operation->toArray(),
                $this->operations,
            ),
            'conflicts' => array_map(
                static fn(MergePlanConflict $conflict): array => $conflict->toArray(),
                $this->conflicts,
            ),
            'schema_mismatches' => $this->schemaMismatches,
            'hash' => $this->hash,
            'metadata' => $this->metadata,
        ];
    }
}
