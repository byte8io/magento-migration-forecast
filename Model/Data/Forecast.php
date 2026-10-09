<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\MigrationForecast\Model\Data;

/**
 * What the next setup:upgrade is expected to do to the database.
 */
class Forecast
{
    public const CONFIDENCE_HIGH = 'high';
    public const CONFIDENCE_MEDIUM = 'medium';
    public const CONFIDENCE_LOW = 'low';

    /**
     * @param SchemaChange[] $schemaChanges
     * @param array<string, array{rows: int|null, bytes: int|null, est_secs: float, blocks_writes: bool}> $tables
     * @param string[] $dataPatches Pending data patch classes (cost unknown).
     * @param string[] $schemaPatches Pending schema patch classes (cost unknown).
     * @param string[] $legacyScripts Modules whose Install/Upgrade scripts will run.
     * @param string[] $warnings
     * @param int $predictedSeconds Bootstrap overhead plus all DDL. Patches and scripts are NOT included.
     * @param int $blockingSeconds Portion of the DDL during which some table rejects writes.
     * @param string $confidence How far the DDL figure can be trusted (CONFIDENCE_*). Pending patches
     *                           do not lower it: they are reported separately, never folded in.
     */
    public function __construct(
        public readonly array $schemaChanges,
        public readonly array $tables,
        public readonly array $dataPatches,
        public readonly array $schemaPatches,
        public readonly array $legacyScripts,
        public readonly array $warnings,
        public readonly int $predictedSeconds,
        public readonly int $blockingSeconds,
        public readonly string $confidence
    ) {
    }

    /**
     * @return bool
     */
    public function isUpToDate(): bool
    {
        return !$this->schemaChanges && !$this->getUncostedCount();
    }

    /**
     * Patches and scripts that run arbitrary PHP and so cannot be costed.
     *
     * @return int
     */
    public function getUncostedCount(): int
    {
        return count($this->dataPatches) + count($this->schemaPatches) + count($this->legacyScripts);
    }

    /**
     * One-line summary, suitable for a deploy log.
     *
     * @return string
     */
    public function getSummary(): string
    {
        $byImpact = [];
        foreach ($this->schemaChanges as $change) {
            $byImpact[$change->getImpact()] = ($byImpact[$change->getImpact()] ?? 0) + 1;
        }
        ksort($byImpact);
        $classes = [];
        foreach ($byImpact as $impact => $count) {
            $classes[] = $count . ' ' . $impact;
        }

        $summary = sprintf(
            'DDL forecast: %s; ~%ds, ~%ds write-blocking (confidence: %s).',
            $classes ? implode(', ', $classes) : 'no structural changes',
            $this->predictedSeconds,
            $this->blockingSeconds,
            $this->confidence
        );
        $uncosted = $this->getUncostedCount();
        if ($uncosted) {
            $summary .= sprintf(' Plus %d patch(es)/script(s) not costed.', $uncosted);
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'up_to_date' => $this->isUpToDate(),
            'predicted_secs' => $this->predictedSeconds,
            'blocking_secs' => $this->blockingSeconds,
            'confidence' => $this->confidence,
            'uncosted' => $this->getUncostedCount(),
            'summary' => $this->getSummary(),
            'schema_changes' => array_map(
                static fn (SchemaChange $change): array => $change->toArray(),
                $this->schemaChanges
            ),
            'tables' => $this->tables,
            'data_patches' => $this->dataPatches,
            'schema_patches' => $this->schemaPatches,
            'legacy_scripts' => $this->legacyScripts,
            'warnings' => $this->warnings,
        ];
    }
}
