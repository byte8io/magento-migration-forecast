<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\MigrationForecast\Test\Unit\Model;

use Byte8\MigrationForecast\Model\Classifier;
use Byte8\MigrationForecast\Model\Data\Forecast;
use Byte8\MigrationForecast\Model\Data\SchemaChange;
use PHPUnit\Framework\TestCase;

class ForecastTest extends TestCase
{
    public function testPatchesAreReportedBesideTheDdlFigureNotInsideIt(): void
    {
        $forecast = $this->forecast(
            [(new Classifier())->classify('sales_order', SchemaChange::KIND_ADD_COLUMN, 'note')],
            ['Acme\\Widget\\Setup\\Patch\\Data\\SeedWidgets']
        );

        self::assertSame(
            'DDL forecast: 1 instant; ~4s, ~0s write-blocking (confidence: high).'
            . ' Plus 1 data patch not costed.',
            $forecast->getSummary()
        );
        self::assertSame('high', $forecast->toArray()['confidence']);
        self::assertSame(1, $forecast->toArray()['uncosted']);
        self::assertFalse($forecast->isUpToDate());
    }

    public function testSummaryOmitsThePatchClauseWhenNothingIsPending(): void
    {
        $forecast = $this->forecast([], []);

        self::assertSame(
            'DDL forecast: no structural changes, no DDL time (confidence: high).',
            $forecast->getSummary()
        );
        self::assertSame(0, $forecast->toArray()['uncosted']);
        self::assertTrue($forecast->isUpToDate());
    }

    public function testAPatchOnlyReleaseQuotesNoSeconds(): void
    {
        $forecast = $this->forecast([], ['Acme\\Widget\\Setup\\Patch\\Data\\SeedWidgets']);

        self::assertSame(
            'DDL forecast: no structural changes, no DDL time (confidence: high). Plus 1 data patch not costed.',
            $forecast->getSummary()
        );
    }

    public function testUncostedWorkIsNamedByKindWithPlurals(): void
    {
        $forecast = new Forecast(
            [],
            [],
            ['A', 'B'],
            ['C'],
            ['Acme_Widget'],
            [],
            3,
            0,
            Forecast::CONFIDENCE_HIGH
        );

        self::assertSame(
            '2 data patches, 1 schema patch and 1 module with legacy setup scripts',
            $forecast->getUncostedDescription()
        );
        self::assertSame('', $this->forecast([], [])->getUncostedDescription());
    }

    /**
     * @param SchemaChange[] $changes
     * @param string[] $dataPatches
     */
    private function forecast(array $changes, array $dataPatches): Forecast
    {
        return new Forecast($changes, [], $dataPatches, [], [], [], 4, 0, Forecast::CONFIDENCE_HIGH);
    }
}
