<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\MigrationForecast\Model\Data;

/**
 * One pending DDL change, classified against MySQL's online-DDL matrix.
 */
class SchemaChange
{
    public const KIND_NEW_TABLE = 'new_table';
    public const KIND_DROP_TABLE = 'drop_table';
    public const KIND_MODIFY_TABLE = 'modify_table';
    public const KIND_RECREATE_TABLE = 'recreate_table';
    public const KIND_ADD_COLUMN = 'add_column';
    public const KIND_DROP_COLUMN = 'drop_column';
    public const KIND_MODIFY_COLUMN = 'modify_column';
    public const KIND_ADD_INDEX = 'add_index';
    public const KIND_DROP_INDEX = 'drop_index';
    public const KIND_ADD_CONSTRAINT = 'add_constraint';
    public const KIND_DROP_CONSTRAINT = 'drop_constraint';

    public const ALGORITHM_METADATA = 'metadata';
    public const ALGORITHM_INSTANT = 'instant';
    public const ALGORITHM_INPLACE = 'inplace';
    public const ALGORITHM_COPY = 'copy';

    /**
     * @param string $table Table name as it exists in the database (prefix included).
     * @param string $kind One of the KIND_* constants.
     * @param string $name Column/index/constraint name ("" for table-level changes).
     * @param string $algorithm One of the ALGORITHM_* constants.
     * @param bool $rowScaled Whether the cost grows with the table's row count.
     * @param bool $rebuildsTable Whether MySQL rebuilds the whole table.
     * @param bool $blocksWrites Whether concurrent DML is blocked while it runs.
     * @param string $note Why it was classified this way, when not obvious.
     * @param int|null $rows Approximate row count (null when unknown).
     * @param float $estSeconds Estimated duration of this change on its own.
     */
    public function __construct(
        public readonly string $table,
        public readonly string $kind,
        public readonly string $name,
        public readonly string $algorithm,
        public readonly bool $rowScaled,
        public readonly bool $rebuildsTable,
        public readonly bool $blocksWrites,
        public readonly string $note = '',
        public readonly ?int $rows = null,
        public readonly float $estSeconds = 0.0
    ) {
    }

    /**
     * Human label for the summary line and the table output.
     *
     * @return string
     */
    public function getImpact(): string
    {
        if ($this->blocksWrites) {
            return 'blocking';
        }

        return match ($this->algorithm) {
            self::ALGORITHM_INSTANT => 'instant',
            self::ALGORITHM_METADATA => 'metadata',
            default => 'online',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'kind' => $this->kind,
            'name' => $this->name,
            'algorithm' => $this->algorithm,
            'impact' => $this->getImpact(),
            'rebuilds_table' => $this->rebuildsTable,
            'blocks_writes' => $this->blocksWrites,
            'rows' => $this->rows,
            'est_secs' => round($this->estSeconds, 1),
            'note' => $this->note,
        ];
    }
}
