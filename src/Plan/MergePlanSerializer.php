<?php

declare(strict_types=1);

namespace Merql\Plan;

/**
 * JSON serializer for merge plans.
 */
final class MergePlanSerializer
{
    public static function toJson(MergePlan $plan): string
    {
        return json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): MergePlan
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Merge plan JSON must decode to an object.');
        }

        return self::fromArray($data);
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function fromArray(array $data): MergePlan
    {
        $summaryData = $data['summary'] ?? [];
        $summary = is_array($summaryData)
            ? MergePlanSummary::fromArray(self::stringKeyedArray($summaryData))
            : new MergePlanSummary(0, 0, 0, 0, [], []);

        return new MergePlan(
            self::stringValue($data['id'] ?? ''),
            self::stringValue($data['base_snapshot'] ?? ''),
            self::stringValue($data['ours_snapshot'] ?? ''),
            self::stringValue($data['theirs_snapshot'] ?? ''),
            self::intValue($data['created_at'] ?? 0),
            self::stringList($data['tables'] ?? []),
            $summary,
            self::changeGroups($data['change_groups'] ?? []),
            self::operations($data['operations'] ?? []),
            self::conflicts($data['conflicts'] ?? []),
            self::stringList($data['schema_mismatches'] ?? []),
            self::stringValue($data['hash'] ?? ''),
            self::metadata($data['metadata'] ?? []),
            self::intValue($data['schema_version'] ?? MergePlan::SCHEMA_VERSION),
        );
    }

    private static function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
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

    /**
     * @param mixed $value
     * @return list<MergePlanChangeGroup>
     */
    private static function changeGroups(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $groups = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $groups[] = MergePlanChangeGroup::fromArray(self::stringKeyedArray($item));
            }
        }

        return $groups;
    }

    /**
     * @param mixed $value
     * @return list<MergePlanOperation>
     */
    private static function operations(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $operations = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $operations[] = MergePlanOperation::fromArray(self::stringKeyedArray($item));
            }
        }

        return $operations;
    }

    /**
     * @param mixed $value
     * @return list<MergePlanConflict>
     */
    private static function conflicts(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $conflicts = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $conflicts[] = MergePlanConflict::fromArray(self::stringKeyedArray($item));
            }
        }

        return $conflicts;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private static function metadata(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $metadata = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $metadata[$key] = $item;
            }
        }

        return $metadata;
    }

    /**
     * @param array<int|string, mixed> $value
     * @return array<string, mixed>
     */
    private static function stringKeyedArray(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $out[$key] = $item;
            }
        }

        return $out;
    }
}
