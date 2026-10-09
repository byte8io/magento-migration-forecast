<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\MigrationForecast\Console\Command;

use Byte8\MigrationForecast\Console\ForecastOptions;
use Byte8\MigrationForecast\Console\ForecastRenderer;
use Byte8\MigrationForecast\Model\Forecaster;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Forecast what setup:upgrade will do to the database, without running it.
 */
class ForecastCommand extends Command
{
    // Not under "setup:upgrade:" — a command there makes the everyday "s:up" shortcut ambiguous.
    private const COMMAND_NAME = 'setup:db:forecast';
    private const OPTION_FORMAT = 'format';
    private const FORMAT_TEXT = 'text';
    private const FORMAT_JSON = 'json';

    /**
     * @param Forecaster $forecaster
     * @param ForecastOptions $options
     * @param ForecastRenderer $renderer
     * @param string|null $name
     */
    public function __construct(
        private readonly Forecaster $forecaster,
        private readonly ForecastOptions $options,
        private readonly ForecastRenderer $renderer,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription(
                'Forecast what setup:upgrade will do to the database: pending DDL, lock impact and duration.'
            )
            ->setDefinition(array_merge(
                [
                    new InputOption(
                        self::OPTION_FORMAT,
                        null,
                        InputOption::VALUE_REQUIRED,
                        'Output format: text or json.',
                        self::FORMAT_TEXT
                    ),
                ],
                $this->options->getDefinition('Exit with a failure code')
            ));
        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string) $input->getOption(self::OPTION_FORMAT);
        if (!in_array($format, [self::FORMAT_TEXT, self::FORMAT_JSON], true)) {
            $output->writeln('<error>Unknown format: use text or json.</error>');
            return Cli::RETURN_FAILURE;
        }

        try {
            $forecast = $this->options->forecast($this->forecaster, $input);
        } catch (\Throwable $e) {
            $output->writeln('<error>Could not build the forecast: ' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }

        $violations = $this->options->getViolations($forecast, $input);
        if ($format === self::FORMAT_JSON) {
            $output->writeln(json_encode($forecast->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderer->render($forecast, $output);
            foreach ($violations as $violation) {
                $output->writeln('<error>' . $violation . '</error>');
            }
        }

        return $violations ? Cli::RETURN_FAILURE : Cli::RETURN_SUCCESS;
    }
}
