<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\MigrationForecast\Test\Unit\Model;

use Byte8\MigrationForecast\Model\Classifier;
use Byte8\MigrationForecast\Model\CostModel;
use Byte8\MigrationForecast\Model\Data\SchemaChange;
use PHPUnit\Framework\TestCase;

class CostModelTest extends TestCase
{
    private const ROWS = 2100000;

    private Classifier $classifier;

    private CostModel $costModel;

    protected function setUp(): void
    {
        $this->classifier = new Classifier();
        $this->costModel = new CostModel();
    }

    public function testInstantChangeIsFreeRegardlessOfSize(): void
    {
        $change = $this->cost(SchemaChange::KIND_ADD_COLUMN, [], self::ROWS);

        self::assertLessThan(1.0, $change->estSeconds);
        self::assertSame(self::ROWS, $change->rows);
    }

    public function testIndexBuildIsCostedByRows(): void
    {
        // 2.1M rows / 100k rows per second.
        self::assertEqualsWithDelta(21.0, $this->cost(SchemaChange::KIND_ADD_INDEX, [], self::ROWS)->estSeconds, 0.01);
    }

    public function testTableCopyIsCostedByRows(): void
    {
        // 2.1M rows / 50k rows per second.
        self::assertEqualsWithDelta(
            42.0,
            $this->cost(SchemaChange::KIND_RECREATE_TABLE, [], self::ROWS)->estSeconds,
            0.01
        );
    }

    public function testSmallTablesHitTheFloor(): void
    {
        self::assertSame(1.0, $this->cost(SchemaChange::KIND_ADD_INDEX, [], 10)->estSeconds);
        self::assertSame(2.0, $this->cost(SchemaChange::KIND_RECREATE_TABLE, [], 10)->estSeconds);
    }

    public function testUnknownRowsFallBackToClassDefaults(): void
    {
        self::assertSame(5.0, $this->cost(SchemaChange::KIND_ADD_INDEX, [], null)->estSeconds);
        self::assertSame(15.0, $this->cost(SchemaChange::KIND_RECREATE_TABLE, [], null)->estSeconds);
    }

    public function testRatesAreOverridablePerHost(): void
    {
        $fast = $this->costModel->withRates(1000000.0, 500000.0);
        $index = $fast->cost($this->classifier->classify('t', SchemaChange::KIND_ADD_INDEX, 'i'), self::ROWS);

        self::assertEqualsWithDelta(2.1, $index->estSeconds, 0.01);
        // The original instance is untouched.
        self::assertEqualsWithDelta(21.0, $this->cost(SchemaChange::KIND_ADD_INDEX, [], self::ROWS)->estSeconds, 0.01);
    }

    public function testTableIsRebuiltOnceHoweverManyChangesForceIt(): void
    {
        $changes = [
            $this->cost(SchemaChange::KIND_RECREATE_TABLE, [], self::ROWS), // copy rebuild, 42s
            $this->cost(SchemaChange::KIND_DROP_COLUMN, [], self::ROWS),    // inplace rebuild, 21s
            $this->cost(SchemaChange::KIND_ADD_INDEX, [], self::ROWS),      // index build, 21s
            $this->cost(SchemaChange::KIND_ADD_COLUMN, [], self::ROWS),     // instant, 0.1s
        ];

        // One rebuild at the slowest pace (42) + the index build (21) + the instant change (0.1).
        self::assertEqualsWithDelta(63.1, $this->costModel->tableSeconds($changes), 0.01);
    }

    private function cost(string $kind, array $context, ?int $rows): SchemaChange
    {
        return $this->costModel->cost($this->classifier->classify('acme_widget', $kind, 'thing', $context), $rows);
    }
}
