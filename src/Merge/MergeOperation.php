<?php

declare(strict_types=1);

namespace Merql\Merge;

/**
 * A single resolved merge operation.
 */
final readonly class MergeOperation
{
    public const TYPE_INSERT = 'insert';
    public const TYPE_UPDATE = 'update';
    public const TYPE_DELETE = 'delete';

    /**
     * @param string $type insert|update|delete
     * @param string $table Table name.
     * @param string $rowKey Row identity key.
     * @param array<string, mixed> $values Column values for the operation.
     * @param string $source "ours"|"theirs"|"merged"
     */
    public function __construct(
        public string $type,
        public string $table,
        public string $rowKey,
        public array $values,
        public string $source = 'merged',
    ) {
    }
}
