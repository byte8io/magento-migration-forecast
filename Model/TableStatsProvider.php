<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\MigrationForecast\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Approximate table sizes from information_schema.
 *
 * Best-effort by design: any failure yields no stats and the cost model falls
 * back to its per-class defaults.
 */
class TableStatsProvider
{
    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @param array<string, string[]> $tablesByResource resource name => table names
     * @return array<string, array{rows: int, bytes: int, collation: string}> keyed by table name
     */
    public function getStats(array $tablesByResource): array
    {
        $stats = [];
        foreach ($tablesByResource as $resource => $tables) {
            if (!$tables) {
                continue;
            }
            try {
                $connection = $this->resourceConnection->getConnection(
                    $resource ?: ResourceConnection::DEFAULT_CONNECTION
                );
                $rows = $connection->fetchAll(
                    'SELECT TABLE_NAME AS name, TABLE_ROWS AS row_count,'
                    . ' DATA_LENGTH + INDEX_LENGTH AS bytes, TABLE_COLLATION AS collation'
                    . ' FROM information_schema.TABLES'
                    . ' WHERE TABLE_SCHEMA = DATABASE() AND '
                    . $connection->quoteInto('TABLE_NAME IN (?)', array_values(array_unique($tables)))
                );
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($rows as $row) {
                $stats[(string) $row['name']] = [
                    'rows' => (int) $row['row_count'],
                    'bytes' => (int) $row['bytes'],
                    'collation' => (string) $row['collation'],
                ];
            }
        }

        return $stats;
    }
}
