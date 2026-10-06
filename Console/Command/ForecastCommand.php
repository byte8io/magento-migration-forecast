<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\MigrationForecast\Console\Command;

use Byte8\MigrationForecast\Model\CostModel;
use Byte8\MigrationForecast\Model\Data\Forecast;
use Byte8\MigrationForecast\Model\Data\SchemaChange;
use Byte8\MigrationForecast\Model\Forecaster;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Forecast what setup:upgrade will do to the database, without running it.
 */
class ForecastCommand extends Command
{
    private const COMMAND_NAME = 'setup:upgrade:forecast';
    private const OPTION_FORMAT = 'format';
    private const OPTION_MAX_SECONDS = 'max-seconds';
    private const OPTION_MAX_BLOCKING_SECONDS = 'max-blocking-seconds';
    private const OPTION_INPLACE_RATE = 'inplace-rate';
    private const OPTION_COPY_RATE = 'copy-rate';
    private const FORMAT_TEXT = 'text';
    private const FORMAT_JSON = 'json';

    /**
     * @param Forecaster $forecaster
     * @param string|null $name
     */
    public function __construct(
        private readonly Forecaster $forecaster,
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
            ->setDefinition([
                new InputOption(
                    self::OPTION_FORMAT,
                    null,
                    InputOption::VALUE_REQUIRED,
                    'Output format: text or json.',
                    self::FORMAT_TEXT
                ),
                new InputOption(
                    self::OPTION_MAX_SECONDS,
                    null,
                    InputOption::VALUE_REQUIRED,
                    'Exit with a failure code when the predicted duration exceeds this many seconds.'
                ),
                new InputOption(
                    self::OPTION_MAX_BLOCKING_SECONDS,
                    null,
                    InputOption::VALUE_REQUIRED,
                    'Exit with a failure code when the predicted write-blocking time exceeds this many seconds.'
                ),
                new InputOption(
                    self::OPTION_INPLACE_RATE,
                    null,
                    InputOption::VALUE_REQUIRED,
                    'Rows per second this host manages for in-place DDL (index builds, online rebuilds).',
                    (string) (int) CostModel::DEFAULT_INPLACE_ROWS_PER_SEC
                ),
                new InputOption(
                    self::OPTION_COPY_RATE,
                    null,
                    InputOption::VALUE_REQUIRED,
                    'Rows per second this host manages for table-copy DDL.',
                    (string) (int) CostModel::DEFAULT_COPY_ROWS_PER_SEC
                ),
            ]);
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
            $forecast = $this->forecaster->forecast(
                (float) $input->getOption(self::OPTION_INPLACE_RATE),
                (float) $input->getOption(self::OPTION_COPY_RATE)
            );
        } catch (\Throwable $e) {
            $output->writeln('<error>Could not build the forecast: ' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }

        if ($format === self::FORMAT_JSON) {
            $output->writeln(json_encode($forecast->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderText($forecast, $output);
        }

        return $this->exceedsThreshold($forecast, $input, $output, $format === self::FORMAT_TEXT)
            ? Cli::RETURN_FAILURE
            : Cli::RETURN_SUCCESS;
    }

    /**
     * @param Forecast $forecast
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param bool $explain
     * @return bool
     */
    private function exceedsThreshold(
        Forecast $forecast,
        InputInterface $input,
        OutputInterface $output,
        bool $explain
    ): bool {
        $limits = [
            self::OPTION_MAX_SECONDS => ['predicted duration', $forecast->predictedSeconds],
            self::OPTION_MAX_BLOCKING_SECONDS => ['write-blocking time', $forecast->blockingSeconds],
        ];
        $exceeded = false;
        foreach ($limits as $option => [$label, $actual]) {
            $limit = $input->getOption($option);
            if ($limit === null || $actual <= (int) $limit) {
                continue;
            }
            $exceeded = true;
            if ($explain) {
                $output->writeln(
                    sprintf('<error>Forecast %s ~%ds exceeds --%s=%d.</error>', $label, $actual, $option, $limit)
                );
            }
        }

        return $exceeded;
    }

    /**
     * @param Forecast $forecast
     * @param OutputInterface $output
     * @return void
     */
    private function renderText(Forecast $forecast, OutputInterface $output): void
    {
        if ($forecast->schemaChanges) {
            $table = new Table($output);
            $table->setHeaders(['Table', 'Change', 'Name', 'Algorithm', 'Impact', 'Rows', 'Est.']);
            foreach ($forecast->schemaChanges as $change) {
                $table->addRow([
                    $change->table,
                    str_replace('_', ' ', $change->kind),
                    $change->name,
                    strtoupper($change->algorithm),
                    $change->blocksWrites ? '<error>blocking</error>' : $change->getImpact(),
                    $this->formatRows($change),
                    $change->rowScaled ? '~' . $this->formatSeconds($change->estSeconds) : '-',
                ]);
            }
            $table->render();

            $notes = [];
            foreach ($forecast->schemaChanges as $change) {
                if ($change->note !== '' && ($change->rowScaled || $change->blocksWrites)) {
                    $notes[] = sprintf('%s %s: %s', $change->table, $change->name, $change->note);
                }
            }
            foreach (array_unique($notes) as $note) {
                $output->writeln('  <comment>' . $note . '</comment>');
            }
        } else {
            $output->writeln('<info>Declarative schema is up to date: no DDL pending.</info>');
        }

        $this->renderList($output, 'Pending data patches (cost unknown)', $forecast->dataPatches);
        $this->renderList($output, 'Pending schema patches (cost unknown)', $forecast->schemaPatches);
        $this->renderList($output, 'Legacy setup scripts to run (cost unknown)', $forecast->legacyScripts);
        $this->renderList($output, 'Warnings', $forecast->warnings);

        $output->writeln('');
        $output->writeln('<info>' . $forecast->getSummary() . '</info>');
    }

    /**
     * @param OutputInterface $output
     * @param string $title
     * @param string[] $items
     * @return void
     */
    private function renderList(OutputInterface $output, string $title, array $items): void
    {
        if (!$items) {
            return;
        }
        $output->writeln('');
        $output->writeln(sprintf('<comment>%s: %d</comment>', $title, count($items)));
        foreach ($items as $item) {
            $output->writeln('  - ' . $item);
        }
    }

    /**
     * @param SchemaChange $change
     * @return string
     */
    private function formatRows(SchemaChange $change): string
    {
        if ($change->rows !== null) {
            return number_format($change->rows);
        }

        // A table that does not exist yet has no size to report; anything else is a failed lookup.
        return $change->kind === SchemaChange::KIND_NEW_TABLE ? '-' : '?';
    }

    /**
     * @param float $seconds
     * @return string
     */
    private function formatSeconds(float $seconds): string
    {
        return $seconds >= 90
            ? sprintf('%dm %02ds', intdiv((int) round($seconds), 60), ((int) round($seconds)) % 60)
            : sprintf('%ds', (int) ceil($seconds));
    }
}
