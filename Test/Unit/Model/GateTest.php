<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\MigrationForecast\Test\Unit\Model;

use Byte8\MigrationForecast\Model\Data\Forecast;
use Byte8\MigrationForecast\Model\Gate;
use PHPUnit\Framework\TestCase;

class GateTest extends TestCase
{
    private Gate $gate;

    protected function setUp(): void
    {
        $this->gate = new Gate();
    }

    public function testNoLimitsMeansNoViolations(): void
    {
        self::assertSame([], $this->gate->getViolations($this->forecast(600, 500, 3), null, null, false));
    }

    public function testAForecastExactlyOnTheLimitPasses(): void
    {
        self::assertSame([], $this->gate->getViolations($this->forecast(30, 10, 0), 30, 10, true));
    }

    public function testEachBrokenLimitIsReported(): void
    {
        $violations = $this->gate->getViolations($this->forecast(31, 11, 2), 30, 10, true);

        self::assertCount(3, $violations);
        self::assertStringContainsString('~31s exceeds the limit of 30s', $violations[0]);
        self::assertStringContainsString('write-blocking time ~11s exceeds the limit of 10s', $violations[1]);
        self::assertStringContainsString('Pending and not costed: 2 data patches.', $violations[2]);
    }

    public function testAZeroLimitIsALimitNotAnAbsentOne(): void
    {
        self::assertCount(1, $this->gate->getViolations($this->forecast(3, 0, 0), 0, null, false));
    }

    public function testPendingPatchesOnlyCountWhenAsked(): void
    {
        self::assertSame([], $this->gate->getViolations($this->forecast(3, 0, 2), null, null, false));
        self::assertCount(1, $this->gate->getViolations($this->forecast(3, 0, 2), null, null, true));
    }

    private function forecast(int $seconds, int $blockingSeconds, int $patches): Forecast
    {
        return new Forecast(
            [],
            [],
            array_fill(0, $patches, 'Acme\\Widget\\Setup\\Patch\\Data\\Seed'),
            [],
            [],
            [],
            $seconds,
            $blockingSeconds,
            Forecast::CONFIDENCE_HIGH
        );
    }
}
