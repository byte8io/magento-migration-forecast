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
use Byte8\MigrationForecast\Model\UpgradeRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Forecast first, then run the real setup:upgrade — only if the forecast is acceptable.
 *
 * At a terminal it asks before proceeding. Unattended (CI, deploy scripts) it
 * never prompts: the limits passed as options decide.
 */
class GuardedUpgradeCommand extends Command
{
    /**
     * The forecast was over a limit, or the operator said no. setup:upgrade did
     * not start and nothing was changed — distinct from an upgrade that failed.
     */
    public const RETURN_NOT_RUN = 2;

    // Not under "setup:upgrade" — see ForecastCommand.
    private const COMMAND_NAME = 'setup:db:guarded-upgrade';
    private const OPTION_YES = 'yes';
    private const OPTION_KEEP_GENERATED = 'keep-generated';

    /**
     * @param Forecaster $forecaster
     * @param ForecastOptions $options
     * @param ForecastRenderer $renderer
     * @param UpgradeRunner $upgradeRunner
     * @param string|null $name
     */
    public function __construct(
        private readonly Forecaster $forecaster,
        private readonly ForecastOptions $options,
        private readonly ForecastRenderer $renderer,
        private readonly UpgradeRunner $upgradeRunner,
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
                'Forecast, then run setup:upgrade if the forecast is within limits (asks first at a terminal).'
            )
            ->setDefinition(array_merge(
                $this->options->getDefinition('Do not run setup:upgrade'),
                [
                    new InputOption(
                        self::OPTION_YES,
                        'y',
                        InputOption::VALUE_NONE,
                        'Do not ask for confirmation at a terminal.'
                    ),
                    new InputOption(
                        self::OPTION_KEEP_GENERATED,
                        null,
                        InputOption::VALUE_NONE,
                        'Passed through to setup:upgrade: prevents generated files from being deleted.'
                    ),
                ]
            ));
        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $forecast = $this->options->forecast($this->forecaster, $input);
        } catch (\Throwable $e) {
            // Without a forecast there is nothing to guard with; refusing is the safe answer.
            $output->writeln('<error>Could not build the forecast: ' . $e->getMessage() . '</error>');
            $output->writeln('<error>setup:upgrade was not run.</error>');
            return self::RETURN_NOT_RUN;
        }

        $this->renderer->render($forecast, $output);

        $violations = $this->options->getViolations($forecast, $input);
        if ($violations) {
            foreach ($violations as $violation) {
                $output->writeln('<error>' . $violation . '</error>');
            }
            $output->writeln('<error>setup:upgrade was not run.</error>');
            return self::RETURN_NOT_RUN;
        }

        $attended = $this->isAttended($input);
        if (!$forecast->isUpToDate() && $attended && !$input->getOption(self::OPTION_YES)) {
            $question = new ConfirmationQuestion('Run setup:upgrade now? [y/N] ', false);
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('<comment>setup:upgrade was not run.</comment>');
                return self::RETURN_NOT_RUN;
            }
        }

        $arguments = [];
        if ($input->getOption(self::OPTION_KEEP_GENERATED)) {
            $arguments[] = '--' . self::OPTION_KEEP_GENERATED;
        }
        if (!$attended) {
            $arguments[] = '--no-interaction';
        }

        $output->writeln('');
        $output->writeln('<info>Running setup:upgrade…</info>');

        return $this->upgradeRunner->run(
            $arguments,
            static function (string $buffer) use ($output): void {
                $output->write($buffer, false, OutputInterface::OUTPUT_RAW);
            }
        );
    }

    /**
     * Whether a person is there to answer a question.
     *
     * Symfony reports "interactive" unless --no-interaction is passed, even with
     * no terminal attached; a prompt there would be answered by end-of-file.
     *
     * @param InputInterface $input
     * @return bool
     */
    private function isAttended(InputInterface $input): bool
    {
        return $input->isInteractive() && defined('STDIN') && stream_isatty(STDIN);
    }
}
