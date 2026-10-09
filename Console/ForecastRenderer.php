<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\MigrationForecast\Console;

use Byte8\MigrationForecast\Model\Data\Forecast;
use Byte8\MigrationForecast\Model\Data\SchemaChange;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints a forecast for a human.
 */
class ForecastRenderer
{
    /**
     * @param Forecast $forecast
     * @param OutputInterface $output
     * @return void
     */
    public function render(Forecast $forecast, OutputInterface $output): void
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

        $this->renderList($output, 'Pending data patches (not costed)', $forecast->dataPatches);
        $this->renderList($output, 'Pending schema patches (not costed)', $forecast->schemaPatches);
        $this->renderList($output, 'Legacy setup scripts to run (not costed)', $forecast->legacyScripts);
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
