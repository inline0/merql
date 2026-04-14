<?php

declare(strict_types=1);

namespace Merql\Snapshot;

/**
 * Hash of row content for fast change detection.
 */
final class RowFingerprint
{
    /**
     * Compute a fingerprint for a row's data.
     *
     * @param array<string, mixed> $data Column name to value mapping.
     */
    public static function compute(array $data, string $algo = 'sha256'): string
    {
        $normalized = self::normalize($data);

        return hash($algo, $normalized);
    }

    /**
     * Normalize row data to a deterministic string representation.
     * NULL is represented distinctly from empty string or "null".
     *
     * @param array<string, mixed> $data
     */
    private static function normalize(array $data): string
    {
        ksort($data);

        $parts = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                $parts[] = $key . "=\x00NULL\x00";
            } else {
                $parts[] = $key . '=' . (string) $value;
            }
        }

        return implode("\x01", $parts);
    }
}
