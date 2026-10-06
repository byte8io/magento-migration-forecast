<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\MigrationForecast\Model;

use Byte8\MigrationForecast\Model\Data\SchemaChange;

/**
 * Turns classified changes into seconds.
 *
 * Throughputs are deliberately conservative for mid-range Magento hosting;
 * override them per host with the command's --inplace-rate / --copy-rate.
 */
class CostModel
{
    public const DEFAULT_INPLACE_ROWS_PER_SEC = 100000.0;
    public const DEFAULT_COPY_ROWS_PER_SEC = 50000.0;

    /** Fixed overhead of the setup:upgrade bootstrap itself. */
    public const BOOTSTRAP_OVERHEAD_SECS = 3.0;

    private const CHEAP_SECS = 0.1;
    private const INPLACE_MIN_SECS = 1.0;
    private const COPY_MIN_SECS = 2.0;
    /** Used when the row count is unknown. */
    private const INPLACE_DEFAULT_SECS = 5.0;
    private const COPY_DEFAULT_SECS = 15.0;

    /**
     * @param float $inplaceRowsPerSec
     * @param float $copyRowsPerSec
     */
    public function __construct(
        private float $inplaceRowsPerSec = self::DEFAULT_INPLACE_ROWS_PER_SEC,
        private float $copyRowsPerSec = self::DEFAULT_COPY_ROWS_PER_SEC
    ) {
    }

    /**
     * @param float $inplaceRowsPerSec
     * @param float $copyRowsPerSec
     * @return self
     */
    public function withRates(float $inplaceRowsPerSec, float $copyRowsPerSec): self
    {
        $clone = clone $this;
        $clone->inplaceRowsPerSec = max(1.0, $inplaceRowsPerSec);
        $clone->copyRowsPerSec = max(1.0, $copyRowsPerSec);

        return $clone;
    }

    /**
     * Attach a row count and a duration to a classified change.
     *
     * @param SchemaChange $change
     * @param int|null $rows
     * @return SchemaChange
     */
    public function cost(SchemaChange $change, ?int $rows): SchemaChange
    {
        if (!$change->rowScaled) {
            $seconds = self::CHEAP_SECS;
        } elseif ($change->algorithm === SchemaChange::ALGORITHM_COPY) {
            $seconds = $rows === null
                ? self::COPY_DEFAULT_SECS
                : max(self::COPY_MIN_SECS, $rows / $this->copyRowsPerSec);
        } else {
            $seconds = $rows === null
                ? self::INPLACE_DEFAULT_SECS
                : max(self::INPLACE_MIN_SECS, $rows / $this->inplaceRowsPerSec);
        }

        return new SchemaChange(
            $change->table,
            $change->kind,
            $change->name,
            $change->algorithm,
            $change->rowScaled,
            $change->rebuildsTable,
            $change->blocksWrites,
            $change->note,
            $rows,
            $seconds
        );
    }

    /**
     * Duration of all changes to ONE table.
     *
     * Magento folds every change to a table into a single ALTER, so however
     * many of them force a rebuild, the table is rebuilt once — at the pace of
     * the slowest. Index builds each add their own pass.
     *
     * @param SchemaChange[] $changes
     * @return float
     */
    public function tableSeconds(array $changes): float
    {
        $rebuild = 0.0;
        $rest = 0.0;
        foreach ($changes as $change) {
            if ($change->rebuildsTable) {
                $rebuild = max($rebuild, $change->estSeconds);
            } else {
                $rest += $change->estSeconds;
            }
        }

        return $rebuild + $rest;
    }
}
