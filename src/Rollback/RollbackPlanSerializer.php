<?php

declare(strict_types=1);

namespace Merql\Rollback;

/**
 * JSON serializer for rollback plans.
 */
final class RollbackPlanSerializer
{
    public static function toJson(RollbackPlan $plan): string
    {
        return json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): RollbackPlan
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Rollback plan JSON must decode to an object.');
        }

        return self::fromArray($data);
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function fromArray(array $data): RollbackPlan
    {
        return new RollbackPlan(
            self::stringValue($data['id'] ?? ''),
            self::stringValue($data['merge_plan_id'] ?? ''),
            self::stringList($data['selected_operation_ids'] ?? []),
            self::stringList($data['tables'] ?? []),
            self::operations($data['operations'] ?? []),
            self::intValue($data['created_at'] ?? 0),
            self::stringValue($data['hash'] ?? ''),
            self::metadata($data['metadata'] ?? []),
            self::intValue($data['schema_version'] ?? RollbackPlan::SCHEMA_VERSION),
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
     * @return list<RollbackOperation>
     */
    private static function operations(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $operations = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $operations[] = RollbackOperation::fromArray(self::stringKeyedArray($item));
            }
        }

        return $operations;
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
