<?php

declare(strict_types=1);

namespace Merql\CellMerge;

/**
 * Merges JSON values by comparing keys independently.
 *
 * Each top-level key is merged using the same three-way logic:
 * - Only ours changed a key: accept ours
 * - Only theirs changed a key: accept theirs
 * - Both changed to same value: accept
 * - Both changed to different values: conflict
 * - Key added by one side: accept
 * - Key removed by one side: accept removal
 *
 * Nested objects are compared as opaque JSON strings (not recursive).
 */
final class JsonCellMerger implements CellMerger
{
    public function merge(mixed $base, mixed $ours, mixed $theirs): CellMergeResult
    {
        $baseObj = self::decode($base);
        $oursObj = self::decode($ours);
        $theirsObj = self::decode($theirs);

        if ($baseObj === null || $oursObj === null || $theirsObj === null) {
            // Not valid JSON on all three sides: fall back to opaque.
            return CellMergeResult::conflict($ours);
        }

        if (!is_array($baseObj) || !is_array($oursObj) || !is_array($theirsObj)) {
            // Non-object/array JSON (scalar): can't merge keys.
            return CellMergeResult::conflict($ours);
        }

        $allKeys = array_unique(array_merge(
            array_keys($baseObj),
            array_keys($oursObj),
            array_keys($theirsObj),
        ));

        $merged = [];
        $conflicts = 0;

        foreach ($allKeys as $key) {
            $baseVal = $baseObj[$key] ?? null;
            $oursVal = $oursObj[$key] ?? null;
            $theirsVal = $theirsObj[$key] ?? null;

            $baseHas = array_key_exists($key, $baseObj);
            $oursHas = array_key_exists($key, $oursObj);
            $theirsHas = array_key_exists($key, $theirsObj);

            $oursChanged = self::changed($baseVal, $oursVal, $baseHas, $oursHas);
            $theirsChanged = self::changed($baseVal, $theirsVal, $baseHas, $theirsHas);

            if (!$oursChanged && !$theirsChanged) {
                // Neither changed.
                if ($baseHas) {
                    $merged[$key] = $baseVal;
                }
            } elseif (!$oursChanged) {
                // Only theirs changed.
                if ($theirsHas) {
                    $merged[$key] = $theirsVal;
                }
                // If theirs removed the key, it stays removed.
            } elseif (!$theirsChanged) {
                // Only ours changed.
                if ($oursHas) {
                    $merged[$key] = $oursVal;
                }
            } elseif (self::valuesEqual($oursVal, $theirsVal) && $oursHas === $theirsHas) {
                // Both changed to the same thing.
                if ($oursHas) {
                    $merged[$key] = $oursVal;
                }
            } else {
                // Both changed differently: conflict. Default to ours.
                $conflicts++;
                if ($oursHas) {
                    $merged[$key] = $oursVal;
                }
            }
        }

        $encoded = json_encode($merged, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        if ($conflicts > 0) {
            return CellMergeResult::conflict($encoded, $conflicts);
        }

        return CellMergeResult::resolved($encoded);
    }

    private static function decode(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    private static function changed(mixed $baseVal, mixed $currentVal, bool $baseHas, bool $currentHas): bool
    {
        if ($baseHas !== $currentHas) {
            return true;
        }

        return !self::valuesEqual($baseVal, $currentVal);
    }

    private static function valuesEqual(mixed $a, mixed $b): bool
    {
        return json_encode($a, JSON_THROW_ON_ERROR) === json_encode($b, JSON_THROW_ON_ERROR);
    }
}
