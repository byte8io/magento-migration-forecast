<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\MigrationForecast\Model;

use Byte8\MigrationForecast\Model\Data\Forecast;

/**
 * Decides whether a forecast is within the limits a pipeline was given.
 */
class Gate
{
    /**
     * @param Forecast $forecast
     * @param int|null $maxSeconds Limit on the predicted DDL duration (null = no limit).
     * @param int|null $maxBlockingSeconds Limit on the write-blocking part (null = no limit).
     * @param bool $failOnUncosted Whether pending patches or setup scripts are a violation.
     * @return string[] One message per broken limit; empty when the forecast passes.
     */
    public function getViolations(
        Forecast $forecast,
        ?int $maxSeconds,
        ?int $maxBlockingSeconds,
        bool $failOnUncosted
    ): array {
        $violations = [];
        if ($maxSeconds !== null && $forecast->predictedSeconds > $maxSeconds) {
            $violations[] = sprintf(
                'Forecast duration ~%ds exceeds the limit of %ds.',
                $forecast->predictedSeconds,
                $maxSeconds
            );
        }
        if ($maxBlockingSeconds !== null && $forecast->blockingSeconds > $maxBlockingSeconds) {
            $violations[] = sprintf(
                'Forecast write-blocking time ~%ds exceeds the limit of %ds.',
                $forecast->blockingSeconds,
                $maxBlockingSeconds
            );
        }
        if ($failOnUncosted && $forecast->getUncostedCount()) {
            $violations[] = sprintf('Pending and not costed: %s.', $forecast->getUncostedDescription());
        }

        return $violations;
    }
}
