<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\MigrationForecast\Model;

use Byte8\MigrationForecast\Model\Data\Forecast;
use Byte8\MigrationForecast\Model\Data\SchemaChange;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Config\ConfigOptionsListConstants;
use Magento\Framework\Module\FullModuleList;

/**
 * Forecasts what the next setup:upgrade will do to the database.
 *
 * Advisory only: nothing here writes to the database or the filesystem.
 */
class Forecaster
{
    /**
     * @param SchemaChangeCollector $changeCollector
     * @param TableStatsProvider $tableStatsProvider
     * @param PendingPatchProvider $pendingPatchProvider
     * @param Classifier $classifier
     * @param CostModel $costModel
     * @param FullModuleList $fullModuleList
     * @param DeploymentConfig $deploymentConfig
     * @param ComponentRegistrarInterface $componentRegistrar
     */
    public function __construct(
        private readonly SchemaChangeCollector $changeCollector,
        private readonly TableStatsProvider $tableStatsProvider,
        private readonly PendingPatchProvider $pendingPatchProvider,
        private readonly Classifier $classifier,
        private readonly CostModel $costModel,
        private readonly FullModuleList $fullModuleList,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly ComponentRegistrarInterface $componentRegistrar
    ) {
    }

    /**
     * @param float|null $inplaceRowsPerSec Override the in-place throughput for this host.
     * @param float|null $copyRowsPerSec Override the table-copy throughput for this host.
     * @return Forecast
     */
    public function forecast(?float $inplaceRowsPerSec = null, ?float $copyRowsPerSec = null): Forecast
    {
        $costModel = $this->costModel->withRates(
            $inplaceRowsPerSec ?? CostModel::DEFAULT_INPLACE_ROWS_PER_SEC,
            $copyRowsPerSec ?? CostModel::DEFAULT_COPY_ROWS_PER_SEC
        );

        $pending = $this->changeCollector->collect();
        $stats = $this->tableStatsProvider->getStats($this->groupExistingTables($pending));

        $changes = [];
        $byTable = [];
        $anyRowsUnknown = false;
        foreach ($pending as $item) {
            $tableStats = $stats[$item['table']] ?? null;
            $context = $item['context'];
            $context['bytes_per_char'] = $tableStats ? $this->bytesPerChar($tableStats['collation']) : null;

            $change = $costModel->cost(
                $this->classifier->classify($item['table'], $item['kind'], $item['name'], $context),
                $tableStats['rows'] ?? null
            );
            $anyRowsUnknown = $anyRowsUnknown || ($change->rowScaled && $change->rows === null);
            $changes[] = $change;
            $byTable[$change->table][] = $change;
        }

        $tables = [];
        $ddlSeconds = 0.0;
        $blockingSeconds = 0.0;
        foreach ($byTable as $table => $tableChanges) {
            $seconds = $costModel->tableSeconds($tableChanges);
            $blocks = (bool) array_filter(
                $tableChanges,
                static fn (SchemaChange $change): bool => $change->blocksWrites
            );
            $ddlSeconds += $seconds;
            $blockingSeconds += $blocks ? $seconds : 0.0;
            $tables[$table] = [
                'rows' => $stats[$table]['rows'] ?? null,
                'bytes' => $stats[$table]['bytes'] ?? null,
                'est_secs' => round($seconds, 1),
                'blocks_writes' => $blocks,
            ];
        }

        $dataPatches = $this->pendingPatchProvider->getDataPatches();
        $schemaPatches = $this->pendingPatchProvider->getSchemaPatches();
        $legacyScripts = $this->pendingPatchProvider->getLegacyScripts();
        $warnings = $this->getNewModuleWarnings();

        if ($dataPatches || $schemaPatches || $legacyScripts || $warnings) {
            $confidence = Forecast::CONFIDENCE_LOW;
        } elseif ($anyRowsUnknown) {
            $confidence = Forecast::CONFIDENCE_MEDIUM;
        } else {
            $confidence = Forecast::CONFIDENCE_HIGH;
        }

        return new Forecast(
            $changes,
            $tables,
            $dataPatches,
            $schemaPatches,
            $legacyScripts,
            $warnings,
            (int) ceil(CostModel::BOOTSTRAP_OVERHEAD_SECS + $ddlSeconds),
            (int) ceil($blockingSeconds),
            $confidence
        );
    }

    /**
     * Tables that already exist, grouped by the connection they live on.
     *
     * @param array<int, array{table: string, resource: string, kind: string}> $pending
     * @return array<string, string[]>
     */
    private function groupExistingTables(array $pending): array
    {
        $grouped = [];
        foreach ($pending as $item) {
            if ($item['kind'] !== SchemaChange::KIND_NEW_TABLE) {
                $grouped[$item['resource']][] = $item['table'];
            }
        }

        return $grouped;
    }

    /**
     * Widest character of the table's charset, which decides how many length
     * bytes a VARCHAR needs.
     *
     * @param string $collation
     * @return int|null
     */
    private function bytesPerChar(string $collation): ?int
    {
        $charset = strtolower(strtok($collation, '_') ?: '');

        return match ($charset) {
            'utf8mb4', 'utf16', 'utf16le', 'utf32' => 4,
            'utf8', 'utf8mb3' => 3,
            'ucs2' => 2,
            'latin1', 'ascii', 'binary' => 1,
            default => null,
        };
    }

    /**
     * setup:upgrade enables modules that are on disk but missing from
     * config.php; their schema is invisible to the declarative diff until then.
     *
     * @return string[]
     */
    private function getNewModuleWarnings(): array
    {
        $known = (array) $this->deploymentConfig->get(ConfigOptionsListConstants::KEY_MODULES, []);
        $warnings = [];
        foreach ($this->fullModuleList->getNames() as $moduleName) {
            if (array_key_exists($moduleName, $known)) {
                continue;
            }
            $path = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, $moduleName);
            $warnings[] = sprintf(
                '%s is not in app/etc/config.php yet: setup:upgrade will enable it, %s.',
                $moduleName,
                $path && is_file($path . '/etc/db_schema.xml')
                    ? 'and its db_schema.xml is NOT included in this forecast'
                    : 'but it declares no schema'
            );
        }

        return $warnings;
    }
}
