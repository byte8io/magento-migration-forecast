<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\MigrationForecast\Console;

use Byte8\MigrationForecast\Model\CostModel;
use Byte8\MigrationForecast\Model\Data\Forecast;
use Byte8\MigrationForecast\Model\Forecaster;
use Byte8\MigrationForecast\Model\Gate;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * The limits and host rates shared by every forecast command.
 */
class ForecastOptions
{
    public const MAX_SECONDS = 'max-seconds';
    public const MAX_BLOCKING_SECONDS = 'max-blocking-seconds';
    public const FAIL_ON_UNCOSTED = 'fail-on-uncosted';
    public const INPLACE_RATE = 'inplace-rate';
    public const COPY_RATE = 'copy-rate';

    /**
     * @param Gate $gate
     */
    public function __construct(
        private readonly Gate $gate
    ) {
    }

    /**
     * @param string $onViolation What the command does when a limit is broken, for the help text.
     * @return InputOption[]
     */
    public function getDefinition(string $onViolation): array
    {
        return [
            new InputOption(
                self::MAX_SECONDS,
                null,
                InputOption::VALUE_REQUIRED,
                $onViolation . ' when the predicted duration exceeds this many seconds.'
            ),
            new InputOption(
                self::MAX_BLOCKING_SECONDS,
                null,
                InputOption::VALUE_REQUIRED,
                $onViolation . ' when the predicted write-blocking time exceeds this many seconds.'
            ),
            new InputOption(
                self::FAIL_ON_UNCOSTED,
                null,
                InputOption::VALUE_NONE,
                $onViolation . ' when patches or setup scripts are pending: their cost is unknown.'
            ),
            new InputOption(
                self::INPLACE_RATE,
                null,
                InputOption::VALUE_REQUIRED,
                'Rows per second this host manages for in-place DDL (index builds, online rebuilds).',
                (string) (int) CostModel::DEFAULT_INPLACE_ROWS_PER_SEC
            ),
            new InputOption(
                self::COPY_RATE,
                null,
                InputOption::VALUE_REQUIRED,
                'Rows per second this host manages for table-copy DDL.',
                (string) (int) CostModel::DEFAULT_COPY_ROWS_PER_SEC
            ),
        ];
    }

    /**
     * @param Forecaster $forecaster
     * @param InputInterface $input
     * @return Forecast
     */
    public function forecast(Forecaster $forecaster, InputInterface $input): Forecast
    {
        return $forecaster->forecast(
            (float) $input->getOption(self::INPLACE_RATE),
            (float) $input->getOption(self::COPY_RATE)
        );
    }

    /**
     * @param Forecast $forecast
     * @param InputInterface $input
     * @return string[] One message per broken limit.
     */
    public function getViolations(Forecast $forecast, InputInterface $input): array
    {
        $maxSeconds = $input->getOption(self::MAX_SECONDS);
        $maxBlockingSeconds = $input->getOption(self::MAX_BLOCKING_SECONDS);

        return $this->gate->getViolations(
            $forecast,
            $maxSeconds === null ? null : (int) $maxSeconds,
            $maxBlockingSeconds === null ? null : (int) $maxBlockingSeconds,
            (bool) $input->getOption(self::FAIL_ON_UNCOSTED)
        );
    }
}
